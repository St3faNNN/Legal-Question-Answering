<?php

namespace App\Http\Resources;

use App\Models\NewsArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NewsArticle */
class NewsArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'url' => $this->url,
            'source' => $this->source,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
        ];
    }
}
