<?php

namespace App\Models;

use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A single-use registration invitation. Redeeming one is the only way to create
 * an account now that open signup is closed.
 */
#[Fillable(['code', 'email', 'note', 'invited_by_user_id', 'used_at', 'used_by_user_id', 'expires_at'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Generate an unguessable, human-transcribable code (no ambiguous chars).
     */
    public static function generateCode(): string
    {
        return Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4));
    }

    /**
     * Whether this code may still be redeemed. When $email is given, an
     * email-bound code only matches that address (case-insensitive).
     */
    public function isRedeemable(?string $email = null): bool
    {
        if ($this->used_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->email !== null && $email !== null && strcasecmp($this->email, $email) !== 0) {
            return false;
        }

        return true;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by_user_id');
    }
}
