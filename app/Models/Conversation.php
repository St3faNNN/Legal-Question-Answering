<?php

namespace App\Models;

use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['title'])]
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /**
     * The earliest user message, used to derive a display title without loading
     * the full message collection.
     *
     * @return HasOne<Message, $this>
     */
    public function firstUserMessage(): HasOne
    {
        return $this->hasOne(Message::class)
            ->where('role', 'user')
            ->oldestOfMany('id');
    }

    /**
     * Get the display title: user-set value, first message snippet, or generic fallback.
     *
     * Prefers already-loaded relations (firstUserMessage, then messages) to avoid
     * an extra query; falls back to a query only when neither is loaded.
     */
    public function getDisplayTitleAttribute(): string
    {
        if ($this->title !== null) {
            return $this->title;
        }

        $firstMessage = $this->resolveFirstUserMessage();

        if ($firstMessage && strlen($firstMessage->content) > 0) {
            return rtrim(mb_substr($firstMessage->content, 0, 50));
        }

        return "Conversation {$this->id}";
    }

    private function resolveFirstUserMessage(): ?Message
    {
        if ($this->relationLoaded('firstUserMessage')) {
            return $this->firstUserMessage;
        }

        if ($this->relationLoaded('messages')) {
            return $this->messages->firstWhere('role', 'user');
        }

        return $this->firstUserMessage()->first();
    }
}
