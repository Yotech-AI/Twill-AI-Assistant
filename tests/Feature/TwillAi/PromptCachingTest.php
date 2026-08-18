<?php

use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use TwillAi\Agents\TwillAssistant;
use TwillAi\Models\Chat;

it('opts the Anthropic provider into prompt caching', function () {
    $agent = new TwillAssistant(new Chat);

    expect($agent)->toBeInstanceOf(HasProviderOptions::class)
        ->and($agent->providerOptions('anthropic'))->toBe(['cache_control' => ['type' => 'ephemeral']])
        ->and($agent->providerOptions(Lab::Anthropic))->toBe(['cache_control' => ['type' => 'ephemeral']]);
});

it('leaves providers that cache automatically untouched', function (string $provider) {
    expect((new TwillAssistant(new Chat))->providerOptions($provider))->toBe([]);
})->with(['openai', 'gemini', 'mistral']);
