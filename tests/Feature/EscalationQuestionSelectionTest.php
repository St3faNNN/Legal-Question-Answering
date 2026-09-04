<?php

use App\Jobs\RunAgentEscalation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/**
 * Regression cover for an escalation that ran against the wrong question.
 *
 * In production a procurement question was escalated and the agent received the
 * conversation's FIRST question instead — one about military service, outside
 * the corpus — so it refused without searching and the user got an answer about
 * the wrong subject entirely.
 *
 * The cause was ordering. The lookup ran through Conversation::messages(), which
 * carries orderBy('created_at') on the relation, so the orderByDesc('id') layered
 * on top became a tiebreaker: `ORDER BY created_at ASC, id DESC`, and first()
 * returned the oldest user message.
 *
 * TIMESTAMPS MATTER IN THESE TESTS. Rows created in the same second share a
 * created_at, which lets the id tiebreaker decide and hides the bug completely.
 * Every conversation here is built with distinct, increasing timestamps, exactly
 * as a real thread has.
 */

/**
 * A thread with explicit, distinct timestamps.
 *
 * @param  list<array{role: string, content: string}>  $turns
 * @return array{0: Conversation, 1: Collection<int, Message>}
 */
function threadWithDistinctTimestamps(User $user, array $turns): array
{
    $conversation = Conversation::factory()->for($user)->create();
    $at = now()->subHour();

    $messages = collect($turns)->map(function (array $turn) use ($conversation, &$at): Message {
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'role' => $turn['role'],
            'content' => $turn['content'],
            'metadata' => $turn['role'] === 'assistant' ? ['refused' => false, 'chunk_count' => 10] : null,
        ]);

        // created_at is not fillable, so it is forced after the insert. Without
        // this every row lands in the same second and the ordering bug cannot
        // reproduce.
        $at = $at->copy()->addMinute();
        $message->forceFill(['created_at' => $at])->save();

        return $message->refresh();
    });

    return [$conversation, $messages];
}

beforeEach(function (): void {
    config([
        'agent.url' => 'http://agent:8002',
        'agent.token' => 'test-secret',
        'agent.budget.daily_units' => 60,
        'agent.budget.escalation_cost' => 4,
    ]);
    Queue::fake();
    Redis::shouldReceive('setex')->andReturnTrue();
});

it('escalates the question the user escalated, not the first one in the thread', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    // The shape that broke: several exchanges, the first of which is out of
    // corpus scope. The answer being escalated is NOT the most recent one.
    [$conversation, $messages] = threadWithDistinctTimestamps($user, [
        ['role' => 'user', 'content' => 'Кажи ми нешто за воениот рок?'],
        ['role' => 'assistant', 'content' => 'Системот не ја опфаќа таа тема.'],
        ['role' => 'user', 'content' => 'Кој е вредносниот праг кај набавките од мала вредност?'],
        ['role' => 'assistant', 'content' => 'Договорниот орган може да врши набавки...'],
        ['role' => 'user', 'content' => 'Дали има дополнителни казни и рокови?'],
        ['role' => 'assistant', 'content' => 'Одговор на третото прашање.'],
    ]);

    $question = $messages[2];   // the procurement question
    $answer = $messages[3];     // its answer — deliberately not the latest turn

    postJson('/api/v1/chat/escalate', [
        'conversation_id' => $conversation->id,
        'message_id' => $answer->id,
        'question_message_id' => $question->id,
    ])->assertStatus(202);

    Queue::assertPushed(RunAgentEscalation::class, function (RunAgentEscalation $job) use ($question, $answer): bool {
        expect($job->question)->toBe('Кој е вредносниот праг кај набавките од мала вредност?')
            // The exact failure: the thread's opening question must not be picked.
            ->and($job->question)->not->toBe('Кажи ми нешто за воениот рок?')
            ->and($job->questionMessageId)->toBe($question->id)
            ->and($job->sourceMessageId)->toBe($answer->id);

        return true;
    });
});

it('honours the id it was given rather than any positional rule', function (): void {
    // The guard against "fix it with ->latest() instead". Under any positional
    // rule the run would take the NEAREST preceding user message; here the client
    // names an earlier one, and the run must use what it was told.
    $user = User::factory()->create();
    actingAs($user);

    [$conversation, $messages] = threadWithDistinctTimestamps($user, [
        ['role' => 'user', 'content' => 'Првото прашање.'],
        ['role' => 'assistant', 'content' => 'Прв одговор.'],
        ['role' => 'user', 'content' => 'Второто прашање.'],
        ['role' => 'assistant', 'content' => 'Втор одговор.'],
    ]);

    postJson('/api/v1/chat/escalate', [
        'conversation_id' => $conversation->id,
        'message_id' => $messages[3]->id,
        // NOT the nearest preceding user message.
        'question_message_id' => $messages[0]->id,
    ])->assertStatus(202);

    Queue::assertPushed(
        RunAgentEscalation::class,
        function (RunAgentEscalation $job) use ($messages): bool {
            expect($job->question)->toBe('Првото прашање.')
                ->and($job->questionMessageId)->toBe($messages[0]->id);

            return true;
        },
    );
});

it('requires the question id rather than falling back to a guess', function (): void {
    $user = User::factory()->create();
    actingAs($user);

    [$conversation, $messages] = threadWithDistinctTimestamps($user, [
        ['role' => 'user', 'content' => 'Прашање?'],
        ['role' => 'assistant', 'content' => 'Одговор.'],
    ]);

    postJson('/api/v1/chat/escalate', [
        'conversation_id' => $conversation->id,
        'message_id' => $messages[1]->id,
    ])->assertStatus(422)->assertJsonValidationErrors('question_message_id');

    Queue::assertNothingPushed();
});

describe('the pairing is validated, not trusted', function (): void {
    it('refuses a question from another conversation', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        [$conversation, $messages] = threadWithDistinctTimestamps($user, [
            ['role' => 'user', 'content' => 'Прашање?'],
            ['role' => 'assistant', 'content' => 'Одговор.'],
        ]);
        [, $elsewhere] = threadWithDistinctTimestamps(User::factory()->create(), [
            ['role' => 'user', 'content' => 'Туѓо прашање?'],
        ]);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $messages[1]->id,
            'question_message_id' => $elsewhere[0]->id,
        ])->assertStatus(422)->assertJsonPath('error.reason', 'question_not_found');

        Queue::assertNothingPushed();
    });

    it('refuses an assistant message posing as the question', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        [$conversation, $messages] = threadWithDistinctTimestamps($user, [
            ['role' => 'user', 'content' => 'Прашање?'],
            ['role' => 'assistant', 'content' => 'Прв одговор.'],
            ['role' => 'user', 'content' => 'Второ прашање?'],
            ['role' => 'assistant', 'content' => 'Втор одговор.'],
        ]);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $messages[3]->id,
            'question_message_id' => $messages[1]->id,   // an assistant turn
        ])->assertStatus(422)->assertJsonPath('error.reason', 'question_not_a_user_message');
    });

    it('refuses a question that comes after the answer', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        [$conversation, $messages] = threadWithDistinctTimestamps($user, [
            ['role' => 'user', 'content' => 'Прашање?'],
            ['role' => 'assistant', 'content' => 'Одговор.'],
            ['role' => 'user', 'content' => 'Подоцнежно прашање?'],
        ]);

        postJson('/api/v1/chat/escalate', [
            'conversation_id' => $conversation->id,
            'message_id' => $messages[1]->id,
            'question_message_id' => $messages[2]->id,
        ])->assertStatus(422)->assertJsonPath('error.reason', 'question_after_answer');
    });
});
