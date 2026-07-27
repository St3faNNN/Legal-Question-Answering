<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsArticleResource;
use App\Models\NewsArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class NewsController extends Controller
{
    /**
     * Latest news
     *
     * The latest scraped konkursi, newest first, for the dashboard "newspaper"
     * section. Defaults to 3.
     *
     * @group News
     *
     * @authenticated
     *
     * @response 200 {"data":[{"id":1,"title":"Конкурс...","url":"https://mon.gov.mk/...","source":"mon"}]}
     */
    public function index(Request $request): ResourceCollection
    {
        $limit = (int) $request->integer('limit', 3);
        $limit = max(1, min($limit, 20));

        $articles = NewsArticle::query()
            ->where('source', 'mon')
            ->orderBy('position')
            ->limit($limit)
            ->get();

        return NewsArticleResource::collection($articles);
    }
}
