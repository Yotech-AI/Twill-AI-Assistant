<?php

namespace TwillAi\Services\Providers;

use Illuminate\Http\Client\Response;
use Throwable;
use TwillAi\Exceptions\TwillAiException;

/**
 * Shared HTTP + error handling for the provider model-listing adapters. A 401/
 * 403 (or a 400, which most providers return for a malformed/invalid key) is
 * surfaced as a clean "rejected the API key" message used for validation.
 */
abstract class AbstractProviderClient implements ProviderClient
{
    abstract protected function label(): string;

    abstract protected function send(string $key): Response;

    /**
     * @return array<int, array{id: string, label: string}>
     */
    abstract protected function parse(Response $response): array;

    public function listModels(string $key): array
    {
        try {
            $response = $this->send($key);
        } catch (Throwable $e) {
            throw new TwillAiException('Could not reach '.$this->label().': '.$e->getMessage());
        }

        if (in_array($response->status(), [400, 401, 403], true)) {
            throw new TwillAiException($this->label().' rejected the API key.');
        }

        if (! $response->successful()) {
            throw new TwillAiException($this->label().' returned an error ('.$response->status().').');
        }

        $models = $this->parse($response);

        if ($models === []) {
            throw new TwillAiException($this->label().' returned no usable models.');
        }

        return $models;
    }
}
