<?php

namespace App\Models;

use Database\Factories\TodoItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['title', 'description', 'is_done'])]
class TodoItem extends Model
{
    /** @use HasFactory<TodoItemFactory> */
    use HasFactory;

    protected $casts = [
        'is_done' => 'boolean',
    ];

    /**
     * @return BelongsTo<TodoList, $this>
     */
    public function todoList(): BelongsTo
    {
        return $this->belongsTo(TodoList::class);
    }
}
