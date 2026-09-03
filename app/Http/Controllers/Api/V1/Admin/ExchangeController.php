<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentRunResource;
use App\Models\AgentRun;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Browsing individual exchanges from the dashboard.
 *
 * ## Question text is a separate endpoint on purpose
 *
 * The list returns metadata only — timings, path, citations, lengths — and no
 * question or answer text. Reading the text is a second, explicit request for
 * one exchange at a time ({@see self::show()}).
 *
 * This is not squeamishness about data the operator is entitled to: users accept
 * a privacy notice saying conversations are retained, and the notice now says
 * plainly that the operator can read them. It is about the difference between
 * being able to read a question while debugging and reading everyone's questions
 * every time the dashboard loads. Splitting the endpoints makes the first a
 * click and the second impossible by accident, and it means a screen-share of
 * the dashboard does not expose anybody's legal problems.
 *
 * Read-only throughout: no endpoint here writes to an application table.
 */
class ExchangeController extends Controller
{
    /**
     * List recent answers (metadata only)
     *
     * Deliberately carries NO question or answer text — only what is needed to
     * spot an anomaly and decide which exchange is worth opening. `question_chars`
     * gives a sense of input size without revealing the input.
     *
     * @group Admin
     *
     * @authenticated
     *
     * @queryParam path string Filter to one path: pipeline, escalated, gated, refused. Example: refused
     * @queryParam cursor string Pagination cursor.
     *
     * @response 200 {"data":[{"id":347,"path":"pipeline","model":"gemini-flash-lite-latest","ttft_ms":3084,"citations":["ZAKON-ZA-RABOTNI, чл. 96"],"question_chars":78,"has_text":true}]}
     * @response 404 {}
     */
    public function index(Request $request): JsonResponse
    {
        $query = Message::query()
            ->where('role', 'assistant')
            ->with('agentRun:id,message_id,run_id,outcome')
            ->orderByDesc('id');

        $perPage = (int) config('limits.per_page.admin_exchanges');

        $rows = $query->cursorPaginate($perPage);

        $filter = (string) $request->query('path', '');
        $questionSizes = $this->questionSizesFor(collect($rows->items()));

        $data = collect($rows->items())
            ->map(fn (Message $m): array => $this->summarise($m, $questionSizes[$m->id] ?? null))
            ->when($filter !== '', fn ($rows) => $rows->where('path', $filter))
            ->values()
            ->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'next_cursor' => $rows->nextCursor()?->encode(),
                'per_page' => $perPage,
                // Filtering happens after the page is fetched (path lives inside
                // the JSON metadata), so a filtered page can be short without
                // meaning the results are exhausted. Said plainly rather than
                // letting the UI infer it.
                'filtered_in_page' => $filter !== '',
            ],
        ]);
    }

    /**
     * Read one exchange, including its text
     *
     * The explicit action. Returns the question that was asked and the answer
     * that was given, for one exchange, alongside its full metadata.
     *
     * @group Admin
     *
     * @authenticated
     *
     * @response 200 {"data":{"id":347,"question":"Кои се основите...","answer":"Работодавачот може...","metadata":{}}}
     * @response 404 {}
     */
    public function show(int $id): JsonResponse
    {
        /** @var Message $answer */
        $answer = Message::query()
            ->with('agentRun.questionMessage')
            ->where('role', 'assistant')
            ->findOrFail($id);

        // For an escalation, the question is recorded on the run — read it, do not
        // re-derive it. Deriving it by position is what previously ran an agent
        // against the wrong question, and an escalated answer is exactly the case
        // where "nearest preceding user turn" need not be the question answered:
        // the escalation writes an assistant row without a user row, so the turn
        // above it may belong to a different exchange.
        //
        // A pipeline answer has no run, and there the positional rule is sound —
        // the answer is written immediately after the question it answers. Note
        // this query goes through Message::query() rather than the conversation
        // relation, so it does not inherit the relation's orderBy('created_at')
        // and orderByDesc('id') really is the sort.
        $question = $answer->agentRun?->questionMessage
            ?? Message::query()
                ->where('conversation_id', $answer->conversation_id)
                ->where('role', 'user')
                ->where('id', '<', $answer->id)
                ->orderByDesc('id')
                ->first();

        return response()->json([
            'data' => [
                ...$this->summarise($answer, $question === null ? null : mb_strlen($question->content)),
                'searches' => collect($answer->metadata['searches'] ?? [])->pluck('query')->filter()->values()->all(),
                'question' => $question?->content,
                'question_id' => $question?->id,
                'answer' => $answer->content,
                'metadata' => $answer->metadata,
            ],
        ]);
    }

    /**
     * An agent run's trajectory
     *
     * The same shape the chat's trace view renders, reachable from the dashboard
     * without going through the user-scoped conversation route — that one
     * resolves through the signed-in user's own conversations by design, and
     * widening it for admins would weaken it for everybody.
     *
     * @group Admin
     *
     * @authenticated
     *
     * @response 200 {"data":{"run_id":"20260829-110442-opqr0w","outcome":"answered","totals":{"steps":2}}}
     * @response 404 {}
     */
    public function trace(int $id): AgentRunResource
    {
        return new AgentRunResource(AgentRun::query()->findOrFail($id));
    }

    /**
     * Metadata-only view of one answer. Shared by the list and the detail
     * endpoint, so the detail response is a superset rather than a different
     * shape.
     *
     * @return array<string, mixed>
     */
    private function summarise(Message $message, ?int $questionChars = null): array
    {
        $meta = $message->metadata ?? [];

        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'created_at' => $message->created_at?->toIso8601String(),
            'path' => $this->path($meta),
            'model' => $message->model,
            'retrieval_ms' => $meta['retrieval_ms'] ?? null,
            'ttft_ms' => $meta['ttft_ms'] ?? null,
            'total_ms' => $meta['total_ms'] ?? $meta['total_latency_ms'] ?? null,
            'latency_ms' => $message->latency_ms,
            'chunk_count' => $meta['chunk_count'] ?? null,
            'context_items' => $meta['context_items'] ?? null,
            'tokens' => $message->tokens,
            'agent_tokens' => $meta['agent_tokens'] ?? null,
            'run_id' => $meta['run_id'] ?? null,
            'agent_run_id' => $message->agentRun?->id,
            'citations' => collect($meta['top_chunks'] ?? [])->pluck('source')->filter()->values()->all(),
            // The COUNT of searches, not the queries. An agent's generated query
            // ("технолошки вишок отказ испратнина") is a paraphrase of the user's
            // question, so listing the strings would put question content back
            // into the list view by the side door. The strings are in the
            // trajectory, behind the same explicit action as the text.
            'search_count' => count($meta['searches'] ?? []),
            'uncited_articles' => $meta['uncited_articles'] ?? [],
            // Size without content: enough to spot an outlier (a pasted contract,
            // an empty box), not enough to read what was asked.
            'question_chars' => $questionChars,
            'answer_chars' => mb_strlen((string) $message->content),
        ];
    }

    /**
     * The length of the question each answer responded to, keyed by answer id.
     *
     * One extra query for the whole page rather than one per row. Only lengths
     * leave this method — the content is loaded to measure it and then dropped,
     * so no question text reaches the list response.
     *
     * @param  Collection<int, Message>  $answers
     * @return array<int, int>
     */
    private function questionSizesFor(Collection $answers): array
    {
        if ($answers->isEmpty()) {
            return [];
        }

        $questions = Message::query()
            ->where('role', 'user')
            ->whereIn('conversation_id', $answers->pluck('conversation_id')->unique()->all())
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'content']);

        $sizes = [];
        foreach ($answers as $answer) {
            $preceding = $questions
                ->where('conversation_id', $answer->conversation_id)
                ->where('id', '<', $answer->id)
                ->last();

            if ($preceding !== null) {
                $sizes[$answer->id] = mb_strlen($preceding->content);
            }
        }

        return $sizes;
    }

    /**
     * Which path produced an answer. Mirrors MetricsService::classify() — an
     * absent flag means the row predates the flag, not that the flag was false.
     *
     * @param  array<string, mixed>  $meta
     */
    private function path(array $meta): string
    {
        return match (true) {
            ($meta['gated'] ?? false) === true => 'gated',
            ($meta['agent'] ?? false) === true => 'escalated',
            ($meta['refused'] ?? false) === true => 'refused',
            array_key_exists('refused', $meta), array_key_exists('chunk_count', $meta) => 'pipeline',
            default => 'unclassified',
        };
    }
}
