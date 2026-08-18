<?php

namespace TwillAi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('twill_users')->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(array_keys((array) config('twill-ai.providers', [])))],
            'key' => ['required', 'string', 'max:400'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provider.in' => 'That provider is not available.',
            'key.required' => 'Enter an API key.',
        ];
    }
}
