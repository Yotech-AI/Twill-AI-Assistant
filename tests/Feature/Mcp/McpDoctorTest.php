<?php

use TwillAi\Mcp\Servers\TwillContentServer;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;

/**
 * mcp:doctor read the $tools property's compile-time DEFAULT by reflection, so
 * it reported eight tools on a site exposing eleven and never resolve-checked
 * the three it could not see. Both the server and the doctor were tested, but
 * nothing asserted they AGREE — which is the only property that matters for a
 * command whose job is to describe the server.
 *
 * Exit code is deliberately not asserted: under Testbench the Passport keys
 * live at a fixture path rather than storage_path(), so the doctor correctly
 * reports them missing and returns FAILURE. That is the harness, not the
 * behaviour under test.
 */
it('reports the SEO tools the server actually exposes', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    expect(TwillContentServer::effectiveTools())->toHaveCount(11);

    $this->artisan('mcp:doctor')
        ->expectsOutputToContain('11 registered');
});

it('reports eight without the Suite', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(TwillContentServer::effectiveTools())->toHaveCount(8);

    $this->artisan('mcp:doctor')
        ->expectsOutputToContain('8 registered');
});

it('resolves every tool it reports, SEO ones included', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    // "all resolve" is only a real guarantee if the tools it checked are the
    // tools the server serves.
    $this->artisan('mcp:doctor')
        ->expectsOutputToContain('all resolve');
});

it('follows the discovery chain to the CMS approval screen', function () {
    $this->artisan('mcp:doctor')
        ->expectsOutputToContain('401 → resource document → issuer → CMS approval screen');
});

it('warns about a connector whose OAuth client is not bound to the CMS provider', function () {
    registeredConnector();

    $this->artisan('mcp:doctor')
        ->expectsOutputToContain('is not bound to the twill_users provider');
});
