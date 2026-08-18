<?php

namespace TwillAi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
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
            'message' => ['nullable', 'required_without_all:attachments,mentions', 'string', 'max:20000'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('twill-ai.uploads.max_files_per_message', 5)],
            'attachments.*' => ['integer'],
            'mentions' => ['sometimes', 'array', 'max:50'],
            'mentions.*.module' => ['required_with:mentions', 'string', 'max:100'],
            'mentions.*.id' => ['required_with:mentions', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.required_without_all' => 'Please type a message or attach a file first.',
            'message.max' => 'That message is too long (max 20,000 characters).',
        ];
    }
}
