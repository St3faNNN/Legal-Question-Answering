<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegisterUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            // Open signup is closed: an account can only be created by redeeming
            // an invitation code. Presence is validated here; the authoritative
            // (and race-safe) redeem happens in AuthService::register.
            'invite_code' => ['required', 'string'],
            // Explicit, required acceptance of the Terms of Use and Privacy
            // Policy. The accepted versions are recorded server-side (from
            // config/legal.php) — see AuthService::register.
            'accept_terms' => ['accepted'],
        ];
    }
}
