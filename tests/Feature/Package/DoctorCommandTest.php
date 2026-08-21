<?php

use TwillAi\Models\TwillAiSetting;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;

/**
 * The doctor exists to tell the truth about wiring, so a wrong line here is
 * worse than no line: it sends someone to debug a setting that was correct.
 * It reported "anthropic key: MISSING" on every properly configured site for
 * exactly that reason — the key lives encrypted in twill_ai_settings and only
 * reaches config when a chat runs.
 */
function storeKey(array $attributes = []): TwillAiSetting
{
    return TwillAiSetting::query()->create(array_merge([
        'provider' => 'anthropic',
        'api_key' => 'sk-ant-secret-1234',
        'key_last_four' => '1234',
        'verified_at' => now(),
        'available_models' => [['id' => 'claude-opus-5', 'label' => 'Opus 5']],
    ], $attributes));
}

it('reports a key saved in the admin, with no key in config at all', function () {
    config()->set('ai.providers.anthropic.key', null);
    storeKey();

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('admin settings, ••••••••1234')
        ->doesntExpectOutputToContain('MISSING')
        ->assertSuccessful();
});

it('never prints the key itself', function () {
    storeKey();

    $this->artisan('twill-ai:doctor')
        ->doesntExpectOutputToContain('sk-ant-secret-1234')
        ->assertSuccessful();
});

it('calls out a key that was saved but never verified', function () {
    // applyRuntimeConfig() refuses to apply this one, so the agent fails while
    // the settings screen looks filled in. Previously indistinguishable from
    // having no key at all.
    storeKey(['verified_at' => null]);

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('NOT VERIFIED')
        ->assertSuccessful();
});

it('calls out a verified key with no cached model list', function () {
    storeKey(['available_models' => []]);

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('twill-ai:refresh-models')
        ->assertSuccessful();
});

it('falls back to config for a host that never opens the settings screen', function () {
    config()->set('ai.providers.anthropic.key', 'sk-ant-from-env');

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('config, ai.providers.anthropic.key')
        ->assertSuccessful();
});

it('reports MISSING only when there is genuinely no key anywhere', function () {
    config()->set('ai.providers.anthropic.key', null);

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('MISSING')
        ->assertSuccessful();
});

it('reports the SEO integration as off without the Suite', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('SEO Suite integration is off')
        ->assertSuccessful();
});

it('distinguishes "not installed" from "turned off in config"', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));
    config()->set('twill-ai.seo.enabled', false);

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('disabled (twill-ai.seo.enabled is false)')
        ->assertSuccessful();
});

// One substring per OUTPUT LINE below, never two against the same line.
// expectsOutputToContain registers a Mockery expectation per substring, and
// Mockery dispatches each written line to the FIRST matching expectation only —
// so splitting "PERMITTED" and its reason into two calls fails even when both
// are plainly on the line. Assert the whole line instead.
it('warns that live entries are editable, and says why', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));
    config()->set('twill-ai.allow_updating_published', null);

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('PERMITTED — allow_updating_published is null and the Suite is installed')
        // The invariants that survive the permission must be stated with it, or
        // the warning reads as "the agent can publish", which it cannot.
        ->expectsOutputToContain('still created as drafts')
        ->assertSuccessful();
});

it('reports live entries as refused when the host pinned the flag closed', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));
    config()->set('twill-ai.allow_updating_published', false);

    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('refused — allow_updating_published is set to false')
        ->doesntExpectOutputToContain('still created as drafts')
        ->assertSuccessful();
});
