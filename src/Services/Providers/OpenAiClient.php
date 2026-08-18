<?php

namespace TwillAi\Services\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OpenAiClient extends AbstractProviderClient
{
    protected function label(): string
    {
        return 'OpenAI';
    }

    protected function send(string $key): Response
    {
        return Http::withToken($key)->timeout(15)->get('https://api.openai.com/v1/models');
    }

    protected function parse(Response $response): array
    {
        return collect($response->json('data', []))
            ->map(fn (array $model) => (string) ($model['id'] ?? ''))
            ->filter(fn (string $id) => $this->isChatModel($id))
            ->sort()
            ->map(fn (string $id) => ['id' => $id, 'label' => $id])
            ->values()
            ->all();
    }

    protected function isChatModel(string $id): bool
    {
        if ($id === '') {
            return false;
        }

        if (Str::contains($id, ['embedding', 'whisper', 'tts', 'dall-e', 'image', 'audio', 'moderation', 'realtime', 'transcribe', 'search', 'codex'])) {
            return false;
        }

        return Str::startsWith($id, ['gpt', 'chatgpt', 'o1', 'o3', 'o4']);
    }
}
