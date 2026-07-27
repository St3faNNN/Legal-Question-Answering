<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('todo_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('todo_list_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamps();

            $table->index(['todo_list_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('todo_items');
    }
};
