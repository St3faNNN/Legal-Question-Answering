<?php

namespace App\Services\Chat;

use App\Contracts\GeneratesReplies;
use App\Models\Conversation;
use App\Services\Logging\AiLogger;

/**
 * Placeholder reply generator used until the AI microservices are connected.
 */
class PlaceholderReplyGenerator implements GeneratesReplies
{
    public function __construct(private readonly AiLogger $logger) {}

    public function reply(Conversation $conversation, string $userMessage): array
    {
        $this->logger->info('placeholder.reply', [
            'conversation_id' => $conversation->getKey(),
            'message_length' => strlen($userMessage),
        ]);

        return [
            'answer' => 'AI service not connected yet.',
            'model' => null,
            'tokens' => null,
            'latency_ms' => null,
            'metadata' => null,
        ];
    }
}
