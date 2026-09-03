<?php

namespace App\Services\Chat;

use App\Contracts\GeneratesReplies;
use App\Exceptions\GenerationBudgetExceededException;
use App\Jobs\StreamChatReply;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Conversation\ConversationService;
use App\Services\Logging\AiLogger;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ChatService
{
    public function __construct(
        private readonly GeneratesReplies $generator,
        private readonly ConversationService $conversations,
        private readonly AiLogger $logger,
        private readonly GenerationBudget $budget,
        private readonly NonLegalInputGate $gate,
    ) {}

    /**
     * Charge one pipeline answer against the shared daily budget, unless the
     * input is one the gate will answer from a canned message — that path makes
     * no retrieval and no model call, so it costs nothing and is charged nothing.
     * The gate is consulted here as well as in the reply path; it is a pure
     * string check, so asking twice is free and neither call changes behaviour.
     *
     * @throws GenerationBudgetExceededException
     */
    private function chargeForAnswer(User $user, string $content): void
    {
        if ($this->gate->shouldGate($content)) {
            return;
        }

        $this->budget->spend($user, $this->budget->pipelineCost());
    }

    /**
     * Send a user message, generate a reply, and persist both.
     *
     * @return array{conversation: Conversation, message: Message}
     */
    public function sendMessage(User $user, string $content, ?int $conversationId = null): array
    {
        // Charged before anything is persisted, so a request over the daily cap
        // is refused without leaving a user message behind that never gets a
        // reply. An escalation is charged from the same meter at three times this
        // (see config/agent.php), which is what makes it cost something relative
        // to a normal question.
        $this->chargeForAnswer($user, $content);

        // Resolving/creating the conversation and persisting the user message
        // are atomic. Generation runs afterwards so the user message survives a
        // downstream AI-service failure (see AiServiceException).
        $conversation = DB::transaction(function () use ($user, $content, $conversationId): Conversation {
            $conversation = $conversationId !== null
                ? $this->conversations->findForUser($user, $conversationId)
                : $this->conversations->createForUser($user);

            $conversation->messages()->create([
                'role' => 'user',
                'content' => $content,
            ]);

            return $conversation;
        });

        $this->logger->info('chat.message_received', [
            'conversation_id' => $conversation->id,
            'message_length' => strlen($content),
        ]);

        $reply = $this->generator->reply($conversation, $content);

        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $reply['answer'],
            'model' => $reply['model'] ?? null,
            'tokens' => $reply['tokens'] ?? null,
            'latency_ms' => $reply['latency_ms'] ?? null,
            'metadata' => $reply['metadata'] ?? null,
        ]);

        $this->logger->info('chat.reply_generated', [
            'conversation_id' => $conversation->id,
            'model' => $reply['model'] ?? 'placeholder',
            'tokens' => $reply['tokens'] ?? 0,
        ]);

        return [
            'conversation' => $conversation->fresh(),
            'message' => $assistantMessage,
        ];
    }

    /**
     * Persist the user message and dispatch a queued job that streams the reply
     * over a side channel. Returns immediately so the HTTP request is not held
     * open for generation.
     *
     * @return array{conversation: Conversation, stream_id: string}
     */
    public function startStreamedReply(User $user, string $content, ?int $conversationId = null): array
    {
        // Anchor the end-to-end timer at query receipt, before the message is
        // even persisted, and carry it into the job so it can measure through to
        // the final generated word across the web->worker boundary.
        $receivedAt = microtime(true);

        $this->chargeForAnswer($user, $content);

        $conversation = DB::transaction(function () use ($user, $content, $conversationId): Conversation {
            $conversation = $conversationId !== null
                ? $this->conversations->findForUser($user, $conversationId)
                : $this->conversations->createForUser($user);

            $conversation->messages()->create([
                'role' => 'user',
                'content' => $content,
            ]);

            return $conversation;
        });

        $streamId = (string) Str::uuid();

        // Record ownership so the SSE endpoint can authorize the subscriber.
        Redis::setex("chat:stream:{$streamId}:user", (int) config('ai.stream.key_ttl'), (string) $user->id);

        StreamChatReply::dispatch($conversation->id, $streamId, $content, $receivedAt);

        $this->logger->info('chat.stream_started', [
            'conversation_id' => $conversation->id,
            'stream_id' => $streamId,
        ]);

        return [
            'conversation' => $conversation->fresh(),
            'stream_id' => $streamId,
        ];
    }

    /**
     * List messages for a conversation, cursor-paginated.
     */
    public function listMessages(Conversation $conversation, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('limits.per_page.messages');

        return $conversation->messages()
            ->orderBy('created_at')
            ->cursorPaginate($perPage);
    }
}
