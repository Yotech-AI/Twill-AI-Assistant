<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Tests\Fixtures\Models\Article;

/*
 * Self-service connectors. A Twill user adds a connector in their AI app with
 * only the site's /mcp/twill URL: the app registers itself, the user logs in
 * to Twill and approves, and that approval registers the connector.
 */

function registerApp(string $redirectUri, string $name = 'Claude'): TestResponse
{
    return test()->postJson('/oauth/register', [
        'client_name' => $name,
        'redirect_uris' => [$redirectUri],
    ]);
}

function registeredAppId(string $redirectUri, string $name = 'Claude'): string
{
    return (string) registerApp($redirectUri, $name)->assertSuccessful()->json('client_id');
}

function twillEditor(string $email = 'editor@example.test')
{
    return twillAdmin($email, 'PUBLISHER');
}

dataset('ai apps', [
    'Claude' => ['Claude', 'https://claude.ai/api/mcp/auth_callback', 'claude.ai'],
    'ChatGPT' => ['ChatGPT', 'https://chatgpt.com/connector_platform_oauth_redirect', 'chatgpt.com'],
    'a desktop app on loopback' => ['Desktop App', 'http://127.0.0.1:33418/callback', '127.0.0.1'],
]);

it('lets an editor connect an app that registered itself', function (string $name, string $redirectUri, string $host) {
    $clientId = registeredAppId($redirectUri, $name);
    $editor = twillEditor();
    [$verifier, $challenge] = connectorPkce();

    test()->actingAs($editor, 'twill_users')
        ->get(connectorApprovalUrl($clientId, $redirectUri, $challenge))
        ->assertOk()
        ->assertSee($name)
        ->assertSee('given by the app')
        ->assertSee($host)
        ->assertSee('Create and edit drafts')
        ->assertSee('id="authorizeForm"', false);

    $approval = test()->actingAs($editor, 'twill_users')
        ->post(route('twill-ai.mcp.oauth.approve'), ['auth_token' => session('authToken')])
        ->assertRedirect();

    expect((string) $approval->headers->get('Location'))->toStartWith($redirectUri);

    $token = exchangeAuthorizationCode($clientId, $redirectUri, (string) authorizationCode($approval), $verifier);

    mcpRpc($token, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => $name, 'version' => '1.0'],
    ])->assertOk();

    mcpRpc($token, 'tools/list')->assertOk();

    $writtenAs = null;
    Article::created(function () use (&$writtenAs) {
        $writtenAs = Auth::guard('twill_users')->id();
    });

    mcpRpc($token, 'tools/call', ['name' => 'create_content', 'arguments' => [
        'module' => 'articles',
        'payload' => json_encode(['fields' => ['title' => ['en' => "Drafted by {$name}"]], 'locales' => ['en']]),
        'external_ref' => 'self-service-1',
    ]])->assertOk();

    $connector = McpClient::query()->where('oauth_client_id', $clientId)->sole();
    $attribution = $connector->twillUser;

    expect(Article::query()->count())->toBe(1)
        ->and($writtenAs)->toBe($attribution->id)
        ->and($writtenAs)->not->toBe($editor->id)
        ->and($connector->name)->toBe($name)
        ->and($connector->approved_by)->toBe($editor->id)
        ->and($connector->approved_at?->greaterThan(now()->subMinute()))->toBeTrue()
        ->and($attribution->name)->toBe("{$name} (approved by Admin)")
        ->and($attribution->email)->toMatch('/^mcp\+[a-z0-9-]+@localhost$/')
        ->and($attribution->role_value)->toBe('View only')
        ->and((bool) $attribution->published)->toBeFalse()
        ->and($attribution->password)->toBeNull();
})->with('ai apps');

it('gives every approved app its own attribution user', function () {
    $editor = twillEditor();
    [, $challenge] = connectorPkce();

    foreach (['first', 'second'] as $run) {
        $clientId = registeredAppId('https://claude.ai/api/mcp/auth_callback');
        approveConnector($editor, $clientId, 'https://claude.ai/api/mcp/auth_callback', $challenge)->assertRedirect();
    }

    $users = McpClient::query()->pluck('twill_user_id');

    expect($users)->toHaveCount(2)
        ->and($users->unique())->toHaveCount(2);
});

it('refuses to register a redirect URI that OAuth 2.1 does not allow', function (string $redirectUri) {
    registerApp($redirectUri)
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_redirect_uri']);

    expect(Client::query()->count())->toBe(0);
})->with([
    'plain http off loopback' => 'http://example.com/cb',
    'javascript' => 'javascript:alert(1)',
    'a fragment' => 'https://claude.ai/api/mcp/auth_callback#x',
]);

it('registers a desktop app on its private-use scheme', function () {
    registerApp('cursor://anysphere.cursor-mcp/oauth/callback', 'Cursor')->assertSuccessful();
})->skip(! defined(Registrar::class.'::OAUTH_SCOPE'), 'laravel/mcp 0.5 accepts only http and https redirect URIs.');

