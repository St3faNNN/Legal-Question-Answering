<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversations\StoreConversationRequest;
use App\Http\Requests\Conversations\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Services\Conversation\ConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $service) {}

    public function index(Request $request): ResourceCollection
    {
        $conversations = $this->service->listForUser($request->user());

        return ConversationResource::collection($conversations);
    }

    /**
     * Create a conversation
     *
     * @group Conversations
     *
     * @authenticated
     *
     * @bodyParam title string optional Custom title for the conversation. Example: My New Chat
     *
     * @response 201 {"data":{"id":1,"title":"My New Chat","created_at":"...","updated_at":"..."}}
     */
    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = $this->service->createForUser($request->user(), $request->validated('title'));

        return (new ConversationResource($conversation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a conversation
     *
     * Returns a conversation with all its messages.
     *
     * @group Conversations
     *
     * @authenticated
     *
     * @response 200 {"data":{"id":1,"title":"My Chat","messages":[{"id":1,"role":"user","content":"Hello","created_at":"..."}]}}
     */
    public function show(Request $request, int $id): JsonResource
    {
        $conversation = $this->service->findForUser($request->user(), $id);
        $conversation->load('messages');

        return new ConversationResource($conversation);
    }

    /**
     * Rename a conversation
     *
     * @group Conversations
     *
     * @authenticated
     *
     * @bodyParam title string required The new title. Example: Renamed Chat
     *
     * @response 200 {"data":{"id":1,"title":"Renamed Chat","created_at":"...","updated_at":"..."}}
     */
    public function update(UpdateConversationRequest $request, int $id): JsonResource
    {
        $conversation = $this->service->findForUser($request->user(), $id);
        $conversation = $this->service->rename($conversation, $request->validated('title'));

        return new ConversationResource($conversation);
    }

    /**
     * Delete a conversation
     *
     * Soft-deletes the conversation. Messages are not soft-deleted; they are
     * removed by the database FK cascade only if the conversation is force-deleted.
     *
     * @group Conversations
     *
     * @authenticated
     *
     * @response 204
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $conversation = $this->service->findForUser($request->user(), $id);
        $this->service->delete($conversation);

        return response()->json(null, 204);
    }
}
