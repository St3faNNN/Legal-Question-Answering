<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\TodoLists\StoreTodoItemRequest;
use App\Http\Requests\TodoLists\UpdateTodoItemRequest;
use App\Http\Resources\TodoItemResource;
use App\Services\TodoList\TodoListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TodoItemController extends Controller
{
    public function __construct(private readonly TodoListService $service) {}

    /**
     * Add an item to a to-do list
     *
     * @group To-Do Lists
     *
     * @authenticated
     *
     * @bodyParam title string required The item title. Example: Write the docs
     * @bodyParam description string optional A short explanation of the item. Example: Cover the public API endpoints
     *
     * @response 201 {"data":{"id":1,"todo_list_id":1,"title":"Write the docs","description":"Cover the public API endpoints","is_done":false}}
     */
    public function store(StoreTodoItemRequest $request, int $todoListId): JsonResponse
    {
        $todoList = $this->service->findForUser($request->user(), $todoListId);
        $item = $this->service->addItem($todoList, $request->validated());

        return (new TodoItemResource($item))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update a to-do item
     *
     * Used to rename an item, edit its explanation, or toggle its checkbox.
     *
     * @group To-Do Lists
     *
     * @authenticated
     *
     * @bodyParam title string optional The new item title. Example: Write the docs
     * @bodyParam description string optional A short explanation of the item. Example: Cover the public API endpoints
     * @bodyParam is_done boolean optional Whether the item is checked off. Example: true
     *
     * @response 200 {"data":{"id":1,"todo_list_id":1,"title":"Write the docs","is_done":true}}
     */
    public function update(UpdateTodoItemRequest $request, int $itemId): JsonResource
    {
        $item = $this->service->findItemForUser($request->user(), $itemId);
        $item = $this->service->updateItem($item, $request->validated());

        return new TodoItemResource($item);
    }

    /**
     * Delete a to-do item
     *
     * @group To-Do Lists
     *
     * @authenticated
     *
     * @response 204
     */
    public function destroy(Request $request, int $itemId): JsonResponse
    {
        $item = $this->service->findItemForUser($request->user(), $itemId);
        $this->service->deleteItem($item);

        return response()->json(null, 204);
    }
}
