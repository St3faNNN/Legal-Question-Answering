<?php

use App\Jobs\RunAgentEscalation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\GenerationBudget;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * Escalation is user-triggered by design — no signal reliably detects an
 * incomplete pipeline answer (see EscalationService's class docblock for the
 * three measured candidates). What IS enforced is that a run is never spent
 * where the agent has nothing to add, and that it is charged for.
 */
beforeEach(function (): void {
    config([
        'agent.url' => 'http://agent:5060',
        'agent.token' => 'test-secret',
        'agent.budget.daily_units' => 60,
        'agent.budget.pipeline_cost' => 1,
        'agent.budget.escalation_cost' => 4,
    ]);
});

/** A conversation holding one question and the pipeline answer to it. */
function conversationWithPipelineAnswer(User $user, array $metadata = []): array
{
    $conversation = Conversation::factory()->for($user)->create();

    $conversation->messages()->create([
        'role' => 'user',
        'content' => 'Кои се основите за отказ поради технолошки вишок и колкава испратнина следува?',
    ]);

    /** @var Message $answer */
    $answer = $conversation->messages()->create([
        'role' => 'assistant',
        'content' => 'Испратнината се движи од една до седум нето плати.',
        'model' => 'gemini-flash-lite-latest',
        'metadata' => array_merge([
            'refused' => false,
            'top_chunks' => [['id' => '1', 'text' => '…', 'source' => 'ZAKON-ZA-RABOTNI, чл. 96', 'score' => 0.016]],
        ], $metadata),
    ]);

    return [$conversation, $answer];
}

describe('POST /chat/escalate', function (): void {
    it('requires authentication', function (): void {
        postJson('/api/v1/chat/escalate', ['conversation_id' => 1, 'message_id' => 1])
            ->assertUnauthorized();
    });

    it('dispatches the run and returns 202 with a stream id', function (): void {
        Queue::fake();
        Redis::shouldReceive('setex')->andReturnTrue();

        $user = User::factory()->create();
        actingAs($user);
        [$conversation, $answer] = conversationWithPipelineAnswer($user);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $answer->id,
        ])
            ->assertStatus(202)
            ->assertJsonStructure(['conversation' => ['id'], 'stream_id', 'escalated_from', 'cost_units', 'remaining_units'])
            ->assertJsonPath('escalated_from', $answer->id)
            ->assertJsonPath('cost_units', 4);

        // The agent re-answers the QUESTION, not the pipeline's answer to it.
        Queue::assertPushed(
            RunAgentEscalation::class,
            fn (RunAgentEscalation $job): bool => str_contains($job->question, 'технолошки вишок')
                && $job->sourceMessageId === $answer->id,
        );
    });

    it('never escalates a conversation belonging to somebody else', function (): void {
        Queue::fake();

        $owner = User::factory()->create();
        [$conversation, $answer] = conversationWithPipelineAnswer($owner);

        actingAs(User::factory()->create());

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $answer->id,
        ])->assertNotFound();

        Queue::assertNothingPushed();
    });

    it('refuses to escalate a gated greeting, so a greeting can never reach the agent', function (): void {
        Queue::fake();

        $user = User::factory()->create();
        actingAs($user);

        $conversation = Conversation::factory()->for($user)->create();
        $conversation->messages()->create(['role' => 'user', 'content' => 'Здраво']);
        $gated = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Оваа алатка одговара само на правни прашања…',
            'metadata' => ['gated' => true, 'top_chunks' => []],
        ]);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $gated->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.reason', 'gated');

        Queue::assertNothingPushed();
    });

    it('refuses to escalate a refusal — the agent would spend a run to agree', function (): void {
        Queue::fake();

        $user = User::factory()->create();
        actingAs($user);

        $conversation = Conversation::factory()->for($user)->create();
        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Кои се чиновите во Армијата на Република Северна Македонија?',
        ]);
        $refusal = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Системот не ја опфаќа таа тема.',
            'metadata' => ['refused' => true, 'top_chunks' => []],
        ]);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $refusal->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.reason', 'refused');

        Queue::assertNothingPushed();
    });

    it('refuses to escalate an answer that is already an escalation', function (): void {
        Queue::fake();

        $user = User::factory()->create();
        actingAs($user);
        [$conversation] = conversationWithPipelineAnswer($user);

        $agentAnswer = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Продлабочен одговор (ZAKON-ZA-RABOTNI, чл. 97 ст. 1).',
            'metadata' => ['agent' => true, 'top_chunks' => []],
        ]);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $agentAnswer->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.reason', 'already_escalated');
    });

    it('is unavailable when no agent service is configured', function (): void {
        Queue::fake();
        config(['agent.url' => null, 'agent.token' => '']);

        $user = User::factory()->create();
        actingAs($user);
        [$conversation, $answer] = conversationWithPipelineAnswer($user);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $answer->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.reason', 'not_configured');
    });
});

