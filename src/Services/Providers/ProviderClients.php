<?php

namespace TwillAi\Services\Providers;

/**
 * Resolves the model-listing/validation client for a provider. Returns null for
 * providers we don't offer in the Settings UI (no adapter).
 */
class ProviderClients
{
    public static function for(string $provider): ?ProviderClient
    {
        return match ($provider) {
            'anthropic' => new AnthropicClient,
            'openai' => new OpenAiClient,
            'gemini' => new GeminiClient,
            'mistral' => new MistralClient,
            default => null,
        };
    }
}
