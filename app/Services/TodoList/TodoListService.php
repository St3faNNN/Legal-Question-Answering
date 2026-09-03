<?php

namespace App\Services\TodoList;

use App\Models\TodoItem;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Contracts\Pagination\CursorPaginator;

class TodoListService
{
    /**
     * List the user's to-do lists with their items, cursor-paginated.
     *
     * @return CursorPaginator<TodoList>
     */
    public function listForUser(User $user, ?int $perPage = null): CursorPaginator
    {
        $perPage ??= (int) config('limits.per_page.todo_lists');

        return $user->todoLists()
            ->with('items')
            ->orderByDesc('updated_at')
            ->cursorPaginate($perPage);
    }

    /**
     * Resolve a to-do list owned by the user, or throw a 404.
     */
    public function findForUser(User $user, int $todoListId): TodoList
    {
        /** @var TodoList $todoList */
        $todoList = $user->todoLists()->with('items')->findOrFail($todoListId);

        return $todoList;
    }

    /**
     * @param  array{title: string, due_at?: string|null}  $attributes
     */
    public function createForUser(User $user, array $attributes): TodoList
    {
        $todoList = $user->todoLists()->create([
            'title' => $attributes['title'],
            'due_at' => $attributes['due_at'] ?? null,
        ]);

        return $todoList->load('items');
    }

    /**
     * @param  array{title?: string, due_at?: string|null}  $attributes
     */
    public function update(TodoList $todoList, array $attributes): TodoList
    {
        $todoList->update($attributes);

        return $todoList->fresh('items');
    }

    public function delete(TodoList $todoList): void
    {
        $todoList->delete();
    }

    /**
     * @param  array{title: string, description?: string|null}  $attributes
     */
    public function addItem(TodoList $todoList, array $attributes): TodoItem
    {
        return $todoList->items()->create([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'is_done' => false,
        ]);
    }

    /**
     * @param  array{title?: string, description?: string|null, is_done?: bool}  $attributes
     */
    public function updateItem(TodoItem $item, array $attributes): TodoItem
    {
        $item->update($attributes);

        return $item->fresh();
    }

    public function deleteItem(TodoItem $item): void
    {
        $item->delete();
    }

    /**
     * Resolve an item belonging to a list the user owns, or throw a 404.
     */
    public function findItemForUser(User $user, int $itemId): TodoItem
    {
        /** @var TodoItem $item */
        $item = TodoItem::query()
            ->whereHas('todoList', fn ($query) => $query->where('user_id', $user->id))
            ->findOrFail($itemId);

        return $item;
    }
}
