<?php

use App\Http\Controllers\Api\V1\Auth\ApiTokenController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NewsController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TodoItemController;
use App\Http\Controllers\Api\V1\TodoListController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')->group(function (): void {
    // Auth - cookie-based SPA sessions
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware(['throttle:register']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware(['throttle:login']);
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

    // Current user + profile
    Route::get('/user', [AuthController::class, 'show'])->middleware('auth:sanctum');
    Route::patch('/profile', [ProfileController::class, 'update'])->middleware('auth:sanctum');

    // API tokens - programmatic access
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/tokens', [ApiTokenController::class, 'store']);
        Route::get('/tokens', [ApiTokenController::class, 'index']);
        Route::delete('/tokens/{id}', [ApiTokenController::class, 'destroy']);
    });

    // Settings - user preferences
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'show']);
        Route::put('/settings', [SettingsController::class, 'update']);
    });

    // Conversations - CRUD
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{id}', [ConversationController::class, 'show']);
        Route::patch('/conversations/{id}', [ConversationController::class, 'update']);
        Route::delete('/conversations/{id}', [ConversationController::class, 'destroy']);
    });

    // Chat + messages
    Route::middleware(['auth:sanctum', 'throttle:chat'])->group(function (): void {
        Route::post('/chat', [ChatController::class, 'send']);
        Route::post('/chat/stream', [ChatController::class, 'stream']);
        Route::get('/conversations/{id}/messages', [MessageController::class, 'index']);
    });

    // SSE relay for a streamed reply. Auth only (no chat throttle): it is a
    // long-lived relay, and EventSource authenticates via the session cookie.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/chat/stream/{streamId}', [ChatController::class, 'streamEvents']);
    });

    // To-do lists + items
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/todo-lists', [TodoListController::class, 'index']);
        Route::post('/todo-lists', [TodoListController::class, 'store']);
        Route::get('/todo-lists/{id}', [TodoListController::class, 'show']);
        Route::patch('/todo-lists/{id}', [TodoListController::class, 'update']);
        Route::delete('/todo-lists/{id}', [TodoListController::class, 'destroy']);

        Route::post('/todo-lists/{id}/items', [TodoItemController::class, 'store']);
        Route::patch('/todo-items/{id}', [TodoItemController::class, 'update']);
        Route::delete('/todo-items/{id}', [TodoItemController::class, 'destroy']);
    });

    // Newspaper section — latest scraped konkursi (read-only).
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/news', [NewsController::class, 'index']);
    });

    // Namespace reservation for future: /api-keys
});
