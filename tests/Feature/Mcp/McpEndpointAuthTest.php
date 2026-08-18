<?php

use A17\Twill\Models\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use TwillAi\Mcp\Http\Middleware\ActAsTwillUser;
use TwillAi\Mcp\Models\McpClient;

function twillAttributionUser(string $email = 'mcp+test@example.com')
{
    $class = config('twill.models.user');

    $user = new $class;
    $user->name = 'Connector';
    $user->email = $email;
    $user->role = UserRole::VIEWONLY;
    $user->published = false;
    $user->save();

    return $user;
}

/**
 * A registered connector: an OAuth client plus the registry row that maps it to
 * the Twill user its drafts are attributed to.
 *
 * @return array{0: McpClient, 1: Client}
 */
function registeredConnector(bool $linked = true): array
{
    $oauthClient = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Test Connector',
        ['https://claude.ai/api/mcp/auth_callback'],
    );

    $client = McpClient::create([
        'name' => 'Test Connector',
        'oauth_client_id' => $oauthClient->getKey(),
        'twill_user_id' => $linked ? twillAttributionUser()->id : null,
    ]);

    return [$client, $oauthClient];
}

/**
 * The admin who approved the connector. Deliberately a different person from
 * the attribution user, so tests can tell the two apart.
 */
function approvingAdmin()
{
    return twillAttributionUser('admin@example.com');
}

function mcpEndpoint(): string
{
    return '/'.trim((string) config('twill-ai.mcp.path', 'mcp/twill'), '/');
}

function callMcp(): TestResponse
{
    return test()->postJson(mcpEndpoint(), [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
    ]);
}

it('rejects a request with no token', function () {
    callMcp()->assertStatus(401);
});

/*
 * Approving a connector grants access to CMS content, so the approval screen
 * must sit behind the CMS login. Laravel's default handler sends every
 * unauthenticated visitor to the customer login, where signing in can never
 * satisfy the twill_users guard — the admin would simply loop.
 */
it('sends an unapproved connector\'s approver to the CMS login, not the customer login', function () {
    [, $oauthClient] = registeredConnector();

    test()->get('/oauth/authorize?'.http_build_query([
        'client_id' => $oauthClient->getKey(),
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'code_challenge' => 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM',
        'code_challenge_method' => 'S256',
    ]))->assertRedirect(route('twill.login.form'));
});

/*
 * The approval screen must not depend on the frontend build. A missing
 * public/build/manifest.json would otherwise throw before an admin could
 * approve anything, making the connector impossible to set up.
 */
it('renders the approval screen without needing a built frontend', function () {
    [, $oauthClient] = registeredConnector();
    $admin = approvingAdmin();

    $html = view('twill-ai::mcp.authorize', [
        'client' => $oauthClient,
        'user' => $admin,
        'scopes' => [],
        'authToken' => 'test-auth-token',
        'appearance' => 'light',
    ])->render();

    expect($html)
        ->toContain('Authorize Test Connector')
        ->toContain($admin->email)
        ->toContain(route('passport.authorizations.approve'))
        ->toContain(route('passport.authorizations.deny'))
        ->toContain('test-auth-token')
        ->not->toContain('/build/');
});

it('accepts a token issued to a registered connector', function () {
    [, $oauthClient] = registeredConnector();

    Passport::actingAs(approvingAdmin(), ['mcp:use'], guard: 'twill-mcp', client: $oauthClient);

    callMcp()->assertStatus(200);
});

it('refuses a connector that has no Twill user to attribute content to', function () {
    [, $oauthClient] = registeredConnector(linked: false);

    Passport::actingAs(approvingAdmin(), ['mcp:use'], guard: 'twill-mcp', client: $oauthClient);

    callMcp()->assertStatus(403);
});

it('refuses an OAuth client that registered itself but was never allow-listed', function () {
    $selfRegistered = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Self Registered',
        ['https://example.test/callback'],
    );

    expect(McpClient::query()->where('oauth_client_id', $selfRegistered->getKey())->exists())->toBeFalse();

    Passport::actingAs(approvingAdmin(), ['mcp:use'], guard: 'twill-mcp', client: $selfRegistered);

    callMcp()->assertStatus(403);
});

it('refuses a token that carries no OAuth client at all', function () {
    registeredConnector();

    Passport::actingAs(approvingAdmin(), ['mcp:use'], guard: 'twill-mcp');

    callMcp()->assertStatus(403);
});

it('attributes drafts to the connector, not to the admin who approved it', function () {
    [$client] = registeredConnector();

    $admin = approvingAdmin();

    expect(Auth::guard('twill_users')->user())->toBeNull();

    $request = Request::create(mcpEndpoint(), 'POST');
    $admin->withAccessToken(new AccessToken([
        'oauth_client_id' => $client->oauth_client_id,
        'oauth_user_id' => $admin->getAuthIdentifier(),
        'oauth_scopes' => ['mcp:use'],
    ]));
    $request->setUserResolver(fn () => $admin);

    app(ActAsTwillUser::class)->handle($request, fn () => response('ok'));

    expect(Auth::guard('twill_users')->id())
        ->toBe($client->twill_user_id)
        ->not->toBe($admin->id);
});

/*
 * A host may well run no Twill revisions and no user column on its content
 * tables, so this log can be the only record of who wrote what. It must name the
 * connector — under OAuth the authenticated user is the admin who approved it,
 * and logging them would credit a person for a machine.
 */
it('audits content writes against the connector, not the approving admin', function () {
    [$client] = registeredConnector();
    $admin = approvingAdmin();

    $request = Request::create(mcpEndpoint(), 'POST');
    $admin->withAccessToken(new AccessToken([
        'oauth_client_id' => $client->oauth_client_id,
        'oauth_user_id' => $admin->getAuthIdentifier(),
        'oauth_scopes' => ['mcp:use'],
    ]));
    $request->setUserResolver(fn () => $admin);

    app(ActAsTwillUser::class)->handle($request, fn () => response('ok'));

    $bound = app()->bound('mcp.client') ? app('mcp.client') : null;

    expect($bound?->getKey())->toBe($client->getKey())
        ->and($bound?->name)->toBe('Test Connector')
        ->and($bound?->getKey())->not->toBe($admin->getAuthIdentifier());
});

it('records when a connector was last used', function () {
    [$client, $oauthClient] = registeredConnector();

    expect($client->last_used_at)->toBeNull();

    Passport::actingAs(approvingAdmin(), ['mcp:use'], guard: 'twill-mcp', client: $oauthClient);

    callMcp();

    expect($client->fresh()->last_used_at)->not->toBeNull();
});

it('rate limits the endpoint', function () {
    [, $oauthClient] = registeredConnector();

    Passport::actingAs(approvingAdmin(), ['mcp:use'], guard: 'twill-mcp', client: $oauthClient);

    $status = 200;

    for ($i = 0; $i < 32 && $status !== 429; $i++) {
        $status = callMcp()->status();
    }

    expect($status)->toBe(429);
});
