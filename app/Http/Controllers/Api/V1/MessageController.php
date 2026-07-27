<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
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
}
