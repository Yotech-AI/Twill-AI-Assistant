<?php

use Illuminate\Support\Facades\Route;
use TwillAi\Mcp\McpServiceProvider;

/**
 * The connector is gated on TWO conditions: `twill-ai.mcp.enabled` and the
 * presence of laravel/mcp. This file covers the config half; the class half —
 * a host that never installed laravel/mcp at all — is covered by the CI job
 * that runs the suite with mcp and passport removed, because class_exists()
 * cannot be faked from inside a test.
 *
 * These tests run under the default TestCase, which leaves mcp.enabled false.
 */
it('does not register the MCP provider when the connector is disabled', function () {
    expect(config('twill-ai.mcp.enabled'))->toBeFalse()
        ->and(app()->getLoadedProviders())->not->toHaveKey(McpServiceProvider::class);
});

it('registers no MCP route when the connector is disabled', function () {
    $path = trim((string) config('twill-ai.mcp.path', 'mcp/twill'), '/');

    $matching = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), $path));

    expect($matching)->toBeEmpty();
});

it('does not define the twill-mcp guard when the connector is disabled', function () {
    // The guard and the routes are gated together and in the same phase, so a
    // disabled connector leaves neither behind. When they were gated in
    // different phases, a mismatch produced routes with no guard — every
    // request 500ing on "Auth guard [twill-mcp] is not defined" rather than the
    // connector staying quietly dormant.
    expect(config('auth.guards.twill-mcp'))->toBeNull();
});

it('leaves the assistant itself fully working with the connector disabled', function () {
    // The point of the gate: a host that wants the admin assistant but not the
    // external connector gets exactly that.
    $this->actingAs(twillAdmin('gate@example.com'), 'twill_users')
        ->get(twillAiUrl())
        ->assertOk();
});
