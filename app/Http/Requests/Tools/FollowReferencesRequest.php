<?php

namespace App\Http\Requests\Tools;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class FollowReferencesRequest extends FormRequest
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
            // `law` is a short document code (e.g. "ZAKON-ZA-JAVNITE"); `article`
            // is an article number, kept as a string to allow suffixes ("5-а").
            'law' => ['required', 'string', 'min:1', 'max:200'],
            'article' => ['required', 'string', 'min:1', 'max:20'],
        ];
    }
}
