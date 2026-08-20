<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DeleteAccountRequest;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Data-subject rights: a user can export everything the system holds about them
 * and can genuinely delete their account.
 */
class AccountController extends Controller
{
    /**
     * Export the authenticated user's data
     *
     * Returns a portable JSON document with the account, its terms acceptances,
     * and every conversation and message (including soft-deleted conversations,
     * which are still stored).
     *
     * @group Account
     *
     * @authenticated
     */
    public function export(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->load([
            'termsAcceptances',
            // Include soft-deleted conversations: they are still stored, so a
            // complete export must contain them.
            'conversations' => fn ($query) => $query->withTrashed()->with('messages'),
        ]);

        $data = [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'bio' => $user->bio,
                'last_active_at' => optional($user->last_active_at)->toIso8601String(),
                'created_at' => optional($user->created_at)->toIso8601String(),
                'updated_at' => optional($user->updated_at)->toIso8601String(),
            ],
            'terms_acceptances' => $user->termsAcceptances->map(fn ($acceptance) => [
                'document' => $acceptance->document,
                'version' => $acceptance->version,
                'ip_address' => $acceptance->ip_address,
                'accepted_at' => optional($acceptance->accepted_at)->toIso8601String(),
            ])->all(),
            'conversations' => $user->conversations->map(fn ($conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'created_at' => optional($conversation->created_at)->toIso8601String(),
                'updated_at' => optional($conversation->updated_at)->toIso8601String(),
                'deleted_at' => optional($conversation->deleted_at)->toIso8601String(),
                'messages' => $conversation->messages->map(fn ($message) => [
                    'role' => $message->role,
                    'content' => $message->content,
                    'model' => $message->model,
                    'tokens' => $message->tokens,
                    'latency_ms' => $message->latency_ms,
                    'metadata' => $message->metadata,
                    'created_at' => optional($message->created_at)->toIso8601String(),
                ])->all(),
            ])->all(),
        ];

        $filename = 'my-data-'.now()->format('Y-m-d').'.json';

        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    /**
     * Delete the authenticated user's account
     *
     * Permanently and irreversibly deletes the account and all associated data
     * (conversations, messages, terms acceptances). Confirmed with the current
     * password.
     *
     * @group Account
     *
     * @authenticated
     */
    public function destroy(DeleteAccountRequest $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check((string) $request->input('password'), $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The password is incorrect.'],
            ]);
        }

        // Log out BEFORE deleting. The session guard's logout() cycles the
        // remember token and saves the user model — if that runs after the row
        // is deleted, the save re-inserts it (the model's "exists" is now false).
        Auth::guard('web')->logout();

        // Tear down the current session so the auth cookie is invalidated. Guarded
        // because a stateless request (e.g. token auth) may have no session store.
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        DB::transaction(function () use ($user): void {
            // Genuine, hard erasure of everything belonging to the user. Done
            // explicitly (child rows first) rather than relying on database
            // cascade, so deletion is unambiguous and driver-independent — and
            // so soft-deleted conversations are force-deleted, not left behind.
            $conversationIds = $user->conversations()->withTrashed()->pluck('id');
            Message::whereIn('conversation_id', $conversationIds)->delete();
            $user->conversations()->withTrashed()->forceDelete();

            $user->termsAcceptances()->delete();

            // sessions.user_id has no foreign key, so clear it explicitly too.
            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete();
        });

        return response()->noContent();
    }
}
