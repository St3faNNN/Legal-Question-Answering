<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record that a user accepted a specific legal document at a specific version.
 * Append-only: a new row is written each time, so the history of what a user
 * agreed to (and when) is preserved across document version changes.
 */
#[Fillable(['user_id', 'document', 'version', 'ip_address', 'accepted_at'])]
class TermsAcceptance extends Model
{
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
