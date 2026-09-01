<?php

namespace App\Services\Chat;

use App\Exceptions\EscalationNotAvailableException;
use App\Exceptions\GenerationBudgetExceededException;
use App\Jobs\RunAgentEscalation;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversation\ConversationService;
use App\Services\Logging\AiLogger;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/**
 * Escalating one pipeline answer to the multi-hop agent.
 *
 * ## Why this is user-triggered and not detected
 *
 * The obvious design is for the system to notice that the pipeline's answer was
 * incomplete and offer the agent only then. Three candidate signals were measured
 * and none survives:
 *
 *   - **A completeness marker in the generation prompt.** Adding one to the shared
 *     prompt destroyed the refusal marker already in it: on the out-of-corpus army
 *     question the unmodified prompt emitted `[[NO_SOURCE]]` in 6/6 runs, and the
 *     completeness-marker variant emitted it in 0/4 — so the paired signal in
 *     {@see Concerns\ComposesGraphRagReply} stopped suppressing the provisions
 *     panel and five unrelated articles appeared above a refusal every time. The
 *     signal cost more than it bought.
 *   - **Question shape.** A conjunction / question-word heuristic over the 29
 *     lawyer-authored eval questions flags 17 of them, at 0.29 precision against
 *     the rows that genuinely need more than one article — it fires on plain
 *     single-hop questions the pipeline answers well.
 *   - **A retrieval-score threshold.** Already ruled out for the input gate: RRF
 *     scores do not separate answerable from unanswerable (see
 *     {@see NonLegalInputGate}).
 *
 * And the case that motivated escalation argues against detection directly: for
 * "основите за отказ поради технолошки вишок и колкава испратнина", чл. 97 *was*
 * retrieved (rank 6 of 10) and its text *was* in the generation context. The
 * pipeline had no gap to notice. What the user could not see was the provisions
 * panel, which at the time showed only the top 5 — no self-assessment reaches a
 * display cap. That cap is gone (the panel is now sized from ai.retrieval_top_n,
 * see ComposesGraphRagReply::panelLimit()), which fixes the provenance but not
 * the detection problem: there is still no signal for "this answer was
 * incomplete", so escalation stays user-triggered.
 *
 * So escalation is offered on every substantive answer and the user decides. The
 * only thing suppressed is escalation where the agent has nothing to add, which
 * is a fact already computed rather than a prediction: see {@see self::assertEscalatable()}.
 */
class EscalationService
{
    public function __construct(
        private readonly ConversationService $conversations,
        private readonly NonLegalInputGate $gate,
        private readonly GenerationBudget $budget,
        private readonly AiLogger $logger,
    ) {}

    /** Whether the deploy has an agent service to escalate to at all. */
    public function isConfigured(): bool
    {
        return (string) config('agent.url', '') !== '' && (string) config('agent.token', '') !== '';
    }

    /**
     * Escalate the question a given assistant message answered. Charges the
     * budget, then dispatches the run onto the same stream the pipeline uses.
     *
     * @return array{conversation: Conversation, message_id: int, stream_id: string, cost: int, remaining: int}
     *
     * @throws EscalationNotAvailableException
     * @throws GenerationBudgetExceededException
     */
    public function escalate(User $user, int $conversationId, int $messageId): array
    {
        $receivedAt = microtime(true);

        $conversation = $this->conversations->findForUser($user, $conversationId);

        /** @var Message $message */
        $message = $conversation->messages()->findOrFail($messageId);

        $this->assertEscalatable($message);

        $question = $this->questionAnsweredBy($conversation, $message);

        // The non-legal gate stays ahead of everything, exactly as it does for the
        // pipeline: a greeting must never reach a 25k-token run. The gated reply
        // itself is already refused above, but a question can be re-checked here
        // for free, and the job checks a third time.
        if ($this->gate->shouldGate($question)) {
            throw new EscalationNotAvailableException('gated', 'Ова не е правно прашање.');
        }

        $cost = $this->budget->escalationCost();
        $this->budget->spend($user, $cost);

        $streamId = (string) Str::uuid();
        Redis::setex("chat:stream:{$streamId}:user", 300, (string) $user->id);

        RunAgentEscalation::dispatch($conversation->id, $streamId, $question, $message->id, $receivedAt);

        $this->logger->info('agent.escalation_started', [
            'conversation_id' => $conversation->id,
            'stream_id' => $streamId,
            'source_message_id' => $message->id,
            'cost_units' => $cost,
        ]);

        return [
            'conversation' => $conversation,
            'message_id' => $message->id,
            'stream_id' => $streamId,
            'cost' => $cost,
            'remaining' => $this->budget->remaining($user),
        ];
    }

    /**
     * Refuse the escalations that could only waste a run. Every check reads a
     * fact already recorded on the message — none of them is a guess about
     * whether the answer was good enough.
     *
     * @throws EscalationNotAvailableException
     */
    private function assertEscalatable(Message $message): void
    {
        if (! $this->isConfigured()) {
            throw new EscalationNotAvailableException('not_configured', 'Продлабоченото истражување не е достапно.');
        }

        if ($message->role !== 'assistant') {
            throw new EscalationNotAvailableException('not_an_answer', 'Може да се продлабочи само одговор.');
        }

        $metadata = $message->metadata ?? [];

        if (($metadata['agent'] ?? false) === true) {
            throw new EscalationNotAvailableException('already_escalated', 'Овој одговор веќе е продлабочен.');
        }

        // A greeting was answered from a canned message with no retrieval; there
        // is nothing for the agent to research.
        if (($metadata['gated'] ?? false) === true) {
            throw new EscalationNotAvailableException('gated', 'Ова не е правно прашање.');
        }

        // The pipeline established the topic is outside the ten laws. The agent's
        // own prompt would reach the same refusal after several searches — it
        // would spend the tokens to agree.
        if (($metadata['refused'] ?? false) === true) {
            throw new EscalationNotAvailableException('refused', 'Темата не е опфатена со корпусот, па продлабочувањето не би помогнало.');
        }
    }

    /**
     * The user question this assistant message answered: the nearest preceding
     * user message in the conversation. The agent re-answers the question, not
     * the pipeline's answer to it.
     */
    private function questionAnsweredBy(Conversation $conversation, Message $message): string
    {
        /** @var Message|null $question */
        $question = $conversation->messages()
            ->where('role', 'user')
            ->where('id', '<', $message->id)
            ->orderByDesc('id')
            ->first();

        if ($question === null || trim($question->content) === '') {
            throw new EscalationNotAvailableException('no_question', 'Не е пронајдено прашањето на кое се однесува овој одговор.');
        }

        return $question->content;
    }
}
