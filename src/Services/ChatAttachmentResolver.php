<?php

namespace TwillAi\Services;

use TwillAi\Models\ChatFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;

/**
 * Turns stored chat files into what the agent can actually consume:
 *
 * - Images and PDFs are sent natively as SDK attachments (Anthropic reads them
 *   directly).
 * - Text-based files (txt/md/csv, and docx via PhpWord) are flattened to text
 *   and injected into the prompt, which works regardless of provider document
 *   support.
 */
class ChatAttachmentResolver
{
    public function __construct(protected DocxTextExtractor $docx) {}

    /**
     * @param  iterable<ChatFile>  $files
     * @return array{attachments: array<int, File>, texts: array<int, array{name: string, text: string}>}
     */
    public function resolve(iterable $files): array
    {
        $attachments = [];
        $texts = [];

        foreach ($files as $file) {
            $mime = (string) $file->mime;

            if (str_starts_with($mime, 'image/')) {
                $attachments[] = Image::fromStorage($file->path, $file->disk);

                continue;
            }

            if ($mime === 'application/pdf' || $file->extension() === 'pdf') {
                $attachments[] = Document::fromStorage($file->path, $file->disk);

                continue;
            }

            $text = $this->extractText($file);

            if ($text !== '') {
                $texts[] = ['name' => $file->original_name, 'text' => $text];
            }
        }

        return ['attachments' => $attachments, 'texts' => $texts];
    }

    protected function extractText(ChatFile $file): string
    {
        $disk = Storage::disk($file->disk);

        if (! $disk->exists($file->path)) {
            return '';
        }

        $text = $file->extension() === 'docx'
            ? $this->docx->extract($disk->path($file->path))
            : (string) $disk->get($file->path);

        $text = trim($text);

        $max = (int) config('twill-ai.uploads.max_text_chars', 50000);

        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max)."\n\n[… file truncated at {$max} characters …]";
        }

        return $text;
    }
}
