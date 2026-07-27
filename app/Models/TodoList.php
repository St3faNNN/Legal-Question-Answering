<?php

namespace App\Models;

use Database\Factories\TodoListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title', 'due_at'])]
class TodoList extends Model
{
    /** @use HasFactory<TodoListFactory> */
    use HasFactory, SoftDeletes;

    protected $casts = [
        'due_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<TodoItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(TodoItem::class)->orderBy('created_at');
    }
}
