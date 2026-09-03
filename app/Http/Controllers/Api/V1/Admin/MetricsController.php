<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Services\Admin\MetricsService;
use Illuminate\Http\JsonResponse;

/**
 * Operational metrics for the dashboard. Read-only: nothing here writes, and
 * nothing here touches retrieval, generation, the agent or the gate.
 *
 * Access is enforced by {@see EnsureUserIsAdmin} on the
 * route, which 404s a non-allowlisted caller.
 */
class MetricsController extends Controller
{
    public function __construct(private readonly MetricsService $metrics) {}

    /**
     * Operational overview
     *
     * Volume by path, latency distributions, token cost, agent runs and failure
     * surfaces, aggregated from the durable tables (messages, agent_runs,
     * conversations, failed_jobs) rather than the ai log channel, which does not
     * survive a container recreate.
     *
     * Every distribution reports the sample it was computed from (`n`,
     * `out_of`, `coverage`), because message metadata grew field by field and a
     * metric's denominator is not "all messages".
     *
     * @group Admin
     *
     * @authenticated
     *
     * @response 200 {"data":{"window":{"days":90},"volume":{"questions":175,"answers":161},"latency":{"ttft_ms":{"available":true,"n":71,"out_of":161,"p50":2571}}}}
     * @response 404 {}
     */
    public function overview(): JsonResponse
    {
        return response()->json(['data' => $this->metrics->overview()]);
    }
}
