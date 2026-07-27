<?php

namespace App\Policies;

use App\Models\TodoList;
use App\Models\User;

class TodoListPolicy
{
    public function view(User $user, TodoList $todoList): bool
    {
        return $todoList->user_id === $user->id;
    }

    public function update(User $user, TodoList $todoList): bool
    {
        return $todoList->user_id === $user->id;
    }

    public function delete(User $user, TodoList $todoList): bool
    {
        return $todoList->user_id === $user->id;
    }
}
