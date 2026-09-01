<?php

namespace App\Http\Resources;

use App\Models\AgentRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One agent trajectory, in the shape the CLI trace inspector renders: the run's
 * totals, then each step's tool calls with the citations they returned. This is
 * what makes an escalation inspectable after the fact rather than only while it
 * streams.
 *
 * @mixin AgentRun
 */
class AgentRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'run_id' => $this->run_id,
            'question' => $this->question,
            'outcome' => $this->outcome,
            'model' => $this->model,
            'totals' => [
                'steps' => $this->steps_count,
                'api_calls' => $this->api_calls,
                'total_tokens' => $this->total_tokens,
                'duration_ms' => $this->duration_ms,
            ],
            'steps' => $this->steps,
            'passages' => $this->passages,
            'created_at' => $this->created_at,
        ];
    }
}
