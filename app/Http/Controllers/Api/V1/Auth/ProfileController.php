<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileController extends Controller
{
    /**
     * Update profile
     *
     * Updates the authenticated user's name and/or bio.
     *
     * @group User
     *
     * @authenticated
     *
     * @bodyParam name string optional New display name. Example: Jane Doe
     * @bodyParam bio string optional nullable User biography (max 2000 chars). Example: I love building things.
     *
     * @response 200 {"data":{"id":1,"name":"Jane Doe","email":"jane@example.com","bio":"I love building things."}}
     */
    public function update(UpdateProfileRequest $request): JsonResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return new UserResource($user);
    }
}
