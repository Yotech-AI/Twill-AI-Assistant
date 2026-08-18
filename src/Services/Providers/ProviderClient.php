<?php

namespace TwillAi\Services\Providers;

interface ProviderClient
{
    /**
     * Fetch the provider's chat-capable models. Throws a TwillAiException on an
     * auth/HTTP failure, so it doubles as API-key validation.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public function listModels(string $key): array;
}
