<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'theme' => ['sometimes', 'string', 'in:light,dark,system'],
            'language' => ['sometimes', 'string', 'min:2', 'max:10'],
            'notifications' => ['sometimes', 'boolean'],
            'default_model' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
