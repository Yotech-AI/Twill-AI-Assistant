<?php

namespace TwillAi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadFileRequest extends FormRequest
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
        $maxKb = (int) config('twill-ai.uploads.max_kb', 20480);
        $maxFiles = (int) config('twill-ai.uploads.max_files_per_message', 5);
        $extensions = implode(',', config('twill-ai.uploads.extensions', []));

        return [
            'files' => ['required', 'array', 'max:'.$maxFiles],
            'files.*' => ['required', 'file', 'max:'.$maxKb, 'extensions:'.$extensions],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = round(((int) config('twill-ai.uploads.max_kb', 20480)) / 1024);

        return [
            'files.*.max' => "Each file must be smaller than {$maxMb} MB.",
            'files.*.extensions' => 'That file type is not allowed.',
            'files.*.file' => 'The upload was not a valid file.',
        ];
    }
}
