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
            // The assistant answer the control was clicked on.
            'message_id' => ['required', 'integer'],
            // The user message that answer responded to. REQUIRED, and with no
            // server-side fallback on purpose. It used to be derived by walking
            // back to "the nearest preceding user message", which resolved
            // through a relation ordered by created_at and returned the oldest
            // question in the conversation instead of the escalated one. The
            // client renders the thread and therefore knows which question the
            // user escalated; the server validates that claim rather than
            // re-guessing it.
            'question_message_id' => ['required', 'integer'],
        ];
    }
}
