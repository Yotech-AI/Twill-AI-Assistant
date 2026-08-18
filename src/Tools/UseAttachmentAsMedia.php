<?php

namespace TwillAi\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Models\ChatFile;
use TwillAi\Services\MediaIngestionService;
use TwillAi\Tools\Concerns\HandlesToolErrors;

/**
 * Adds an image the user attached to the chat (identified by the file_id listed
 * with the attached images) to the Twill media library, and returns a media_id
 * the agent can drop into a block media role or the SEO image "medias" payload.
 */
class UseAttachmentAsMedia implements Tool
{
    use HandlesToolErrors;

    public function __construct(protected MediaIngestionService $media) {}

    public function name(): string
    {
        return 'use_attachment_as_media';
    }

    public function description(): Stringable|string
    {
        return 'Add an attached image to the Twill media library and get back a media_id to use in a block media role or the SEO image. Pass the file_id shown with the attached images. Images only. Calling it again for the same file returns the same media_id.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $fileId = (int) ($request->offsetExists('file_id') ? $request['file_id'] : 0);

            if ($fileId <= 0) {
                throw new TwillAiException('A "file_id" (from the attached images list) is required.');
            }

            $file = ChatFile::find($fileId);

            if (! $file) {
                throw new TwillAiException("There is no attached file with id {$fileId}.");
            }

            $alt = $request->offsetExists('alt_text') ? trim((string) $request['alt_text']) : null;
            $caption = $request->offsetExists('caption') ? trim((string) $request['caption']) : null;

            return $this->media->ingest($file, $alt ?: null, $caption ?: null);
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->integer()->description('The file_id of an attached image (listed with the attached images in the prompt).'),
            'alt_text' => $schema->string()->description('Optional alt text for the image.'),
            'caption' => $schema->string()->description('Optional caption for the image.'),
        ];
    }
}
