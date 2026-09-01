<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The persisted trajectory of one agent escalation: which searches were issued,
 * what came back, and the passages the run retrieved. Retrievable per message via
 * GET /api/v1/messages/{id}/trace, which is what makes a run inspectable in the
 * app rather than only in the CLI inspector.
 */
#[Fillable([
    'run_id', 'question', 'outcome', 'model',
    'steps_count', 'api_calls', 'total_tokens', 'duration_ms',
    'steps', 'passages',
])]
class AgentRun extends Model
{
    protected $casts = [
        'steps' => 'array',
        'passages' => 'array',
        'steps_count' => 'integer',
        'api_calls' => 'integer',
        'total_tokens' => 'integer',
        'duration_ms' => 'integer',
    ];

    /**
     * @return BelongsTo<Message, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
