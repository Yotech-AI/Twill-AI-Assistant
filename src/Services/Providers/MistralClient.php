<?php

namespace TwillAi\Services\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MistralClient extends AbstractProviderClient
{
    protected function label(): string
    {
        return 'Mistral';
    }

    protected function send(string $key): Response
    {
        return Http::withToken($key)->timeout(15)->get('https://api.mistral.ai/v1/models');
    }

    protected function parse(Response $response): array
    {
        return collect($response->json('data', []))
            ->map(fn (array $model) => (string) ($model['id'] ?? ''))
            ->filter(fn (string $id) => $id !== '' && ! Str::contains($id, ['embed', 'ocr', 'moderation']))
            ->unique()
            ->sort()
            ->map(fn (string $id) => ['id' => $id, 'label' => $id])
            ->values()
            ->all();
    }
}