it('sends a guest to the Twill login and back to the same approval screen', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = registeredAppId($redirectUri);
    twillEditor();
    [, $challenge] = connectorPkce();
    $approvalUrl = connectorApprovalUrl($clientId, $redirectUri, $challenge);

    test()->get($approvalUrl)->assertRedirect(route('twill.login.form'));

    $back = (string) test()->post(route('twill.login'), ['email' => 'editor@example.test', 'password' => 'secret'])
        ->assertRedirect()
        ->headers->get('Location');

    // Laravel keeps the intended URL with its query string sorted.
    parse_str((string) parse_url($back, PHP_URL_QUERY), $backQuery);
    parse_str((string) parse_url($approvalUrl, PHP_URL_QUERY), $approvalQuery);

    expect(strtok($back, '?'))->toBe(strtok($approvalUrl, '?'))
        ->and($backQuery)->toEqual($approvalQuery);

    test()->get($back)->assertOk()->assertSee('id="authorizeForm"', false);

    $approval = test()->post(route('twill-ai.mcp.oauth.approve'), ['auth_token' => session('authToken')]);

    expect(authorizationCode($approval))->toBeString()
        ->and(McpClient::query()->where('oauth_client_id', $clientId)->exists())->toBeTrue();
});

it('refuses a view-only user: no connector and no code', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = registeredAppId($redirectUri);
    $viewer = twillAdmin('viewer@example.test', 'VIEWONLY');
    [, $challenge] = connectorPkce();

    test()->actingAs($viewer, 'twill_users')
        ->get(connectorApprovalUrl($clientId, $redirectUri, $challenge))
        ->assertForbidden()
        ->assertSee('Ask a Twill editor or admin to connect this app')
        ->assertDontSee('id="authorizeForm"', false);

    $approval = test()->actingAs($viewer, 'twill_users')
        ->post(route('twill-ai.mcp.oauth.approve'), ['auth_token' => (string) session('authToken')])
        ->assertForbidden();

    expect(authorizationCode($approval))->toBeNull()
        ->and(McpClient::query()->count())->toBe(0)
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('shows no Approve button and issues no code for an app the site does not allow', function () {
    config()->set('twill-ai.mcp.allowed_redirect_hosts', ['claude.ai']);

    $redirectUri = 'https://chatgpt.com/connector_platform_oauth_redirect';
    $clientId = registeredAppId($redirectUri, 'ChatGPT');
    $editor = twillEditor();
    [, $challenge] = connectorPkce();

    test()->actingAs($editor, 'twill_users')
        ->get(connectorApprovalUrl($clientId, $redirectUri, $challenge))
        ->assertForbidden()
        ->assertSee('chatgpt.com')
        ->assertSee('not allowed on this site')
        ->assertDontSee('id="authorizeForm"', false);

    // An approval screen opened before the site narrowed its list must not
    // get through either.
    config()->set('twill-ai.mcp.allowed_redirect_hosts', null);
    test()->actingAs($editor, 'twill_users')->get(connectorApprovalUrl($clientId, $redirectUri, $challenge))->assertOk();
    config()->set('twill-ai.mcp.allowed_redirect_hosts', ['claude.ai']);

    $approval = test()->actingAs($editor, 'twill_users')
        ->post(route('twill-ai.mcp.oauth.approve'), ['auth_token' => session('authToken')])
        ->assertForbidden();

    expect(authorizationCode($approval))->toBeNull()
        ->and(McpClient::query()->count())->toBe(0)
        ->and(DB::table('oauth_auth_codes')->count())->toBe(0);
});

it('lets an allowed app through when the site lists hosts', function () {
    config()->set('twill-ai.mcp.allowed_redirect_hosts', ['claude.ai']);

    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = registeredAppId($redirectUri);
    [, $challenge] = connectorPkce();

    expect(authorizationCode(approveConnector(twillEditor(), $clientId, $redirectUri, $challenge)))->toBeString();
});

it('refuses a self-registered app after approval when self-service is off, as in 1.3', function () {
    config()->set('twill-ai.mcp.self_service', false);

    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = registeredAppId($redirectUri);
    [$verifier, $challenge] = connectorPkce();

    $approval = approveConnector(twillEditor(), $clientId, $redirectUri, $challenge)->assertRedirect();
    $token = exchangeAuthorizationCode($clientId, $redirectUri, (string) authorizationCode($approval), $verifier);

    mcpRpc($token, 'tools/list')->assertForbidden();

    expect(McpClient::query()->count())->toBe(0);
});

it('never turns the site\'s own OAuth client into a connector', function () {
    // A confidential client the host made for its own customers, say. Only
    // what /oauth/register makes, a public client, can register itself.
    $hostClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Customer portal',
        ['https://portal.example.test/callback'],
    );
    [, $challenge] = connectorPkce();

    approveConnector(twillEditor(), $hostClient->getKey(), 'https://portal.example.test/callback', $challenge);

    expect(McpClient::query()->count())->toBe(0);
});
