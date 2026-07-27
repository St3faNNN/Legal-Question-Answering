<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['source', 'title', 'url', 'position', 'published_at'])]
class NewsArticle extends Model
{
    protected $casts = [
        'position' => 'integer',
        'published_at' => 'datetime',
    ];
}
