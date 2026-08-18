<?php

namespace TwillAi\Tools;

use A17\Twill\Models\Media;
use A17\Twill\Services\MediaLibrary\ImageService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class SearchMedia implements Tool
{
    use HandlesToolErrors;

    public function name(): string
    {
        return 'search_media';
    }

    public function description(): Stringable|string
    {
        return 'Search the Twill media library for existing images by alt text, caption or filename. Returns media ids to use in "medias" payload sections. You can only pick existing images — you cannot upload or generate new ones.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $query = $request->offsetExists('query') ? trim((string) $request['query']) : '';
            $page = max(1, (int) ($request->offsetExists('page') ? $request['page'] : 1));
            $perPage = 12;

            $builder = Media::query()->orderByDesc('id');

            if ($query !== '') {
                $builder->where(function ($q) use ($query) {
                    $q->where('alt_text', 'like', "%{$query}%")
                        ->orWhere('caption', 'like', "%{$query}%")
                        ->orWhere('filename', 'like', "%{$query}%");
                });
            }

            $total = (clone $builder)->count();

            $items = $builder->forPage($page, $perPage)->get()->map(function (Media $media) {
                try {
                    $thumbnail = ImageService::getCmsUrl($media->uuid, ['h' => 150]);
                } catch (Throwable) {
                    $thumbnail = null;
                }

                return [
                    'id' => $media->id,
                    'alt_text' => $media->alt_text,
                    'caption' => $media->caption,
                    'filename' => $media->filename,
                    'width' => $media->width,
                    'height' => $media->height,
                    'thumbnail' => $thumbnail,
                ];
            })->all();

            return [
                'page' => $page,
                'total' => $total,
                'results' => $items,
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search terms (matched against alt text, caption and filename). Omit to browse the latest uploads.'),
            'page' => $schema->integer()->description('Page number, 12 results per page.'),
        ];
    }
}
