<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterUserRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    /**
     * Register a new user
     *
     * Creates a new user account and authenticates them via session cookie.
     *
     * @group Authentication
     *
     * @bodyParam name string required The user's display name. Example: John Doe
     * @bodyParam email string required The user's email address. Example: john@example.com
     * @bodyParam password string required Minimum 8 characters. Example: password123
     * @bodyParam password_confirmation string required Must match password. Example: password123
     *
     * @response 201 {"data":{"id":1,"name":"John Doe","email":"john@example.com","bio":null,"created_at":"2026-07-16T14:00:00.000000Z","updated_at":"2026-07-16T14:00:00.000000Z","last_active_at":null}}
     * @response 422 {"message":"The email has already been taken.","errors":{"email":["The email has already been taken."]}}
     */
    public function register(RegisterUserRequest $request): JsonResponse
    {
        $user = $this->auth->register($request->validated(), $request);

        return (new UserResource($user))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Log in
     *
     * Authenticates a user via session-based cookie auth (SPA).
     *
     * @group Authentication
     *
     * @bodyParam email string required Example: john@example.com
     * @bodyParam password string required Example: password123
     *
     * @response 200 {"data":{"id":1,"name":"John Doe","email":"john@example.com"}}
     * @response 422 {"message":"The provided credentials are incorrect.","errors":{"email":["The provided credentials are incorrect."]}}
     */
    public function login(LoginRequest $request): JsonResource
    {
        $user = $this->auth->login($request);

        return new UserResource($user);
    }

    /**
     * Log out
     *
     * Invalidates the current session.
     *
     * @group Authentication
     *
     * @authenticated
     *
     * @response 204
     */
    public function logout(Request $request): JsonResponse
    {
        $this->auth->logout($request);

        return response()->json(null, 204);
    }

    /**
     * Get current user
     *
     * Returns the authenticated user's profile.
     *
     * @group User
     *
     * @authenticated
     *
     * @response 200 {"data":{"id":1,"name":"John Doe","email":"john@example.com","bio":null}}
     */
    public function show(Request $request): JsonResource
    {
        return new UserResource($request->user());
    }
}
