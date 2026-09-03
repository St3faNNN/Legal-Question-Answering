<?php

namespace App\Services\Admin;

use App\Models\AgentRun;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\GenerationBudget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operational metrics, aggregated from the tables that survive a deploy.
 *
 * ## Why the tables and not the ai log channel
 *
 * The `ai` channel records the richest per-request detail, and it is the wrong
 * source for a dashboard: `storage/` is deliberately not mounted into the app
 * containers (see docker-compose.yml), so every container recreate discards it.
 * At the time of writing the host holds months of `ai-*.log` while the running
 * container holds 2 KB from today. A dashboard built on it would silently reset
 * on each deploy and read as "traffic collapsed".
 *
 * Everything here therefore comes from `messages`, `agent_runs`, `conversations`
 * and `failed_jobs`, which are Postgres rows.
 *
 * ## Coverage is reported, never assumed
 *
 * Message metadata is a JSON column that grew field by field, so a metric's
 * denominator is not "all messages" — it is "messages that carry the field".
 * Measured on the current data: `ttft_ms` and `retrieval_ms` are present on 71
 * of 161 assistant messages (they were added on 2026-08-10), `chunk_count` on
 * 105, and the `refused` / `gated` / `agent` flags on 17 / 4 / 1 respectively.
 *
 * Two consequences are load-bearing:
 *
 *   - An absent flag is NOT false. A message written before the refusal feature
 *     has no `refused` key; counting `refused !== true` as "answered" would
 *     silently reclassify 144 rows. Paths are therefore counted into an explicit
 *     `unclassified` bucket instead.
 *   - Every distribution carries the `n` it was computed from and the `total` it
 *     was drawn out of, so a percentile over 44% of the rows cannot be read as a
 *     percentile over all of them.
 */
class MetricsService
{
    public function __construct(private readonly GenerationBudget $budget) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $windowDays = max(1, (int) config('admin.window_days', 90));
        $from = Carbon::now()->subDays($windowDays)->startOfDay();

        $assistant = Message::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', $from)
            ->orderBy('id')
            ->get();

