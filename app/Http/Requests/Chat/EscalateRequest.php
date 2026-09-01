<?php

namespace App\Http\Requests\Chat;

use App\Services\Chat\EscalationService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EscalateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ownership is not asserted here — the conversation is resolved through the
     * user's own relation in {@see EscalationService}, so a
     * conversation belonging to somebody else 404s rather than validating.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conversation_id' => ['required', 'integer'],
            'message_id' => ['required', 'integer'],
        ];
    }
}
