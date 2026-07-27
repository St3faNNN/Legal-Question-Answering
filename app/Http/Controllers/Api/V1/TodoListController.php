<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TodoLists\StoreTodoListRequest;
use App\Http\Requests\TodoLists\UpdateTodoListRequest;
use App\Http\Resources\TodoListResource;
use App\Services\TodoList\TodoListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

class TodoListController extends Controller
{
    public function __construct(private readonly TodoListService $service) {}

    public function index(Request $request): ResourceCollection
    {
        $todoLists = $this->service->listForUser($request->user());

        return TodoListResource::collection($todoLists);
    }

    /**
     * Create a to-do list
     *
     * @group To-Do Lists
     *
     * @authenticated
     *
     * @bodyParam title string required The list title. Example: Launch checklist
     * @bodyParam due_at string optional When the list is due (ISO 8601). Example: 2026-08-01T17:00:00Z
     *
     * @response 201 {"data":{"id":1,"title":"Launch checklist","due_at":"2026-08-01T17:00:00Z","items":[]}}
     */
    public function store(StoreTodoListRequest $request): JsonResponse
    {
        $todoList = $this->service->createForUser($request->user(), $request->validated());

        return (new TodoListResource($todoList))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Show a to-do list
     *
     * Returns a to-do list with all of its items.
     *
     * @group To-Do Lists
     *
     * @authenticated
     *
     * @response 200 {"data":{"id":1,"title":"Launch checklist","items":[{"id":1,"title":"Write docs","is_done":false}]}}
     */
    public function show(Request $request, int $id): JsonResource
    {
        $todoList = $this->service->findForUser($request->user(), $id);

        return new TodoListResource($todoList);
    }

    /**
     * Update a to-do list
     *
     * @group To-Do Lists
     *
     * @authenticated
     *
     * @bodyParam title string optional The new title. Example: Renamed checklist
     * @bodyParam due_at string optional When the list is due (ISO 8601). Example: 2026-08-02T17:00:00Z
     *
     * @response 200 {"data":{"id":1,"title":"Renamed checklist","due_at":"2026-08-02T17:00:00Z"}}
     */
    public function update(UpdateTodoListRequest $request, int $id): JsonResource
    {
        $todoList = $this->service->findForUser($request->user(), $id);
        $todoList = $this->service->update($todoList, $request->validated());

        return new TodoListResource($todoList);
    }

    /**
     * Delete a to-do list
     *
     * Soft-deletes the list. Items are removed by the database FK cascade only
     * if the list is force-deleted.
     *
     * @group To-Do Lists
     *
     * @authenticated
     *
     * @response 204
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $todoList = $this->service->findForUser($request->user(), $id);
        $this->service->delete($todoList);

        return response()->json(null, 204);
    }
}
