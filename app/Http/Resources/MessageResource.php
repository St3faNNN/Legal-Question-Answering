<?php

namespace App\Http\Resources;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Message */
class MessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'role' => $this->role,
            'content' => $this->content,
            'model' => $this->model,
            'latency_ms' => $this->latency_ms,
            'tokens' => $this->tokens,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
