<?php

namespace TwillAi\Services\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AnthropicClient extends AbstractProviderClient
{
    protected function label(): string
    {
        return 'Anthropic';
    }

    protected function send(string $key): Response
    {
        return Http::withHeaders([
            'x-api-key' => $key,
            'anthropic-version' => '2023-06-01',
        ])->timeout(15)->get('https://api.anthropic.com/v1/models', ['limit' => 100]);
    }

    protected function parse(Response $response): array
    {
        return collect($response->json('data', []))
            ->map(fn (array $model) => [
                'id' => $model['id'],
                'label' => $model['display_name'] ?? $model['id'],
            ])
            ->values()
            ->all();
    }
}