describe('the daily generation budget', function (): void {
    it('charges an escalation several times what a normal question costs', function (): void {
        // The shipped defaults, not the values this file pins: 4,628 tokens for a
        // pipeline call against a mean 19,878 for an agent run is 4.3x.
        $defaults = require config_path('agent.php');
        config(['agent.budget' => $defaults['budget']]);

        $budget = app(GenerationBudget::class);

        expect($budget->pipelineCost())->toBe(1)
            ->and($budget->escalationCost())->toBe(4);
    });

    it('refuses the run with 429 once the daily budget cannot cover it', function (): void {
        Queue::fake();
        Redis::shouldReceive('setex')->andReturnTrue();
        config(['agent.budget.daily_units' => 6]);

        $user = User::factory()->create();
        actingAs($user);
        [$conversation, $answer] = conversationWithPipelineAnswer($user);

        // 4 of 6 units.
        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $answer->id,
        ])->assertStatus(202);

        // A second run needs 4 more and only 2 remain.
        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $answer->id,
        ])
            ->assertStatus(429)
            ->assertJsonPath('error.reason', 'budget_exhausted')
            ->assertJsonPath('error.remaining_units', 2);

        // The refused attempt gave its units back rather than consuming them.
        expect(app(GenerationBudget::class)->used($user))->toBe(4);

        Queue::assertPushed(RunAgentEscalation::class, 1);
    });

    it('charges a pipeline answer against the same meter, so an escalation is not free next to it', function (): void {
        Queue::fake();
        Redis::shouldReceive('setex')->andReturnTrue();

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', ['message' => 'Кој е рокот за жалба?'])->assertStatus(202);

        expect(app(GenerationBudget::class)->used($user))->toBe(1);
    });

    it('does not charge for input the gate answers without any model call', function (): void {
        Queue::fake();
        Redis::shouldReceive('setex')->andReturnTrue();

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', ['message' => 'Здраво'])->assertStatus(202);

        expect(app(GenerationBudget::class)->used($user))->toBe(0);
    });

    it('leaves the pipeline uncharged and unlimited when the meter is disabled', function (): void {
        Queue::fake();
        Redis::shouldReceive('setex')->andReturnTrue();
        config(['agent.budget.daily_units' => 0]);

        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/chat/stream', ['message' => 'Кој е рокот за жалба?'])->assertStatus(202);

        expect(app(GenerationBudget::class)->used($user))->toBe(0)
            ->and(app(GenerationBudget::class)->remaining($user))->toBe(PHP_INT_MAX);
    });
});

describe('GET /conversations/{id}/messages/{id}/trace', function (): void {
    it('returns the persisted trajectory of an escalated answer', function (): void {
        $user = User::factory()->create();
        actingAs($user);
        [$conversation] = conversationWithPipelineAnswer($user);

        $message = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => 'Одговор (ZAKON-ZA-RABOTNI, чл. 97 ст. 1).',
            'metadata' => ['agent' => true],
        ]);
        $message->agentRun()->create([
            'run_id' => '20260829-124011-gbwlbb',
            'question' => 'Кои се основите за отказ?',
            'outcome' => 'answered',
            'model' => 'gemini-flash-lite-latest',
            'steps_count' => 4,
            'api_calls' => 4,
            'total_tokens' => 25397,
            'duration_ms' => 7565,
            'steps' => [['index' => 0, 'calls' => [['tool' => 'search_law', 'query' => 'технолошки вишок', 'sources' => ['ZAKON-ZA-RABOTNI, чл. 97']]]]],
            'passages' => [['id' => '1', 'source' => 'ZAKON-ZA-RABOTNI, чл. 97', 'text' => '…', 'score' => 0.016]],
        ]);

        getJson("/api/v1/conversations/{$conversation->id}/messages/{$message->id}/trace")
            ->assertOk()
            ->assertJsonPath('data.run_id', '20260829-124011-gbwlbb')
            ->assertJsonPath('data.totals.total_tokens', 25397)
            ->assertJsonPath('data.steps.0.calls.0.query', 'технолошки вишок');
    });

    it('404s for a pipeline answer, which has no trajectory', function (): void {
        $user = User::factory()->create();
        actingAs($user);
        [$conversation, $answer] = conversationWithPipelineAnswer($user);

        getJson("/api/v1/conversations/{$conversation->id}/messages/{$answer->id}/trace")
            ->assertNotFound();
    });

    it('404s for a message in somebody else\'s conversation', function (): void {
        $owner = User::factory()->create();
        [$conversation, $answer] = conversationWithPipelineAnswer($owner);

        actingAs(User::factory()->create());

        getJson("/api/v1/conversations/{$conversation->id}/messages/{$answer->id}/trace")
            ->assertNotFound();
    });
});
