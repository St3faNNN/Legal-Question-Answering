<?php

namespace App\Contracts;

use App\Models\Conversation;

/**
 * Generates an assistant reply for a conversation.
 *
 * The Laravel application remains agnostic to the underlying AI
 * implementation. Phase 1 uses a placeholder driver; Phase 2 swaps in an HTTP
 * driver that orchestrates the Retrieval and Generation microservices behind
 * this same interface.
 */
interface GeneratesReplies
{
    /**
     * Generate a reply for the given user message within the conversation.
     *
     * @return array{
     *     answer: string,
     *     model: ?string,
     *     tokens: ?int,
     *     latency_ms: ?int,
     *     metadata: ?array<string, mixed>,
     * }
     */
    public function reply(Conversation $conversation, string $userMessage): array;
}
