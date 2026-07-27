<?php

use App\Models\TodoItem;
use App\Models\TodoList;
use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

describe('todo lists', function (): void {
    it('lists the authenticated user todo lists with items', function (): void {
        $user = User::factory()->create();
        TodoList::factory()->count(3)->for($user)->create()
            ->each(fn (TodoList $list) => TodoItem::factory()->count(2)->for($list)->create());

        actingAs($user);

        $response = getJson('/api/v1/todo-lists')
            ->assertSuccessful()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'due_at', 'created_at', 'updated_at', 'items' => [['id', 'title', 'description', 'is_done']]]],
                'meta' => ['next_cursor', 'per_page'],
            ]);

        expect($response->json('data'))->toHaveCount(3);
    });

    it('only lists todo lists belonging to the authenticated user', function (): void {
        $user = User::factory()->create();
        $other = User::factory()->create();

        TodoList::factory()->count(2)->for($user)->create();
        TodoList::factory()->count(3)->for($other)->create();

        actingAs($user);

        expect(getJson('/api/v1/todo-lists')->json('data'))->toHaveCount(2);
    });

    it('creates a todo list with a title and due date', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/todo-lists', [
            'title' => 'Launch checklist',
            'due_at' => '2026-08-01T17:00:00Z',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Launch checklist')
            ->assertJsonPath('data.items', []);

        expect(TodoList::where('title', 'Launch checklist')->exists())->toBeTrue();
    });

    it('requires a title when creating a todo list', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/todo-lists', ['title' => ''])
            ->assertJsonValidationErrorFor('title');
    });

    it('rejects an invalid due date', function (): void {
        $user = User::factory()->create();
        actingAs($user);

        postJson('/api/v1/todo-lists', ['title' => 'Valid', 'due_at' => 'not-a-date'])
            ->assertJsonValidationErrorFor('due_at');
    });

    it('shows a todo list with its items', function (): void {
        $user = User::factory()->create();
        $list = TodoList::factory()->for($user)->create(['title' => 'My List']);
        TodoItem::factory()->count(3)->for($list)->create();

        actingAs($user);

        $response = getJson("/api/v1/todo-lists/{$list->id}")
            ->assertSuccessful()
            ->assertJsonPath('data.id', $list->id)
            ->assertJsonPath('data.title', 'My List');

        expect($response->json('data.items'))->toHaveCount(3);
    });

    it('updates a todo list', function (): void {
        $user = User::factory()->create();
        $list = TodoList::factory()->for($user)->create(['title' => 'Old']);

        actingAs($user);

        patchJson("/api/v1/todo-lists/{$list->id}", ['title' => 'New Title'])
            ->assertSuccessful()
            ->assertJsonPath('data.title', 'New Title');

        expect($list->fresh()->title)->toBe('New Title');
    });

    it('deletes a todo list (soft delete)', function (): void {
        $user = User::factory()->create();
        $list = TodoList::factory()->for($user)->create();

        actingAs($user);

        deleteJson("/api/v1/todo-lists/{$list->id}")->assertNoContent();

        expect(TodoList::find($list->id))->toBeNull()
            ->and(TodoList::withTrashed()->find($list->id))->not->toBeNull();
    });

    it('prevents viewing another user todo list', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = TodoList::factory()->for($owner)->create();

        actingAs($intruder);

        getJson("/api/v1/todo-lists/{$list->id}")->assertNotFound();
    });

    it('requires authentication', function (): void {
        getJson('/api/v1/todo-lists')->assertUnauthorized();
        postJson('/api/v1/todo-lists', [])->assertUnauthorized();
    });
});

describe('todo items', function (): void {
    it('adds an item with a title and explanation to a list', function (): void {
        $user = User::factory()->create();
        $list = TodoList::factory()->for($user)->create();

        actingAs($user);

        postJson("/api/v1/todo-lists/{$list->id}/items", [
            'title' => 'Write the docs',
            'description' => 'Cover the public API endpoints',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Write the docs')
            ->assertJsonPath('data.description', 'Cover the public API endpoints')
            ->assertJsonPath('data.is_done', false);
    });

    it('toggles an item done state', function (): void {
        $user = User::factory()->create();
        $list = TodoList::factory()->for($user)->create();
        $item = TodoItem::factory()->for($list)->create(['is_done' => false]);

        actingAs($user);

        patchJson("/api/v1/todo-items/{$item->id}", ['is_done' => true])
            ->assertSuccessful()
            ->assertJsonPath('data.is_done', true);

        expect($item->fresh()->is_done)->toBeTrue();
    });

    it('deletes an item', function (): void {
        $user = User::factory()->create();
        $list = TodoList::factory()->for($user)->create();
        $item = TodoItem::factory()->for($list)->create();

        actingAs($user);

        deleteJson("/api/v1/todo-items/{$item->id}")->assertNoContent();

        expect(TodoItem::find($item->id))->toBeNull();
    });

    it('prevents adding an item to another user list', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = TodoList::factory()->for($owner)->create();

        actingAs($intruder);

        postJson("/api/v1/todo-lists/{$list->id}/items", ['title' => 'Sneaky'])
            ->assertNotFound();
    });

    it('prevents toggling an item on another user list', function (): void {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $list = TodoList::factory()->for($owner)->create();
        $item = TodoItem::factory()->for($list)->create(['is_done' => false]);

        actingAs($intruder);

        patchJson("/api/v1/todo-items/{$item->id}", ['is_done' => true])->assertNotFound();

        expect($item->fresh()->is_done)->toBeFalse();
    });
});
