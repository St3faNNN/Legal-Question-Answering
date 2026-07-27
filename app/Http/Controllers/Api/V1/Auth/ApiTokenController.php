<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\StoreTokenRequest;
use App\Http\Resources\TokenResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

class ApiTokenController extends Controller
{
    /**
     * Create an API token
     *
     * Issues a personal access token for programmatic API access.
     *
     * @group API Tokens
     *
     * @authenticated
     *
     * @bodyParam name string required A name for the token. Example: My Mobile App
     * @bodyParam abilities string[] optional Token abilities (defaults to all). Example: ["read","write"]
     *
     * @response 201 {"token":"1|abcdef123456","name":"My Mobile App"}
     */
    public function store(StoreTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $token = $request->user()->createToken(
            $validated['name'],
            $validated['abilities'] ?? ['*'],
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'name' => $validated['name'],
        ], 201);
    }

    /**
     * List API tokens
     *
     * Returns the authenticated user's personal access tokens (paginated).
     *
     * @group API Tokens
     *
     * @authenticated
     *
     * @response 200 {"data":[{"id":1,"name":"My App","abilities":["*"],"created_at":"..."}],"meta":{"current_page":1,"per_page":15,"total":1}}
     */
    public function index(Request $request): ResourceCollection
    {
        $tokens = $request->user()->tokens()
            ->orderByDesc('created_at')
            ->paginate(15);

        return TokenResource::collection($tokens);
    }

    /**
     * Revoke an API token
     *
     * Deletes a personal access token by ID.
     *
     * @group API Tokens
     *
     * @authenticated
     *
     * @response 204
     * @response 404 {"message":"Token not found."}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $deleted = $request->user()->tokens()->where('id', $id)->delete();

        if (! $deleted) {
            return response()->json(['message' => 'Token not found.'], 404);
        }

        return response()->json(null, 204);
    }
}
