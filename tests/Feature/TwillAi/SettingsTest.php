<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use TwillAi\Agents\TwillAssistant;
use TwillAi\Models\Chat;
use TwillAi\Models\TwillAiSetting;
use TwillAi\Services\ChatService;
use TwillAi\Services\Providers\AnthropicClient;
use TwillAi\Services\Providers\GeminiClient;
use TwillAi\Services\Providers\MistralClient;
use TwillAi\Services\Providers\OpenAiClient;
use TwillAi\Services\SettingsService;

function fakeAnthropicModels(): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response(['data' => [
            ['id' => 'claude-sonnet-4-6', 'display_name' => 'Claude Sonnet 4.6'],
            ['id' => 'claude-opus-4-8', 'display_name' => 'Claude Opus 4.8'],
        ]], 200),
    ]);
}

it('stores the API key encrypted and only exposes a masked form', function () {
    fakeAnthropicModels();
    $admin = twillAdmin('settings@example.com');

    $response = $this->actingAs($admin, 'twill_users')
        ->putJson(twillAiUrl('settings/key'), ['provider' => 'anthropic', 'key' => 'sk-ant-secret-1234'])
        ->assertOk()
        ->json();

    expect($response['verified'])->toBeTrue()
        ->and($response['key_masked'])->toContain('1234')
        ->and($response['available_models'])->toHaveCount(2);

    $setting = TwillAiSetting::current();
    expect($setting->api_key)->toBe('sk-ant-secret-1234') // decrypts via the cast
        ->and($setting->key_last_four)->toBe('1234')
        ->and($setting->verified_at)->not->toBeNull();

    // The column is encrypted, not the plaintext key.
    $raw = DB::table('twill_ai_settings')->where('id', $setting->id)->value('api_key');
    expect($raw)->not->toBe('sk-ant-secret-1234');

    // The GET payload never leaks the raw key.
    $get = $this->actingAs($admin, 'twill_users')->getJson(twillAiUrl('settings'))->json();
    expect(json_encode($get))->not->toContain('sk-ant-secret-1234');
});

it('rejects an invalid API key and stores nothing', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'bad key'], 401)]);

    $this->actingAs(twillAdmin('settings-bad@example.com'), 'twill_users')
        ->putJson(twillAiUrl('settings/key'), ['provider' => 'anthropic', 'key' => 'bad'])
        ->assertStatus(422);

    expect(TwillAiSetting::current()->isConfigured())->toBeFalse();
});

it('reveals model + prompt settings only after the key is verified', function () {
    $admin = twillAdmin('settings-reveal@example.com');

    // Not verified yet, so the settings update is refused.
    $this->actingAs($admin, 'twill_users')
        ->putJson(twillAiUrl('settings'), ['default_model' => 'claude-opus-4-8', 'system_prompt' => 'Be terse.'])
        ->assertStatus(422);

    fakeAnthropicModels();
    $this->actingAs($admin, 'twill_users')
        ->putJson(twillAiUrl('settings/key'), ['provider' => 'anthropic', 'key' => 'sk-ant-xxxx9999'])
        ->assertOk();

    $this->actingAs($admin, 'twill_users')
        ->putJson(twillAiUrl('settings'), ['default_model' => 'claude-opus-4-8', 'system_prompt' => 'Be terse.'])
        ->assertOk();

    $setting = TwillAiSetting::current();
    expect($setting->default_model)->toBe('claude-opus-4-8')
        ->and($setting->system_prompt)->toBe('Be terse.');

    // A model not in the fetched list is rejected.
    $this->actingAs($admin, 'twill_users')
        ->putJson(twillAiUrl('settings'), ['default_model' => 'gpt-4o'])
        ->assertStatus(422);
});

it('drives the chat model catalog and default from settings when configured', function () {
    fakeAnthropicModels();
    app(SettingsService::class)->saveApiKey('anthropic', 'sk-ant-aaaa2222');
    app(SettingsService::class)->updateSettings('claude-opus-4-8', null);

    $chats = app(ChatService::class);

    expect(collect($chats->models())->pluck('id'))
        ->toContain('anthropic:claude-sonnet-4-6', 'anthropic:claude-opus-4-8')
        ->and($chats->defaultModelId())->toBe('anthropic:claude-opus-4-8');

    $resolved = $chats->resolveModel('anthropic:claude-opus-4-8');
    expect($resolved['provider'])->toBe('anthropic')
        ->and($resolved['model'])->toBe('claude-opus-4-8');
});

it('falls back to the config models when no provider key is set', function () {
    $chats = app(ChatService::class);

    expect(collect($chats->models())->pluck('id'))->toContain(config('twill-ai.default_model'));
});

it('injects the stored key into the AI SDK config at runtime', function () {
    fakeAnthropicModels();
    app(SettingsService::class)->saveApiKey('anthropic', 'sk-ant-runtime-7777');

    config(['ai.providers.anthropic.key' => 'env-fallback']);
    app(SettingsService::class)->applyRuntimeConfig('anthropic');

    expect(config('ai.providers.anthropic.key'))->toBe('sk-ant-runtime-7777');
});

it('appends the settings system prompt to the assistant instructions', function () {
    fakeAnthropicModels();
    app(SettingsService::class)->saveApiKey('anthropic', 'sk-ant-prompt-5555');
    app(SettingsService::class)->updateSettings(null, 'Always mention the brand voice.');

    $admin = twillAdmin('settings-prompt@example.com');
    $chat = Chat::create(['user_id' => $admin->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);

    $instructions = (new TwillAssistant($chat))->instructions();

    expect($instructions)->toContain('Always mention the brand voice.')
        ->toContain('# Project notes');
});

it('parses and filters each provider model list', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['data' => [['id' => 'claude-x', 'display_name' => 'Claude X']]], 200),
        'api.openai.com/*' => Http::response(['data' => [['id' => 'gpt-4o'], ['id' => 'text-embedding-3-small'], ['id' => 'whisper-1']]], 200),
        'generativelanguage.googleapis.com/*' => Http::response(['models' => [
            ['name' => 'models/gemini-2.0-flash', 'displayName' => 'Gemini 2.0 Flash', 'supportedGenerationMethods' => ['generateContent']],
            ['name' => 'models/embedding-001', 'displayName' => 'Embedding', 'supportedGenerationMethods' => ['embedContent']],
        ]], 200),
        'api.mistral.ai/*' => Http::response(['data' => [['id' => 'mistral-large-latest'], ['id' => 'mistral-embed']]], 200),
    ]);

    expect(collect((new AnthropicClient)->listModels('k'))->pluck('id')->all())->toEqual(['claude-x']);
    expect(collect((new OpenAiClient)->listModels('k'))->pluck('id')->all())
        ->toContain('gpt-4o')
        ->not->toContain('text-embedding-3-small');
    expect(collect((new GeminiClient)->listModels('k'))->pluck('id')->all())->toEqual(['gemini-2.0-flash']);
    expect(collect((new MistralClient)->listModels('k'))->pluck('id')->all())->toEqual(['mistral-large-latest']);
});
