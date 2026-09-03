<?php

namespace App\Services\Conversation;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

class ConversationService
{
    /**
     * List the user's conversations, cursor-paginated.
     *
     * @return CursorPaginator<Conversation>
     */
    public function listForUser(User $user, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('limits.per_page.conversations');

        return $user->conversations()
            ->withCount('messages')
            ->with('firstUserMessage')
            ->orderByDesc('updated_at')
            ->cursorPaginate($perPage);
    }

    /**
     * Resolve a conversation owned by the user, or throw a 404.
     */
    public function findForUser(User $user, int $conversationId): Conversation
    {
        /** @var Conversation $conversation */
        $conversation = $user->conversations()->findOrFail($conversationId);

        return $conversation;
    }

    public function createForUser(User $user, ?string $title = null): Conversation
    {
        return $user->conversations()->create([
            'title' => $title,
        ]);
    }

    public function rename(Conversation $conversation, string $title): Conversation
    {
        $conversation->update(['title' => $title]);

        return $conversation->fresh();
    }

    public function delete(Conversation $conversation): void
    {
        $conversation->delete();
    }
}
