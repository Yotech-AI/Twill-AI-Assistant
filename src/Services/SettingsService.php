<?php

namespace TwillAi\Services;

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Models\TwillAiSetting;
use TwillAi\Services\Providers\ProviderClients;
use Throwable;

/**
 * Orchestrates the install-wide Twill AI settings: validating + storing the
 * provider API key, fetching the provider's model list, and exposing the
 * active model catalog / default model to the chat. The decrypted key never
 * leaves the server — forDisplay() returns only a masked form.
 */
class SettingsService
{
    public function settings(): TwillAiSetting
    {
        return TwillAiSetting::current();
    }

    public function isConfigured(): bool
    {
        return $this->settings()->isConfigured();
    }

    /**
     * Safe payload for the Settings UI (never includes the raw key). Lazily
     * refreshes the model list when it is older than a week.
     *
     * @return array<string, mixed>
     */
    public function forDisplay(): array
    {
        $settings = $this->settings();

        if ($settings->isConfigured()
            && $settings->models_fetched_at
            && $settings->models_fetched_at->lt(now()->subDays(7))) {
            try {
                $this->refreshModels();
                $settings = $settings->fresh();
            } catch (Throwable) {
                // Keep the last good list if the refresh fails.
            }
        }

        return [
            'providers' => (array) config('twill-ai.providers', []),
            'provider' => $settings->provider,
            'has_key' => filled($settings->api_key),
            'key_masked' => $settings->maskedKey(),
            'verified' => $settings->isConfigured(),
            'default_model' => $settings->default_model,
            'system_prompt' => $settings->system_prompt,
            'available_models' => $settings->available_models ?? [],
            'models_fetched_at' => $settings->models_fetched_at?->toIso8601String(),
        ];
    }

    /**
     * Validate a key against the provider (a models call) and, only on success,
     * store it encrypted with the fetched model list.
     *
     * @return array<string, mixed>
     */
    public function saveApiKey(string $provider, string $key): array
    {
        if (! array_key_exists($provider, (array) config('twill-ai.providers', []))) {
            throw new TwillAiException('Unknown provider.');
        }

        $client = ProviderClients::for($provider);

        if (! $client) {
            throw new TwillAiException('That provider is not supported yet.');
        }

        $models = $client->listModels($key); // throws on an invalid key

        $settings = $this->settings();
        $settings->provider = $provider;
        $settings->api_key = $key;
        $settings->key_last_four = substr($key, -4);
        $settings->verified_at = now();
        $settings->available_models = $models;
        $settings->models_fetched_at = now();

        if (! $settings->default_model || ! collect($models)->contains('id', $settings->default_model)) {
            $settings->default_model = $this->preferredDefault($provider, $models);
        }

        $settings->save();

        return $this->forDisplay();
    }

    /**
     * @return array<string, mixed>
     */
    public function updateSettings(?string $defaultModel, ?string $systemPrompt): array
    {
        $settings = $this->settings();

        if (! $settings->isConfigured()) {
            throw new TwillAiException('Save and verify an API key first.');
        }

        if ($defaultModel !== null) {
            if (! collect($settings->available_models)->contains('id', $defaultModel)) {
                throw new TwillAiException('That model is not in the available list.');
            }
            $settings->default_model = $defaultModel;
        }

        if ($systemPrompt !== null) {
            $settings->system_prompt = trim($systemPrompt) ?: null;
        }

        $settings->save();

        return $this->forDisplay();
    }

    /**
     * @return array<string, mixed>
     */
    public function refreshModels(): array
    {
        $settings = $this->settings();

        if (! filled($settings->api_key)) {
            throw new TwillAiException('No API key set.');
        }

        $client = ProviderClients::for($settings->provider);

        if (! $client) {
            throw new TwillAiException('That provider is not supported yet.');
        }

        $settings->available_models = $client->listModels($settings->api_key);
        $settings->models_fetched_at = now();

        if ($settings->default_model && ! collect($settings->available_models)->contains('id', $settings->default_model)) {
            $settings->default_model = $this->preferredDefault($settings->provider, $settings->available_models);
        }

        $settings->save();

        return $this->forDisplay();
    }

    /**
     * Inject the stored key into the AI SDK config for this run, so the agent
     * uses the DB key. Falls back to the env-based config when none is stored.
     */
    public function applyRuntimeConfig(string $provider): void
    {
        $settings = $this->settings();

        if ($settings->isConfigured() && ($key = $settings->keyFor($provider))) {
            config(['ai.providers.'.$provider.'.key' => $key]);
        }
    }

    /**
     * The active model catalog the chat picker + resolver use, or [] when no
     * provider is configured (callers then fall back to config('twill-ai.models')).
     *
     * @return array<int, array{id: string, provider: string, model: string, label: string, description: null}>
     */
    public function modelCatalog(): array
    {
        $settings = $this->settings();

        if (! $settings->isConfigured()) {
            return [];
        }

        return collect($settings->available_models)
            ->map(fn (array $model) => [
                'id' => $settings->provider.':'.$model['id'],
                'provider' => $settings->provider,
                'model' => $model['id'],
                'label' => $model['label'] ?? $model['id'],
                'description' => null,
            ])
            ->values()
            ->all();
    }

    public function defaultModelId(): ?string
    {
        $settings = $this->settings();

        return $settings->isConfigured() && $settings->default_model
            ? $settings->provider.':'.$settings->default_model
            : null;
    }

    /**
     * @param  array<int, array{id: string, label: string}>  $models
     */
    protected function preferredDefault(string $provider, array $models): string
    {
        $ids = collect($models)->pluck('id');

        $prefer = match ($provider) {
            'anthropic' => ['claude-sonnet-4', 'claude-3-7-sonnet', 'claude-3-5-sonnet'],
            'openai' => ['gpt-4.1', 'gpt-4o'],
            'gemini' => ['gemini-2.0-flash', 'gemini-1.5-pro'],
            'mistral' => ['mistral-large'],
            default => [],
        };

        foreach ($prefer as $needle) {
            if ($hit = $ids->first(fn (string $id) => str_contains($id, $needle))) {
                return $hit;
            }
        }

        return (string) $ids->first();
    }
}
