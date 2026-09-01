<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AgentRunResource;
use App\Http\Resources\MessageResource;
use App\Models\Message;
use App\Services\Chat\ChatService;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class MessageController extends Controller
{
    public function __construct(
        private readonly ChatService $service,
        private readonly ConversationService $conversations,
    ) {}

    /**
     * List messages
     *
     * Returns all messages in a conversation, cursor-paginated by creation time.
     *
     * @group Messages
     *
     * @authenticated
     *
     * @response 200 {"data":[{"id":1,"role":"user","content":"Hello","created_at":"..."}],"meta":{"next_cursor":null,"per_page":20}}
     */
    public function index(Request $request, int $id): ResourceCollection
    {
        $conversation = $this->conversations->findForUser($request->user(), $id);

        $messages = $this->service->listMessages($conversation);

        return MessageResource::collection($messages);
    }

    /**
     * Get an escalation's trace
     *
     * Returns the agent trajectory behind an assistant message: each step's tool
     * calls, the query issued, and the citations it returned — the same shape the
     * CLI trace inspector renders, so a run can be read after the fact instead of
     * only while it streams.
     *
     * 404 for a pipeline answer, which has no trajectory, and for a message in
     * somebody else's conversation.
     *
     * @group Messages
     *
     * @authenticated
     *
     * @response 200 {"data":{"run_id":"20260829-124011-gbwlbb","outcome":"answered","totals":{"steps":4,"api_calls":4,"total_tokens":25397,"duration_ms":7565},"steps":[{"index":0,"calls":[{"tool":"search_law","query":"технолошки вишок отказ испратнина","sources":["ZAKON-ZA-RABOTNI, чл. 88"]}]}],"passages":[]}}
     */
    public function trace(Request $request, int $conversationId, int $messageId): AgentRunResource
    {
        $conversation = $this->conversations->findForUser($request->user(), $conversationId);

        /** @var Message $message */
        $message = $conversation->messages()->findOrFail($messageId);

        return new AgentRunResource($message->agentRun()->firstOrFail());
    }
}
