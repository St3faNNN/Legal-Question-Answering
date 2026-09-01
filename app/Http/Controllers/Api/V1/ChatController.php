<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\EscalateRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Jobs\StreamChatReply;
use App\Services\Chat\ChatService;
use App\Services\Chat\EscalationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $service,
        private readonly EscalationService $escalation,
    ) {}

    /**
     * Send a message
     *
     * Sends a user message to the chat, creates or continues a conversation,
     * and returns the assistant's generated reply.
     *
     * @group Chat
     *
     * @authenticated
     *
     * @bodyParam message string required The user's message (max 10000 chars). Example: What is GraphRAG?
     * @bodyParam conversation_id int optional ID of an existing conversation to continue. Example: 1
     *
     * @response 201 {"conversation":{"id":1,"title":null,"created_at":"...","updated_at":"..."},"message":{"id":2,"conversation_id":1,"role":"assistant","content":"...","model":"...","tokens":48,"latency_ms":850,"metadata":{"entities":["..."]},"created_at":"..."}}
     */
    public function send(SendMessageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->service->sendMessage(
            $request->user(),
            $validated['message'],
            $validated['conversation_id'] ?? null,
        );

        return response()->json([
            'conversation' => new ConversationResource($result['conversation']),
            'message' => new MessageResource($result['message']),
        ], 201);
    }

    /**
     * Send a message (streaming)
     *
     * Persists the user message and dispatches a queued job that streams the
     * assistant reply token-by-token. Returns immediately with a stream id the
     * client subscribes to via the SSE endpoint below.
     *
     * @group Chat
     *
     * @authenticated
     *
     * @bodyParam message string required The user's message. Example: Who is Albert Einstein?
     * @bodyParam conversation_id int optional Existing conversation to continue. Example: 1
     *
     * @response 202 {"conversation":{"id":1},"stream_id":"9b1c..."}
     */
    public function stream(SendMessageRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->service->startStreamedReply(
            $request->user(),
            $validated['message'],
            $validated['conversation_id'] ?? null,
        );

        return response()->json([
            'conversation' => new ConversationResource($result['conversation']),
            'stream_id' => $result['stream_id'],
        ], 202);
    }

    /**
     * Escalate an answer to the research agent
     *
     * Re-answers the question behind an existing assistant message with the
     * multi-hop agent instead of the single retrieve-then-generate pipeline. The
     * run costs several times a normal answer, so it is charged against the daily
     * generation budget (429 when that is spent) and rate-limited separately.
     *
     * Returns immediately with a stream id; the browser subscribes to the SAME
     * SSE endpoint the pipeline uses, and receives one `agent_search` status per
     * query as the agent issues it, then the answer.
     *
     * Refused with 422 where the agent has nothing to add: a gated greeting, an
     * out-of-corpus refusal, or an answer that is already an escalation.
     *
     * @group Chat
     *
     * @authenticated
     *
     * @bodyParam conversation_id int required The conversation holding the answer. Example: 1
     * @bodyParam message_id int required The assistant message to escalate. Example: 4
     *
     * @response 202 {"conversation":{"id":1},"stream_id":"9b1c...","escalated_from":4,"cost_units":3,"remaining_units":54}
     */
    public function escalate(EscalateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->escalation->escalate(
            $request->user(),
            $validated['conversation_id'],
            $validated['message_id'],
        );

        return response()->json([
            'conversation' => new ConversationResource($result['conversation']),
            'stream_id' => $result['stream_id'],
            'escalated_from' => $result['message_id'],
            'cost_units' => $result['cost'],
            'remaining_units' => $result['remaining'],
        ], 202);
    }

    /**
     * Stream reply events (SSE)
     *
     * Server-Sent Events relay: drains the stream's Redis list and forwards
     * `status`, `chunk`, `done`, and `error` events to the browser.
     * Authenticated via the same-origin session cookie (EventSource cannot send
     * bearer headers).
     *
     * @group Chat
     *
     * @authenticated
     */
    public function streamEvents(Request $request, string $streamId): StreamedResponse
    {
        $ownerId = Redis::get("chat:stream:{$streamId}:user");
        abort_unless($ownerId !== null && (int) $ownerId === $request->user()->id, 403);

        $key = StreamChatReply::channelKey($streamId);

        // Two silence budgets: the first token can be many seconds away
        // (retrieval, then generation prefill), but once tokens flow they arrive
        // ~10/s, so a mid-stream stall must be caught far sooner. Both are a
        // backstop — a clean job failure publishes its own terminal error via
        // StreamChatReply::failed(); these only fire when the worker died
        // without saying so (SIGKILL/OOM).
        $firstChunkTimeout = (float) $this->firstChunkTimeout();
        $betweenChunkTimeout = (float) config('ai.stream.between_chunk_timeout');
        $pollSeconds = max(1, (int) config('ai.stream.poll_seconds'));

        return response()->stream(function () use ($key, $firstChunkTimeout, $betweenChunkTimeout, $pollSeconds): void {
            $redis = Redis::connection();
            $seenChunk = false;
            $lastEventAt = microtime(true);

            while (! connection_aborted()) {
                // Block for the next event; a Redis list buffers chunks
                // produced before the browser connected (pub/sub would drop
                // them).
                $item = $redis->blpop([$key], $pollSeconds);

                if ($item === null) {
                    // Emit a keepalive (which also gives connection_aborted() a
                    // write to notice a gone client), then give up if the
                    // tolerated silence has elapsed — a stalled first token and a
                    // stalled mid-stream have very different budgets.
                    echo ": keepalive\n\n";
                    $this->flushOutput();

                    $budget = $seenChunk ? $betweenChunkTimeout : $firstChunkTimeout;

                    if ((microtime(true) - $lastEventAt) >= $budget) {
                        $this->emitError(
                            $seenChunk ? 'stalled' : 'timeout',
                            $seenChunk
                                ? 'The assistant stopped responding. Please try again.'
                                : 'The assistant took too long to respond. Please try again.',
                        );

                        break;
                    }

                    continue;
                }

                $lastEventAt = microtime(true);
                $payload = $item[1];
                echo "data: {$payload}\n\n";
                $this->flushOutput();

                $event = json_decode($payload, true);
                $type = $event['type'] ?? '';

                if ($type === 'chunk') {
                    $seenChunk = true;
                }

                if (in_array($type, ['done', 'error'], true)) {
                    break;
                }
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * Silence budget before the first token, derived from the active generation
     * driver unless pinned. The hosted path reaches the first token in seconds
     * (retrieval + a short prefill); the opt-in local-llm fallback adds ~90s of
     * llama.cpp prefill, so it needs a far larger window. Deriving keeps a
     * routine restart or deploy from stranding a hosted client for the
     * grpc-sized window — the failure a user actually hits, since neither a kill
     * nor a stop fires the job's failed() hook.
     */
    private function firstChunkTimeout(): int
    {
        $override = config('ai.stream.first_chunk_timeout');

        if ($override !== null) {
            return (int) $override;
        }

        return config('ai.generator') === 'grpc'
            ? (int) config('ai.stream.first_chunk_timeout_grpc')
            : (int) config('ai.stream.first_chunk_timeout_hosted');
    }

    /**
     * Push a terminal SSE `error` event so the browser's stream handler rejects
     * with a real message, rather than hanging or seeing a bare disconnect it
     * would surface as a generic "connection lost".
     */
    private function emitError(string $reason, string $message): void
    {
        echo 'data: '.json_encode(['type' => 'error', 'reason' => $reason, 'message' => $message])."\n\n";
        $this->flushOutput();
    }

    private function flushOutput(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }
}
