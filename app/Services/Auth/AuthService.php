<?php

namespace App\Services\Auth;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /**
     * Register a new user and authenticate them via the session.
     *
     * Registration is invitation-only. The invitation is looked up under a row
     * lock and marked used in the same transaction as the account creation, so
     * a single-use code cannot be redeemed twice by concurrent requests.
     *
     * @throws ValidationException when the invitation code is invalid, expired,
     *                             already used, or bound to a different email
     */
    public function register(array $attributes, Request $request): User
    {
        $user = DB::transaction(function () use ($attributes, $request): User {
            $invitation = Invitation::where('code', $attributes['invite_code'])
                ->lockForUpdate()
                ->first();

            if ($invitation === null || ! $invitation->isRedeemable($attributes['email'])) {
                throw ValidationException::withMessages([
                    'invite_code' => ['This invitation code is invalid, expired, or has already been used.'],
                ]);
            }

            $user = User::create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
            ]);

            $invitation->forceFill([
                'used_at' => now(),
                'used_by_user_id' => $user->id,
            ])->save();

            $this->recordTermsAcceptance($user, $request->ip());

            return $user;
        });

        Auth::guard('web')->login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return $user;
    }

    /**
     * Record the user's acceptance of each required legal document at its
     * current server-side version (from config/legal.php, never from the
     * client), with a timestamp and the request IP.
     */
    private function recordTermsAcceptance(User $user, ?string $ip): void
    {
        $now = now();

        foreach ((array) config('legal.acceptance_required', []) as $document) {
            $version = config("legal.documents.{$document}.version");

            if ($version === null) {
                continue;
            }

            $user->termsAcceptances()->create([
                'document' => $document,
                'version' => $version,
                'ip_address' => $ip,
                'accepted_at' => $now,
            ]);
        }
    }

    /**
     * Attempt to authenticate a user using session-based cookie auth.
     *
     * @throws ValidationException
     */
    public function login(Request $request): User
    {
        $credentials = $request->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = Auth::guard('web')->user();

        $user->update(['last_active_at' => now()]);

        return $user;
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
