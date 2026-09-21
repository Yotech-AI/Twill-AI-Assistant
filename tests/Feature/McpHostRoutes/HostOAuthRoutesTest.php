<?php

use TwillAi\Mcp\Http\Middleware\ServeConnectorDiscovery;

/*
 * Registering laravel/mcp's OAuth routes a second time would replace the
 * host's POST /oauth/register (the later route for a method and URI wins)
 * and drop the rate limit the host put on that anonymous endpoint.
 */
it('keeps the host\'s rate limit on client registration', function () {
    $register = fn () => test()->postJson('/oauth/register', [
        'client_name' => 'x',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ]);

    foreach (range(1, 3) as $attempt) {
        $register()->assertCreated();
    }

    $register()->assertStatus(429);
});

it('still sends the connector to its own approval screen on such a host', function () {
    test()->getJson('/.well-known/oauth-protected-resource/'.ServeConnectorDiscovery::resourcePath())
        ->assertOk()
        ->assertJson(['authorization_servers' => [ServeConnectorDiscovery::issuer()]]);
});
