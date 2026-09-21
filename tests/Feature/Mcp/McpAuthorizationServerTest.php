<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use TwillAi\Mcp\Models\McpClient;

/*
 * The connector runs its own OAuth authorization server: its own discovery
 * documents, its own approval screen behind the CMS login, and nothing global
 * changed. Everything here runs with passport.guard left at Passport's default,
 * `web`, which is what a host serving its own customer MCP or API needs.
 */

function connectorIssuer(): string
{
    return url(trim((string) config('twill-ai.mcp.oauth_prefix', 'twill-ai/oauth'), '/'));
}

function pkcePair(): array
{
    $verifier = str_repeat('a1b2c3d4', 8);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [$verifier, $challenge];
}

/**
 * @return array{0: McpClient, 1: Client}
 */
function allowListedConnector(): array
{
    $oauthClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Claude',
        ['https://claude.ai/api/mcp/auth_callback'],
    );

    $client = McpClient::create([
        'name' => 'Claude',
        'oauth_client_id' => $oauthClient->getKey(),
        'twill_user_id' => twillAttributionUser('mcp+claude@example.com')->id,
    ]);

    return [$client, $oauthClient];
}

function authorizeQuery(string $clientId, string $challenge): array
{
    return [
        'client_id' => $clientId,
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'state-123',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ];
}

it('leaves passport.guard at the host\'s value', function () {
    expect(config('passport.guard'))->toBe('web');
});

it('points the 401 challenge at the connector\'s own protected-resource document', function () {
    $header = callMcp()->assertStatus(401)->headers->get('WWW-Authenticate');

    expect($header)->toContain('resource_metadata="'.url('/.well-known/oauth-protected-resource/'.trim(mcpEndpoint(), '/')).'"');
});

it('names the connector\'s own issuer for the connector endpoint', function () {
    test()->getJson('/.well-known/oauth-protected-resource/'.trim(mcpEndpoint(), '/'))
        ->assertOk()
        ->assertJson([
            'resource' => url(mcpEndpoint()),
            'authorization_servers' => [connectorIssuer()],
            'scopes_supported' => ['mcp:use'],
        ]);
});

it('serves the connector\'s authorization server metadata at every discovery address a client tries', function () {
    $prefix = trim((string) config('twill-ai.mcp.oauth_prefix', 'twill-ai/oauth'), '/');

    foreach ([
        "/.well-known/oauth-authorization-server/{$prefix}",
        "/.well-known/openid-configuration/{$prefix}",
        "/{$prefix}/.well-known/oauth-authorization-server",
        "/{$prefix}/.well-known/openid-configuration",
    ] as $address) {
        test()->getJson($address)
            ->assertOk()
            ->assertJson([
                'issuer' => connectorIssuer(),
                'authorization_endpoint' => route('twill-ai.mcp.oauth.authorize'),
                'token_endpoint' => route('passport.token'),
                'registration_endpoint' => url('oauth/register'),
                'code_challenge_methods_supported' => ['S256'],
            ]);
    }
});

it('leaves the host\'s own discovery documents alone', function () {
    // laravel/mcp shapes these differently in 0.5 and 0.9; either way they
    // are its documents, not the connector's.
    $hostResource = test()->getJson('/.well-known/oauth-protected-resource/mcp/app')->assertOk()->json();

    expect($hostResource['authorization_servers'] ?? [])->not->toContain(connectorIssuer());

    test()->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJson(['authorization_endpoint' => route('passport.authorizations.authorize')]);
});

it('answers its discovery addresses even when a host route claims them first', function () {
    Route::get('.well-known/oauth-protected-resource/'.trim(mcpEndpoint(), '/'), fn () => response()->json(['authorization_servers' => ['https://wrong.example']]));
    Route::getRoutes()->refreshNameLookups();

    test()->getJson('/.well-known/oauth-protected-resource/'.trim(mcpEndpoint(), '/'))
        ->assertOk()
        ->assertJson(['authorization_servers' => [connectorIssuer()]]);
});

