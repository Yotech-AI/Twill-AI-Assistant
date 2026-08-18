<?php

namespace TwillAi\Services\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GeminiClient extends AbstractProviderClient
{
    protected function label(): string
    {
        return 'Google Gemini';
    }

    protected function send(string $key): Response
    {
        return Http::timeout(15)->get('https://generativelanguage.googleapis.com/v1beta/models', [
            'key' => $key,
            'pageSize' => 200,
        ]);
    }

    protected function parse(Response $response): array
    {
        return collect($response->json('models', []))
            ->filter(fn (array $model) => in_array('generateContent', $model['supportedGenerationMethods'] ?? [], true))
            ->map(function (array $model) {
                $id = preg_replace('#^models/#', '', (string) ($model['name'] ?? ''));

                return ['id' => $id, 'label' => $model['displayName'] ?? $id];
            })
            ->filter(fn (array $model) => $model['id'] !== '')
            ->values()
            ->all();
    }
}
