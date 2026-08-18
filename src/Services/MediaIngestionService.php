<?php

namespace TwillAi\Services;

use A17\Twill\Models\Media;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Models\ChatFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies an image from the private Twill AI library onto Twill's media-library
 * disk and creates the matching Media record, so the agent can reference it in
 * a block media role or the SEO image.
 *
 * Mirrors Twill's own MediaLibraryController::storeFile() exactly — uuid is
 * "{uuid-folder}/{sanitized filename}" on config('twill.media_library.disk') —
 * so ingested media are indistinguishable from normally uploaded ones. The
 * media disk may be remote (S3/Azure), so dimensions are read from the bytes in
 * memory rather than a local path.
 */
class MediaIngestionService
{
    /**
     * @return array{media_id: int, width: int, height: int, filename: string}
     */
    public function ingest(ChatFile $file, ?string $altText = null, ?string $caption = null): array
    {
        if (! $file->isImage()) {
            throw new TwillAiException('Only images can be added to the media library (got "'.($file->mime ?: $file->extension() ?: 'unknown').'"). Use search_media for existing images instead.');
        }

        // Idempotent: a given upload is ingested once. Re-use the existing media.
        if ($file->media_id && $media = Media::find($file->media_id)) {
            return $this->payload($media);
        }

        $source = Storage::disk($file->disk);

        if (! $source->exists($file->path)) {
            throw new TwillAiException('That file is no longer available on the server.');
        }

        $bytes = (string) $source->get($file->path);

        $originalFilename = $file->original_name;
        $uuid = Str::uuid().'/'.sanitizeFilename($originalFilename);

        if (config('twill.media_library.prefix_uuid_with_local_path', false)) {
            $uuid = trim((string) config('twill.media_library.local_path'), '/ ').'/'.$uuid;
        }

        Storage::disk(config('twill.media_library.disk', 'twill_media_library'))->put($uuid, $bytes);

        $dimensions = @getimagesizefromstring($bytes);

        $media = Media::create([
            'uuid' => $uuid,
            'filename' => $originalFilename,
            'width' => (int) ($dimensions[0] ?? 0),
            'height' => (int) ($dimensions[1] ?? 0),
            'alt_text' => $altText,
            'caption' => $caption,
        ]);

        $file->forceFill(['media_id' => $media->id])->save();

        return $this->payload($media);
    }

    /**
     * @return array{media_id: int, width: int, height: int, filename: string}
     */
    protected function payload(Media $media): array
    {
        return [
            'media_id' => $media->id,
            'width' => (int) $media->width,
            'height' => (int) $media->height,
            'filename' => $media->filename,
        ];
    }
}