        $questions = Message::query()
            ->where('role', 'user')
            ->where('created_at', '>=', $from)
            ->count();

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'window' => [
                'days' => $windowDays,
                'from' => $from->toDateString(),
                'to' => Carbon::now()->toDateString(),
            ],
            'volume' => $this->volume($assistant, $questions, $from),
            'latency' => $this->latency($assistant),
            'tokens' => $this->tokens($assistant),
            'agent' => $this->agent(),
            'failures' => $this->failures($assistant),
        ];
    }

    /**
     * Questions per day, split by the path that answered them.
     *
     * @param  Collection<int, Message>  $assistant
     * @return array<string, mixed>
     */
    private function volume(Collection $assistant, int $questions, Carbon $from): array
    {
        $byDay = $assistant->groupBy(fn (Message $m): string => $m->created_at->toDateString());

        $daily = [];
        foreach ($byDay as $date => $rows) {
            $counts = $this->classify($rows);
            $daily[] = ['date' => $date, 'total' => $rows->count()] + $counts;
        }
        usort($daily, fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return [
            // Questions asked (user turns) and answers produced are different
            // numbers: an escalation adds an answer without a new question.
            'questions' => $questions,
            'answers' => $assistant->count(),
            'conversations' => Conversation::query()->where('created_at', '>=', $from)->count(),
            'users_active' => Conversation::query()
                ->where('created_at', '>=', $from)
                ->distinct('user_id')
                ->count('user_id'),
            'by_path' => $this->classify($assistant),
            'daily' => $daily,
        ];
    }

    /**
     * Bucket answers by the path that produced them, keeping "the flag predates
     * this row" separate from "the flag was false".
     *
     * @param  Collection<int, Message>  $rows
     * @return array{gated: int, escalated: int, refused: int, pipeline: int, unclassified: int}
     */
    private function classify(Collection $rows): array
    {
        $counts = ['gated' => 0, 'escalated' => 0, 'refused' => 0, 'pipeline' => 0, 'unclassified' => 0];

        foreach ($rows as $row) {
            $meta = $row->metadata ?? [];

            if (($meta['gated'] ?? false) === true) {
                $counts['gated']++;
            } elseif (($meta['agent'] ?? false) === true) {
                $counts['escalated']++;
            } elseif (($meta['refused'] ?? false) === true) {
                $counts['refused']++;
            } elseif (array_key_exists('refused', $meta) || array_key_exists('chunk_count', $meta)) {
                // The row is from an era that recorded the flags, and none fired:
                // a plain pipeline answer.
                $counts['pipeline']++;
            } else {
                // Predates the flags entirely. Not a pipeline answer we can vouch
                // for, so it is counted apart rather than folded in.
                $counts['unclassified']++;
            }
        }

        return $counts;
    }

    /**
     * Latency distributions. Percentiles rather than a mean, because the tail is
     * the part that hurts, and a mean over a bimodal population (hosted seconds
     * vs the retired local path's ~90s prefill) describes neither mode.
     *
     * @param  Collection<int, Message>  $assistant
     * @return array<string, mixed>
     */
    private function latency(Collection $assistant): array
    {
        $total = $assistant->count();

        return [
            'total_messages' => $total,
            'retrieval_ms' => $this->distribution($this->pluckMetric($assistant, 'retrieval_ms'), $total),
            'ttft_ms' => $this->distribution($this->pluckMetric($assistant, 'ttft_ms'), $total),
            'total_ms' => $this->distribution($this->pluckMetric($assistant, 'total_ms'), $total),
            // Present on nearly every row, including the pre-streaming era, so it
            // is the one latency series with near-full coverage.
            'generation_latency_ms' => $this->distribution($this->pluckMetric($assistant, 'generation_latency_ms'), $total),
            'by_model' => $this->latencyByModel($assistant),
            'null_model' => $this->nullModelAnswers($assistant),
        ];
    }

    /**
     * Answers that arrived without a model name.
     *
     * The audit line for the by-model table, in the same spirit as the token
     * card's legacy-row count: by-model latency is only meaningful if answers
     * actually carry a model, and a driver that silently stops sending one would
     * otherwise just thin the table without saying so.
     *
     * Gated replies are excluded from the count, not from the denominator: they
     * are answered from a canned message with no model call, so a null model
     * there is correct rather than a symptom.
     *
     * @param  Collection<int, Message>  $assistant
     * @return array<string, mixed>
     */
    private function nullModelAnswers(Collection $assistant): array
    {
        $gated = $assistant->filter(fn (Message $m): bool => ($m->metadata['gated'] ?? false) === true)->count();

        return [
            'count' => $assistant->filter(
                fn (Message $m): bool => $m->model === null && ($m->metadata['gated'] ?? false) !== true,
            )->count(),
            'out_of' => $assistant->count(),
            'gated_excluded' => $gated,
            'note' => 'Generated answers carrying no model name. Gated replies are excluded — '
                .'they make no model call, so a null model there is expected. Anything else here '
                .'means a generation driver stopped reporting which model answered.',
        ];
    }

    /**
     * @param  Collection<int, Message>  $assistant
     * @return list<int>
     */
    private function pluckMetric(Collection $assistant, string $key): array
    {
        return $assistant
            ->map(fn (Message $m) => $m->metadata[$key] ?? null)
            ->filter(fn ($v): bool => is_numeric($v))
            ->map(fn ($v): int => (int) $v)
            ->values()
            ->all();
    }

    /**
     * Percentiles, a histogram, and — always — the sample the numbers came from.
     *
     * @param  list<int>  $values
     * @return array<string, mixed>
     */
    private function distribution(array $values, int $outOf): array
    {
        sort($values);
        $n = count($values);

        if ($n === 0) {
            return ['n' => 0, 'out_of' => $outOf, 'coverage' => 0.0, 'available' => false];
        }

        $buckets = [];
        $previous = 0;
        /** @var list<int> $edges Histogram edges, in ms (config/admin.php). */
        $edges = config('admin.latency_buckets_ms', []);

        foreach ($edges as $edge) {
            $buckets[] = [
                'label' => $previous === 0 ? "<{$edge}" : "{$previous}-{$edge}",
                'count' => count(array_filter($values, fn (int $v): bool => $v >= $previous && $v < $edge)),
            ];
            $previous = $edge;
        }
        $buckets[] = [
            'label' => '≥'.$previous,
            'count' => count(array_filter($values, fn (int $v): bool => $v >= $previous)),
        ];

        return [
            'available' => true,
            'n' => $n,
            'out_of' => $outOf,
            'coverage' => $outOf > 0 ? round($n / $outOf, 4) : 0.0,
            'min' => $values[0],
            'p50' => $this->percentile($values, 0.50),
            'p90' => $this->percentile($values, 0.90),
            'p95' => $this->percentile($values, 0.95),
            'p99' => $this->percentile($values, 0.99),
            'max' => $values[$n - 1],
            'histogram' => $buckets,
        ];
    }

    /**
     * Nearest-rank percentile. No interpolation: with samples this small an
     * interpolated p99 invents a value that was never observed.
     *
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $q): int
    {
        $n = count($sorted);
        $rank = (int) max(1, min($n, ceil($q * $n)));

        return $sorted[$rank - 1];
    }

    /**
     * Latency split by model, because the data spans several generations of this
     * system (BART, a series of FLAN-T5s, local Qwen, now hosted Gemini). Pooling
     * them produces a number that describes no configuration that ever ran.
     *
     * @param  Collection<int, Message>  $assistant
     * @return list<array<string, mixed>>
     */
    private function latencyByModel(Collection $assistant): array
    {
        $rows = [];

        foreach ($assistant->whereNotNull('model')->groupBy('model') as $model => $group) {
            $values = $this->pluckMetric($group, 'total_ms');
            if ($values === []) {
                $values = $group->pluck('latency_ms')->filter()->map(fn ($v): int => (int) $v)->values()->all();
            }
            sort($values);

            $rows[] = [
                'model' => (string) $model,
                'answers' => $group->count(),
                'p50_ms' => $values === [] ? null : $this->percentile($values, 0.50),
                'p95_ms' => $values === [] ? null : $this->percentile($values, 0.95),
                'last_seen' => $group->max('created_at')?->toDateString(),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['answers'] <=> $a['answers']);

        return $rows;
    }

    /**
     * Token cost.
     *
     * The escalation half is exact: RunAgentEscalation persists the agent's own
     * usage totals per run. The pipeline half is NOT AVAILABLE and is reported as
     * such rather than estimated — see the `pipeline` key. Every positive `tokens`
     * value on a pipeline message comes from the retired local-model era (BART,
     * FLAN-T5, Qwen; last seen 2026-08-08), where the gRPC service returned a
     * count. The current hosted driver streams, and the streamed response carries
     * no usage block, so of 87 Gemini-era answers exactly one has a token count —
     * and that one is an escalation.
     *
     * @param  Collection<int, Message>  $assistant
     * @return array<string, mixed>
     */
    private function tokens(Collection $assistant): array
    {
        $runs = AgentRun::query()->get();
        $runTokens = $runs->pluck('total_tokens')->filter()->map(fn ($v): int => (int) $v)->values()->all();
        sort($runTokens);

        return [
            'escalation' => [
                'available' => $runTokens !== [],
                'runs' => count($runTokens),
                'total' => array_sum($runTokens),
                'mean' => $runTokens === [] ? null : (int) round(array_sum($runTokens) / count($runTokens)),
                'min' => $runTokens[0] ?? null,
                'max' => $runTokens === [] ? null : $runTokens[count($runTokens) - 1],
            ],
            'pipeline' => [
                'available' => false,
                'reason' => 'The hosted generation driver streams its answer, and the streamed '
                    .'response carries no usage block, so no per-answer token count is stored. '
                    .'The only positive counts on pipeline messages come from the retired '
                    .'local-model era and are not comparable. Reporting an estimate here would '
                    .'be a guess dressed as a measurement.',
                // Kept so the claim above is auditable rather than asserted.
                'legacy_rows_with_tokens' => $assistant
                    ->filter(fn (Message $m): bool => ($m->metadata['agent'] ?? false) !== true && (int) $m->tokens > 0)
                    ->count(),
            ],
            'budget' => [
                'daily_units' => $this->budget->allowance(),
                'pipeline_cost' => $this->budget->pipelineCost(),
                'escalation_cost' => $this->budget->escalationCost(),
                'today' => $this->budgetToday(),
            ],
        ];
    }

    /**
     * Today's spend against the meter, per user.
     *
     * The meter lives in the cache with a midnight TTL, so this is a live gauge
     * and not a history: yesterday's numbers are gone, and a cache flush zeroes
     * it. Presented as "today", never as a trend.
     *
     * @return list<array{user_id: int, name: string, used: int, remaining: int}>
     */
    private function budgetToday(): array
    {
        if ($this->budget->allowance() === 0) {
            return [];
        }

        return User::query()
            ->orderBy('id')
            ->get()
            ->map(fn ($user): array => [
                'user_id' => $user->id,
                'name' => $user->name,
                'used' => $this->budget->used($user),
                'remaining' => $this->budget->remaining($user),
            ])
            ->filter(fn (array $row): bool => $row['used'] > 0)
            ->values()
            ->all();
    }

    /**
     * Agent runs: outcome mix, effort per run, and enough identity to open the
     * trajectory. Exact — agent_runs is written once per escalation with the
     * agent's own totals.
     *
     * @return array<string, mixed>
     */
    private function agent(): array
    {
        $runs = AgentRun::query()->with('message:id,conversation_id')->orderByDesc('id')->get();

        $steps = $runs->pluck('steps_count')->map(fn ($v): int => (int) $v)->sort()->values()->all();
        $searches = $runs->map(fn (AgentRun $r): int => $this->searchCount($r))->sort()->values()->all();

        return [
            'runs' => $runs->count(),
            'by_outcome' => $runs->groupBy('outcome')->map->count()->all(),
            'steps' => [
                'mean' => $steps === [] ? null : round(array_sum($steps) / count($steps), 1),
                'min' => $steps[0] ?? null,
                'max' => $steps === [] ? null : $steps[count($steps) - 1],
            ],
            'searches_per_run' => [
                'mean' => $searches === [] ? null : round(array_sum($searches) / count($searches), 1),
                'min' => $searches[0] ?? null,
                'max' => $searches === [] ? null : $searches[count($searches) - 1],
            ],
            'recent' => $runs->take(25)->map(fn (AgentRun $r): array => [
                'id' => $r->id,
                'run_id' => $r->run_id,
                'outcome' => $r->outcome,
                'model' => $r->model,
                'steps' => $r->steps_count,
                'api_calls' => $r->api_calls,
                'searches' => $this->searchCount($r),
                'total_tokens' => $r->total_tokens,
                'duration_ms' => $r->duration_ms,
                'message_id' => $r->message_id,
                'conversation_id' => $r->message?->conversation_id,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** How many tool calls a run issued, counted from its stored trajectory. */
    private function searchCount(AgentRun $run): int
    {
        $count = 0;
        foreach ($run->steps ?? [] as $step) {
            $count += count(is_array($step) ? ($step['calls'] ?? []) : []);
        }

        return $count;
    }

    /**
     * Failure surfaces. `failed_jobs` is durable and exact; refusals and gated
     * inputs are counted only over the rows that carry the flag, for the reason
     * in the class docblock.
     *
     * @param  Collection<int, Message>  $assistant
     * @return array<string, mixed>
     */
    private function failures(Collection $assistant): array
    {
        $flagged = $assistant->filter(fn (Message $m): bool => array_key_exists('refused', $m->metadata ?? []));

        $failedJobs = DB::table('failed_jobs')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(function ($job): array {
                $payload = json_decode((string) $job->payload, true);
                $exception = (string) $job->exception;

                return [
                    'id' => $job->id,
                    'job' => $payload['displayName'] ?? 'unknown',
                    'failed_at' => $job->failed_at,
                    // First line only: the class and message, without the trace.
                    'reason' => trim(strtok($exception, "\n") ?: ''),
                ];
            })
            ->values()
            ->all();

        return [
            'refusals' => [
                'count' => $assistant->filter(fn (Message $m): bool => ($m->metadata['refused'] ?? false) === true)->count(),
                'out_of' => $flagged->count(),
                'note' => 'Counted only over answers written since the refusal flag existed; '
                    .'earlier rows carry no flag and are excluded from the denominator.',
            ],
            'gated' => [
                'count' => $assistant->filter(fn (Message $m): bool => ($m->metadata['gated'] ?? false) === true)->count(),
                'out_of' => $assistant->count(),
            ],
            'failed_jobs' => [
                'total' => DB::table('failed_jobs')->count(),
                'recent' => $failedJobs,
            ],
        ];
    }
}
