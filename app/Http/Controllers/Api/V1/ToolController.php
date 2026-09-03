<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\RetrievalClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\FollowReferencesRequest;
use App\Http\Requests\Tools\SearchLawRequest;
use Illuminate\Http\JsonResponse;

/**
 * Agent-facing tools. These expose existing capabilities of the app as thin,
 * single-purpose HTTP endpoints a hand-written agent loop can call over the
 * OpenAI-compatible tool interface. They are not consumed by the SPA.
 */
class ToolController extends Controller
{
    /**
     * Search Macedonian legislation
     *
     * Runs the existing retrieval pipeline for a single query and returns the
     * retrieved passages. Each result carries the article citation (`source`)
     * to cite and feed into follow-up searches, and the passage `text` to
     * reason over — the exact shape {@see RetrievalClient::retrieve()} yields.
     *
     * A retrieval failure surfaces as the app-wide 503 JSON, and an
     * unauthenticated request as 401 JSON; the agent treats both as a tool
     * failure and can rephrase and retry.
     *
     * @group Tools
     *
     * @authenticated
     *
     * @bodyParam query string required The search term, in Macedonian. Example: рок за поднесување жалба јавна набавка
     *
     * @response 200 {"query":"...","count":2,"results":[{"id":"1","source":"ZAKON-A, чл. 1","text":"...","score":7.5}]}
     */
    public function searchLaw(SearchLawRequest $request, RetrievalClient $retrieval): JsonResponse
    {
        $query = $request->validated('query');

        // Resolved as a method dependency (not in the constructor) so validation
        // runs first: an invalid request returns 422 without touching retrieval,
        // and only a valid query resolves the gRPC client / test fake.
        $results = $retrieval->retrieve($query)['chunks'];

        return response()->json([
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ]);
    }

    /**
     * Follow an article's cross-references
     *
     * Returns the articles a given article cites — its outgoing edges in the
     * corpus citation graph. Identifier-only by design: each reference carries
     * its `citation` (to cite and to feed a follow-up search-law), a short
     * `heading` label, and the parsed cross-reference `confidence` — never body
     * text, which would flood the agent's context. `found` is false (with an
     * empty list, HTTP 200) when the article is not in the corpus.
     *
     * @group Tools
     *
     * @authenticated
     *
     * @bodyParam law string required Short document code, the part before the comma in a citation. Example: ZAKON-ZA-JAVNITE
     * @bodyParam article string required Article number. Example: 181
     *
     * @response 200 {"law":"ZAKON-ZA-JAVNITE","article":"181","found":true,"source_citation":"ZAKON-ZA-JAVNITE, чл. 181","count":17,"references":[{"citation":"ZAKON-ZA-JAVNITE, чл. 55","heading":"Постапка со преговарање без објавување оглас","confidence":1.0}]}
     */
    public function followReferences(FollowReferencesRequest $request, RetrievalClient $retrieval): JsonResponse
    {
        $validated = $request->validated();

        $result = $retrieval->references($validated['law'], $validated['article']);

        return response()->json([
            'law' => $validated['law'],
            'article' => $validated['article'],
            'found' => $result['found'],
            'source_citation' => $result['source_citation'],
            'count' => count($result['references']),
            'references' => $result['references'],
        ]);
    }
}
