<?php

use Illuminate\Support\Facades\View;
use TwillAi\TwillAiServiceProvider;

/**
 * The floating widget rides a view composer on `twill::layouts.main` rather than
 * a byte-copied vendor footer override. These pin the two things that composer
 * has to get right, and it is driven from a Twill built-in admin route so the
 * package's own tests never depend on a host module existing.
 */
function twillAdminUrl(string $path = ''): string
{
    $prefix = trim((string) config('twill.admin_app_path', 'admin'), '/');

    return '/'.trim($prefix.'/'.ltrim($path, '/'), '/');
}

it('injects the widget on an arbitrary admin page', function () {
    $this->actingAs(twillAdmin('widget@example.com'), 'twill_users')
        ->get(twillAdminUrl('users'))
        ->assertOk()
        ->assertSee('twill-ai-widget', false);
});

it('does not inject the widget on the full-page chat', function () {
    // Two instances on one page would fight over the same DOM ids.
    $this->actingAs(twillAdmin('widget-ai@example.com'), 'twill_users')
        ->get(twillAiUrl())
        ->assertOk()
        ->assertDontSee('twill-ai-widget', false);
});

it('does not inject the widget for a guest', function () {
    $this->get(twillAdminUrl('users'))->assertRedirect();
});

it('registers no composer when the widget is disabled', function () {
    $dispatcher = View::getFacadeRoot()->getDispatcher();
    $event = 'composing: twill::layouts.main';

    $before = count($dispatcher->getListeners($event));

    config()->set('twill-ai.floating_widget.enabled', false);
    invokeRegisterFloatingWidget();

    expect(count($dispatcher->getListeners($event)))->toBe($before);

    // ...and the same call with the flag on does attach one, so the assertion
    // above is about the flag rather than about the call being a no-op.
    config()->set('twill-ai.floating_widget.enabled', true);
    invokeRegisterFloatingWidget();

    expect(count($dispatcher->getListeners($event)))->toBe($before + 1);
});

function invokeRegisterFloatingWidget(): void
{
    $provider = app()->getProvider(TwillAiServiceProvider::class);

    $method = new ReflectionMethod($provider, 'registerFloatingWidget');
    $method->setAccessible(true);
    $method->invoke($provider);
}
