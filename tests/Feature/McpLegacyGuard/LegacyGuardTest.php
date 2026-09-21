<?php

use Laravel\Passport\Contracts\AuthorizationViewResponse;
use TwillAi\Mcp\Http\Middleware\ServeConnectorDiscovery;

it('keeps the package approval screen on Passport\'s own route', function () {
    [, $oauthClient] = registeredConnector();

    expect(app()->bound(AuthorizationViewResponse::class))->toBeTrue();

    test()->actingAs(approvingAdmin(), 'twill_users')
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $oauthClient->getKey(),
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('Authorize Test Connector')
        ->assertSee(route('passport.authorizations.approve'), false);
});

it('still sends new connections to the connector\'s own approval screen', function () {
    test()->getJson('/.well-known/oauth-protected-resource/'.ServeConnectorDiscovery::resourcePath())
        ->assertOk()
        ->assertJson(['authorization_servers' => [ServeConnectorDiscovery::issuer()]]);
});
