<?php

use App\Http\Controllers\Api\V1\Admin\ExchangeController;
use App\Http\Controllers\Api\V1\Admin\MetricsController;
use App\Http\Controllers\Api\V1\Auth\AccountController;
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
use App\Http\Controllers\Api\V1\ToolController;
use App\Http\Middleware\EnsureUserIsAdmin;
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

    // Data-subject rights: export own data, delete own account.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/account/export', [AccountController::class, 'export']);
        Route::delete('/account', [AccountController::class, 'destroy']);
    });

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
        Route::post('/chat/stream', [ChatController::class, 'stream']);
        Route::get('/conversations/{id}/messages', [MessageController::class, 'index']);
    });

    // Escalation to the research agent. Its own throttle, far tighter than the
    // chat one: a run costs several times a normal answer, so the per-minute
    // burst limit is sized to that, on top of the shared daily unit budget the
    // service charges (config/agent.php).
    Route::middleware(['auth:sanctum', 'throttle:escalate'])->group(function (): void {
        Route::post('/chat/escalate', [ChatController::class, 'escalate']);
    });

    // The trajectory behind one escalated answer, so a run is inspectable after
    // the fact rather than only while it streams.
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/conversations/{conversationId}/messages/{messageId}/trace', [MessageController::class, 'trace']);
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

    // Agent tools — the retrieval pipeline as a callable tool. Its own throttle
    // (retrieval-only, no generation) keeps agent searches off the chat budget.
    Route::middleware(['auth:sanctum', 'throttle:tools'])->group(function (): void {
        Route::post('/tools/search-law', [ToolController::class, 'searchLaw']);
        Route::post('/tools/follow-references', [ToolController::class, 'followReferences']);
    });

    // Operational dashboard. Read-only aggregation over the durable tables.
    //
    // The gate is server-side and 404s a non-allowlisted caller, so the panel's
    // existence is not advertised (see EnsureUserIsAdmin). It is applied by class
    // name rather than by a middleware alias because aliases are registered in
    // bootstrap/app.php, which the Docker deploy does not mount — referencing the
    // class keeps the whole gate inside the mounted tree.
    //
    // There is no server-rendered admin page to gate: routes/web.php serves one
    // Blade shell for every path and the SPA routes client-side. These endpoints
    // are therefore the entire boundary, and the client route shows nothing
    // without them.
    Route::middleware(['auth:sanctum', EnsureUserIsAdmin::class])->prefix('admin')->group(function (): void {
        Route::get('/metrics', [MetricsController::class, 'overview']);
        // Metadata only — no question or answer text. Reading the text is the
        // separate, explicit request below.
        Route::get('/exchanges', [ExchangeController::class, 'index']);
        Route::get('/exchanges/{id}', [ExchangeController::class, 'show']);
        Route::get('/agent-runs/{id}/trace', [ExchangeController::class, 'trace']);
    });

    // Namespace reservation for future: /api-keys
});