it('sends a guest approver to the CMS login', function () {
    [, $oauthClient] = allowListedConnector();
    [, $challenge] = pkcePair();

    test()->get(route('twill-ai.mcp.oauth.authorize', authorizeQuery($oauthClient->getKey(), $challenge)))
        ->assertRedirect(route('twill.login.form'));
});

it('shows a logged-in CMS admin the connector approval screen, posting to its own routes', function () {
    [, $oauthClient] = allowListedConnector();
    [, $challenge] = pkcePair();

    test()->actingAs(approvingAdmin(), 'twill_users')
        ->get(route('twill-ai.mcp.oauth.authorize', authorizeQuery($oauthClient->getKey(), $challenge)))
        ->assertOk()
        ->assertSee('Authorize Claude')
        ->assertSee(route('twill-ai.mcp.oauth.approve'), false)
        ->assertSee(route('twill-ai.mcp.oauth.deny'), false)
        ->assertDontSee(route('passport.authorizations.approve'), false);
});

it('refuses to approve for someone not logged in to the CMS', function () {
    test()->post(route('twill-ai.mcp.oauth.approve'), ['auth_token' => 'x'])
        ->assertRedirect(route('twill.login.form'));
});

it('does not replace the host\'s own approval screen', function () {
    expect(app()->bound(AuthorizationViewResponse::class))->toBeFalse();
});

/**
 * The real flow a connector goes through, returning its access token.
 *
 * @return array{0: string, 1: McpClient, 2: Client}
 */
function signInConnector(): array
{
    [$client, $oauthClient] = allowListedConnector();
    [$verifier, $challenge] = pkcePair();
    $admin = approvingAdmin();

    test()->actingAs($admin, 'twill_users')
        ->get(route('twill-ai.mcp.oauth.authorize', authorizeQuery($oauthClient->getKey(), $challenge)))
        ->assertOk();

    $redirect = test()->actingAs($admin, 'twill_users')
        ->post(route('twill-ai.mcp.oauth.approve'), ['auth_token' => session('authToken')])
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);

    expect($redirect)->toStartWith('https://claude.ai/api/mcp/auth_callback')
        ->and($query['state'] ?? null)->toBe('state-123')
        ->and($query['code'] ?? null)->toBeString();

    $token = test()->postJson(route('passport.token'), [
        'grant_type' => 'authorization_code',
        'client_id' => $oauthClient->getKey(),
        'client_secret' => $oauthClient->plainSecret,
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'code_verifier' => $verifier,
        'code' => $query['code'],
    ])->assertOk()->json('access_token');

    Auth::forgetGuards();

    return [(string) $token, $client, $oauthClient];
}

function callMcpWith(string $token): TestResponse
{
    return test()->withHeader('Authorization', 'Bearer '.$token)
        ->postJson(mcpEndpoint(), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
}

it('runs the whole sign-in: approve in the CMS, exchange the code, call the endpoint', function () {
    [$token, $client] = signInConnector();

    callMcpWith($token)->assertOk();

    expect($client->fresh()->last_used_at)->not->toBeNull();
});

it('refuses a connector token whose client is bound to another kind of account', function () {
    [$token, , $oauthClient] = signInConnector();

    $oauthClient->forceFill(['provider' => 'users'])->save();

    // Passport memoizes client lookups with once(), which a real server clears
    // between requests; the test runs every request in one process.
    Once::flush();

    callMcpWith($token)->assertStatus(401);
});

it('binds a new connector client to the CMS user provider', function () {
    test()->artisan('mcp:client-create', ['name' => 'Claude Cowork'])->assertSuccessful();

    $oauthClient = Client::query()->findOrFail(McpClient::query()->sole()->oauth_client_id);

    expect($oauthClient->provider)->toBe('twill_users');
});

it('binds an adopted self-registered client to the CMS user provider', function () {
    $selfRegistered = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback']);

    expect($selfRegistered->provider)->toBeNull();

    test()->artisan('mcp:client-create', ['name' => 'Claude', '--oauth-client' => $selfRegistered->getKey()])->assertSuccessful();

    expect($selfRegistered->fresh()->provider)->toBe('twill_users');
});
