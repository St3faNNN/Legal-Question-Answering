<?php

use App\Contracts\GeneratesReplies;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\PlaceholderReplyGenerator;

describe('AI interface binding', function (): void {
    it('resolves to PlaceholderReplyGenerator by default', function (): void {
        $generator = app(GeneratesReplies::class);

        expect($generator)->toBeInstanceOf(PlaceholderReplyGenerator::class);
    });

    it('returns a placeholder reply with null AI metadata', function (): void {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $generator = app(GeneratesReplies::class);

        $reply = $generator->reply($conversation, 'Hello');

        expect($reply)
            ->toBeArray()
            ->and($reply['answer'])->toBe('AI service not connected yet.')
            ->and($reply['model'])->toBeNull()
            ->and($reply['tokens'])->toBeNull()
            ->and($reply['latency_ms'])->toBeNull()
            ->and($reply['metadata'])->toBeNull();
    });

    it('reads the generator driver from config', function (): void {
        config(['ai.generator' => 'placeholder']);

        $generator = app(GeneratesReplies::class);

        expect($generator)->toBeInstanceOf(PlaceholderReplyGenerator::class)
            ->and(config('ai.generator'))->toBe('placeholder');
    });
});
