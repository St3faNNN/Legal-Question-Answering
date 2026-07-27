<?php

namespace App\Http\Resources;

use App\Models\TodoList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TodoList */
class TodoListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'due_at' => $this->due_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'items' => TodoItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
