<?php

use A17\Twill\Facades\TwillNavigation;
use TwillAi\TwillAiServiceProvider;

/**
 * The assistant is reachable from the shared Plugins page and from the floating
 * widget on every admin screen. A third entry in the main navigation is
 * duplication rather than access, so it is off unless a host asks for it.
 */
// The built tree applies each link's shouldShow(), and Twill's links gate on an
// authenticated admin — without one the tree is empty and every assertion here
// would pass for the wrong reason.
beforeEach(function () {
    $this->actingAs(twillAdmin('nav-tree@example.com'), 'twill_users');
});

/**
 * Read the navigation the way Twill renders it. `$links` is private, so the
 * built tree is the only public view of what a user actually sees — and it is
 * the honest thing to assert, since it applies each link's own shouldShow().
 *
 * @return array<int, string>
 */
function twillAiNavigationTitles(): array
{
    $tree = TwillNavigation::buildNavigationTree();

    return collect($tree)
        ->flatten()
        ->map(fn ($link) => $link->getTitle())
        ->all();
}

function invokeRegisterNavigation(): void
{
    $provider = app()->getProvider(TwillAiServiceProvider::class);

    $method = new ReflectionMethod($provider, 'registerNavigation');
    $method->setAccessible(true);
    $method->invoke($provider);
}

it('adds no entry to the admin navigation by default', function () {
    $titles = twillAiNavigationTitles();

    // Guards against a vacuous pass: an empty tree would satisfy the assertion
    // below for entirely the wrong reason. Plugins is there because this package
    // owns that page.
    expect($titles)->toContain('Plugins');

    expect(config('twill-ai.ui.navigation_link'))->toBeFalse()
        ->and($titles)->not->toContain(config('twill-ai.ui.title'));
});

it('adds one when a host opts in', function () {
    // Asserted against the same call the provider makes at boot, so the flag is
    // proven to gate registration rather than merely to exist in the config.
    $before = count(twillAiNavigationTitles());

    config()->set('twill-ai.ui.navigation_link', true);
    invokeRegisterNavigation();

    expect(count(twillAiNavigationTitles()))->toBe($before + 1)
        ->and(twillAiNavigationTitles())->toContain(config('twill-ai.ui.title'));
});

it('still registers no entry when the flag stays off', function () {
    $before = count(twillAiNavigationTitles());

    invokeRegisterNavigation();

    expect(count(twillAiNavigationTitles()))->toBe($before);
});

it('keeps the assistant reachable without a navigation entry', function () {
    // The point of the default: no menu item, but the page is still there and
    // the Plugins card still links to it.
    $this->actingAs(twillAdmin('nav@example.com'), 'twill_users')
        ->get(twillAiUrl())
        ->assertOk();
});
