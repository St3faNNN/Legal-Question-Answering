<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Jobs\StreamChatReply;
use App\Services\Chat\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    public function __construct(private readonly ChatService $service) {}

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
     * Stream reply events (SSE)
     *
     * Server-Sent Events relay: drains the stream's Redis list and forwards
     * `chunk`, `done`, and `error` events to the browser. Authenticated via the
     * same-origin session cookie (EventSource cannot send bearer headers).
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

        return response()->stream(function () use ($key): void {
            $redis = Redis::connection();
            $idleLoops = 0;

            while (! connection_aborted()) {
                // Block up to 15s for the next event; a Redis list buffers
                // chunks produced before the browser connected (pub/sub would
                // drop them).
                $item = $redis->blpop([$key], 15);

                if ($item === null) {
                    echo ": keepalive\n\n";
                    $this->flushOutput();

                    if (++$idleLoops >= 8) {
                        break; // ~2 min with no events — give up
                    }

                    continue;
                }

                $idleLoops = 0;
                $payload = $item[1];
                echo "data: {$payload}\n\n";
                $this->flushOutput();

                $event = json_decode($payload, true);
                if (in_array($event['type'] ?? '', ['done', 'error'], true)) {
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

    private function flushOutput(): void
    {
        if (function_exists('ob_flush')) {
            @ob_flush();
        }

        flush();
    }
}
