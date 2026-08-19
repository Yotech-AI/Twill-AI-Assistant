<?php

use Illuminate\Support\Facades\Route;
use TwillAi\PluginPage\PluginsNavigation;
use TwillAi\PluginPage\TwillPluginServiceProvider;
use TwillAi\TwillAiServiceProvider;

/**
 * The shared Plugins page is vendored into this package rather than pulled from
 * a separate composer dependency, so every Yotech plugin can be installed on its
 * own. The mechanism that keeps several vendored copies from fighting is the
 * pair of container keys below: whichever plugin provider registers FIRST binds
 * itself as the page owner and creates the page, and every later one only adds
 * its manifest to the shared registry.
 *
 * Those two strings are an interop contract with the copies vendored into
 * twill-cms-redirect and twill-cms-seo-suite. Changing either would give a site
 * running two Yotech plugins two Plugins pages.
 */
it('keeps the container keys that let differently-namespaced copies interoperate', function () {
    expect(TwillPluginServiceProvider::REGISTRY_BINDING)->toBe('yotech.twill-plugins.registry')
        ->and(TwillPluginServiceProvider::PAGE_OWNER_BINDING)->toBe('yotech.twill-plugins.page-owner');
});

it('creates the Plugins page because it is the first plugin installed', function () {
    expect(app()->bound(TwillPluginServiceProvider::PAGE_OWNER_BINDING))->toBeTrue()
        ->and(app(TwillPluginServiceProvider::PAGE_OWNER_BINDING))->toBe(TwillAiServiceProvider::class)
        ->and(Route::has(PluginsNavigation::routeName()))->toBeTrue();
});

it('registers itself in the shared plugin registry', function () {
    $registry = app(TwillPluginServiceProvider::REGISTRY_BINDING);

    // Keyed by composer package name, so a second copy of the same plugin
    // cannot list itself twice.
    $manifest = $registry['yotech-ai/twill-cms-ai-assistant'] ?? null;

    expect($manifest)->not->toBeNull()
        ->and($manifest['name'])->toBe(config('twill-ai.ui.title', 'Twill AI'))
        ->and($manifest['route'])->toBe(config('twill.admin_route_name_prefix', 'twill.').'ai.index')
        ->and($manifest['provider'])->toBe(TwillAiServiceProvider::class);
});

it('holds only built-in types in the registry, so a foreign copy can read it', function () {
    $manifest = app(TwillPluginServiceProvider::REGISTRY_BINDING)['yotech-ai/twill-cms-ai-assistant'];

    foreach ($manifest as $key => $value) {
        expect(is_scalar($value) || $value === null)->toBeTrue(
            "Registry value \"{$key}\" is not a built-in type; a copy of this class under another namespace could not read it."
        );
    }
});

it('renders the Plugins page listing this plugin', function () {
    $this->actingAs(twillAdmin('plugins@example.com'), 'twill_users')
        ->get(route(PluginsNavigation::routeName()))
        ->assertOk()
        ->assertSee(config('twill-ai.ui.title', 'Twill AI'), false)
        ->assertSee('yotech-ai/twill-cms-ai-assistant', false);
});

it('keeps the Plugins page behind the admin login', function () {
    $this->get(route(PluginsNavigation::routeName()))->assertRedirect();
});
