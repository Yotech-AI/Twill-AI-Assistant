<?php

namespace TwillAi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'default_model' => ['sometimes', 'nullable', 'string', 'max:191'],
            'system_prompt' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }
}
