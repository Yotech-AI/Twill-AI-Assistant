<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use TwillAi\Mcp\ConnectorRegistrar;
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

/**
 * Passport's own authorization URL for a request the connector's screen would
 * also take.
 */
function passportAuthorizeUrl(string $clientId, string $redirectUri, string $challenge, array $extra = []): string
{
    return '/oauth/authorize?'.http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'state-abc',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ] + $extra);
}

/**
 * An app that registered itself and was approved by an editor.
 */
function approvedApp(string $redirectUri = 'https://claude.ai/api/mcp/auth_callback'): string
{
    $clientId = registeredAppId($redirectUri);
    [, $challenge] = connectorPkce();

    approveConnector(twillEditor('approver@example.test'), $clientId, $redirectUri, $challenge)->assertRedirect();
    freshRequestState();

    return $clientId;
}

/*
 * Passport's own /oauth/authorize authenticates on passport.guard, which on
 * a host with its own customers is the customer login. A connector client
 * authorized there would get a token carrying a customer's id, which the
 * connector's guard then reads as a Twill user's.
 */
it('sends a connector client from Passport\'s own screen to the connector\'s', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = approvedApp($redirectUri);
    [, $challenge] = connectorPkce();

    $location = (string) test()->get(passportAuthorizeUrl($clientId, $redirectUri, $challenge))
        ->assertRedirect()
        ->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(strtok($location, '?'))->toBe(route('twill-ai.mcp.oauth.authorize'))
        ->and($query['client_id'] ?? null)->toBe($clientId)
        ->and($query['code_challenge'] ?? null)->toBe($challenge);
});

it('leaves Passport\'s own screen alone for the site\'s own clients', function () {
    $hostClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Customer portal',
        ['https://portal.example.test/callback'],
    );
    [, $challenge] = connectorPkce();

    $response = test()->get(passportAuthorizeUrl($hostClient->getKey(), 'https://portal.example.test/callback', $challenge));

    expect((string) $response->headers->get('Location'))->not->toStartWith(route('twill-ai.mcp.oauth.authorize'));
});

it('revokes the tokens an app was given before it was approved', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = registeredAppId($redirectUri);
    [$verifier, $challenge] = connectorPkce();

    // As in 1.3: with self-service off anyone in the CMS could approve, and
    // got a token the endpoint refused because the app was not registered.
    config()->set('twill-ai.mcp.self_service', false);
    $approval = approveConnector(twillAdmin('viewer@example.test', 'VIEWONLY'), $clientId, $redirectUri, $challenge);
    $earlierToken = exchangeAuthorizationCode($clientId, $redirectUri, (string) authorizationCode($approval), $verifier);

    mcpRpc($earlierToken, 'tools/list')->assertForbidden();
    freshRequestState();

    config()->set('twill-ai.mcp.self_service', true);
    $approval = approveConnector(twillEditor(), $clientId, $redirectUri, $challenge)->assertRedirect();
    $editorToken = exchangeAuthorizationCode($clientId, $redirectUri, (string) authorizationCode($approval), $verifier);

    expect(mcpRpc($earlierToken, 'tools/list')->status())->toBeIn([401, 403]);
    freshRequestState();
    mcpRpc($editorToken, 'tools/list')->assertOk();
});

it('always shows the screen for an app that is not registered yet', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = registeredAppId($redirectUri);
    $editor = twillEditor();
    [$verifier, $challenge] = connectorPkce();

    // A token from before, which Passport would otherwise take as consent.
    config()->set('twill-ai.mcp.self_service', false);
    exchangeAuthorizationCode($clientId, $redirectUri, (string) authorizationCode(approveConnector($editor, $clientId, $redirectUri, $challenge)), $verifier);
    config()->set('twill-ai.mcp.self_service', true);

    test()->actingAs($editor, 'twill_users')
        ->get(connectorApprovalUrl($clientId, $redirectUri, $challenge))
        ->assertOk()
        ->assertSee('id="authorizeForm"', false);

    $silent = test()->actingAs($editor, 'twill_users')
        ->get(connectorApprovalUrl($clientId, $redirectUri, $challenge).'&prompt=none');

    parse_str((string) parse_url((string) $silent->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query['error'] ?? null)->toBe('consent_required')
        ->and($query)->not->toHaveKey('code');
});

it('refuses a registration on an encoded path too', function () {
    test()->postJson('/oauth/%72egister', ['client_name' => 'x', 'redirect_uris' => ['http://example.com/cb']])
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_redirect_uri']);
});

it('refuses a redirect URI with a host that is not plain ASCII', function () {
    registerApp("https://cl\u{0430}ude.ai/api/mcp/auth_callback")
        ->assertStatus(400)
        ->assertJson(['error' => 'invalid_redirect_uri']);
});

it('checks the redirect URI the app asks for, on any loopback port', function () {
    config()->set('twill-ai.mcp.allowed_redirect_hosts', ['claude.ai']);

    $clientId = registeredAppId('http://127.0.0.1:33418/callback', 'Desktop App');
    [, $challenge] = connectorPkce();

    test()->actingAs(twillEditor(), 'twill_users')
        ->get(connectorApprovalUrl($clientId, 'http://127.0.0.1:50000/callback', $challenge))
        ->assertForbidden()
        ->assertSee('127.0.0.1')
        ->assertDontSee('id="authorizeForm"', false);
});

it('keeps the approval screens out of frames and labels the buttons Approve and Deny', function () {
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';
    $clientId = registeredAppId($redirectUri);
    [, $challenge] = connectorPkce();

    $screen = test()->actingAs(twillEditor(), 'twill_users')->get(connectorApprovalUrl($clientId, $redirectUri, $challenge));
    $refusal = test()->actingAs(twillAdmin('viewer@example.test', 'VIEWONLY'), 'twill_users')->get(connectorApprovalUrl($clientId, $redirectUri, $challenge));

    foreach ([$screen, $refusal] as $response) {
        expect($response->headers->get('X-Frame-Options'))->toBe('DENY')
            ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'");
    }

    preg_match_all('/<button[^>]*>(.*?)<\/button>/s', (string) $screen->getContent(), $buttons);

    expect(array_map(fn (string $label) => trim(strip_tags($label)), $buttons[1]))->toBe(['Deny', 'Approve']);
});

it('leaves a host\'s own list of private-use schemes as it is', function () {
    config()->set('mcp.custom_schemes', ['cursor']);

    registerApp('cursor://anysphere.cursor-mcp/oauth/callback', 'Cursor')->assertSuccessful();
    registerApp('someotherapp://callback/x', 'Other')->assertStatus(400);
})->skip(! defined(Registrar::class.'::OAUTH_SCOPE'), 'laravel/mcp 0.5 accepts only http and https redirect URIs.');

it('registers an approved app where Twill\'s permission management dropped the role column', function () {
    $editor = twillEditor();
    config()->set('twill.enabled.permissions-management', true);
    Schema::table('twill_users', fn (Blueprint $table) => $table->dropColumn('role'));

    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], false);

    $connector = app(ConnectorRegistrar::class)->approve($client, $editor);

    expect($connector->twillUser)->not->toBeNull()
        ->and($connector->approved_by)->toBe($editor->id);
});
