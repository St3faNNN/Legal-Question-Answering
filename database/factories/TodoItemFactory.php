<?php

namespace Database\Factories;

use App\Models\TodoItem;
use App\Models\TodoList;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TodoItem> */
class TodoItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'todo_list_id' => TodoList::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional(70)->sentence(),
            'is_done' => fake()->boolean(30),
        ];
    }
}
