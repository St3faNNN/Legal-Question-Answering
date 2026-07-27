<?php

namespace App\Http\Resources;

use App\Models\TodoItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TodoItem */
class TodoItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'todo_list_id' => $this->todo_list_id,
            'title' => $this->title,
            'description' => $this->description,
            'is_done' => $this->is_done,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
