<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        Conversation::factory()
            ->count(3)
            ->for($user)
            ->has(Message::factory()->count(4)->sequence(
                ['role' => 'user', 'content' => 'What is GraphRAG?'],
                ['role' => 'assistant', 'content' => 'AI service not connected yet.'],
                ['role' => 'user', 'content' => 'How does it work?'],
                ['role' => 'assistant', 'content' => 'AI service not connected yet.'],
            ))
            ->create(['title' => 'GraphRAG Intro']);

        Conversation::factory()
            ->count(2)
            ->for($user)
            ->create();

        $other = User::factory()->create([
            'name' => 'Another User',
            'email' => 'other@example.com',
        ]);

        Conversation::factory()
            ->count(2)
            ->for($other)
            ->has(Message::factory()->count(2))
            ->create();
    }
}
