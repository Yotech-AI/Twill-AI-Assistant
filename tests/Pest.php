<?php

use A17\Twill\Models\Enums\UserRole;
use A17\Twill\Models\User as TwillUser;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Tests\HostOAuthRoutesMcpTestCase;
use TwillAi\Tests\LegacyGuardMcpTestCase;
use TwillAi\Tests\McpTestCase;
use TwillAi\Tests\TestCase;

// Pest refuses overlapping uses() paths, so these are listed as siblings rather
// than as 'Feature' plus an override. Feature/Mcp boots with the connector
// enabled; everything else keeps it off.
uses(TestCase::class)->in('Feature/Package', 'Feature/TwillAi', 'Feature/Seo');

// Guarded so the suite still loads for a host that installed neither
// laravel/mcp nor laravel/passport: McpTestCase touches Passport at class level,
// and CI runs one job with both packages removed.
if (class_exists(Passport::class)) {
    uses(McpTestCase::class)->in('Feature/Mcp');
    uses(LegacyGuardMcpTestCase::class)->in('Feature/McpLegacyGuard');
    uses(HostOAuthRoutesMcpTestCase::class)->in('Feature/McpHostRoutes');
}

/**
 * A saved, published Twill admin. Several suites need one to own a chat or to
 * act on an admin route.
 */
function twillAdmin(string $email = 'admin@example.test', string $role = 'SUPERADMIN'): TwillUser
{
    $userClass = config('twill.models.user', TwillUser::class);

    $user = new $userClass;
    $user->name = 'Admin';
    $user->email = $email;
    $user->password = bcrypt('secret');
    $user->published = true;
    $user->role = $role;
    $user->save();

    return $user;
}

/**
 * Build a package admin URL from Twill's configured admin path rather than
 * hardcoding "/admin/ai". A host is free to rename that path, and the package
 * follows it — so the tests have to as well, or they would assert a convention
 * the package deliberately does not depend on.
 */
function twillAiUrl(string $path = ''): string
{
    $prefix = trim((string) config('twill.admin_app_path', 'admin'), '/');

    return '/'.trim($prefix.'/ai/'.ltrim($path, '/'), '/');
}

/*
|--------------------------------------------------------------------------
| MCP connector helpers
|--------------------------------------------------------------------------
|
| Shared by every file in Feature/Mcp. The classes they touch (Passport, the
| connector's McpClient) are only resolved when a helper is called, so the
| no-connector CI job, which never calls them, loads this file fine.
|
*/

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
