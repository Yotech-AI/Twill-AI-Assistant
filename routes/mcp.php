<?php

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use TwillAi\Mcp\Http\Controllers\ConnectorAuthorizationController;
use TwillAi\Mcp\Http\Middleware\ActAsTwillUser;
use TwillAi\Mcp\Http\Middleware\RequireCmsLogin;
use TwillAi\Mcp\Servers\TwillContentServer;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| Required by TwillAi\Mcp\McpServiceProvider — laravel/mcp only auto-loads the
| host application's own routes/ai.php and does not scan packages.
|
*/

/*
 * Local (stdio) server: no HTTP, no auth, reachable only from this machine.
 * Kept for development and for `php artisan mcp:inspector twill-content`.
 */
Mcp::local(config('twill-ai.mcp.local_handle', 'twill-content'), TwillContentServer::class);

/*
 * OAuth 2.1 discovery and dynamic client registration.
 *
 * Registers the two .well-known documents Claude reads to find the
 * authorisation and token endpoints, plus POST /oauth/register (RFC 7591).
 * Dynamic registration is why the connector dialog's client id and secret
 * fields are optional — a client that is given neither registers itself.
 *
 * Self-registration alone grants nothing: the client still has no row in
 * mcp_clients, and ActAsTwillUser refuses it.
 *
 * Only when the host has not registered them already. A host that runs its
 * own MCP server calls Mcp::oauthRoutes() itself, often inside a throttle
 * group because /oauth/register is anonymous. Registering the same POST route
 * again would replace the host's (the later route for a method and URI wins)
 * and silently drop its rate limit. These routes are the same for every MCP
 * server on the site, so the host's copy serves the connector too.
 */
if (! array_key_exists('oauth/register', Route::getRoutes()->get('POST'))) {
    Mcp::oauthRoutes();
}

/*
 * The connector's own approval screen, behind the CMS login.
 *
 * Passport's /oauth/authorize authenticates on the one global passport.guard,
 * which a host serving its own customer API or MCP server needs to keep for
 * its customers. These routes are the connector's instead: the discovery
 * documents ServeConnectorDiscovery serves for the connector endpoint point
 * Claude here, and the guard is twill_users whatever passport.guard says.
 * Tokens are still issued by Passport's shared /oauth/token.
 */
Route::middleware('web')
    ->prefix(trim((string) config('twill-ai.mcp.oauth_prefix', 'twill-ai/oauth'), '/'))
    ->name('twill-ai.mcp.oauth.')
    ->group(function (): void {
        Route::get('authorize', [ConnectorAuthorizationController::class, 'authorize'])->name('authorize');

        Route::middleware(RequireCmsLogin::class)->group(function (): void {
            Route::post('authorize', [ApproveAuthorizationController::class, 'approve'])->name('approve');
            Route::delete('authorize', [DenyAuthorizationController::class, 'deny'])->name('deny');
        });
    });

/*
 * Remote server: what an external MCP client such as Claude connects to.
 *
 * Auth is an OAuth access token issued by Passport on the package's own
 * `twill-mcp` guard — claiming `api` would collide with Sanctum in most host
 * applications. The MCP specification documents OAuth 2.1, and it is the only
 * scheme Claude's custom connector dialog offers.
 *
 * The token belongs to the Twill user who approved the connector (the approval
 * screen above sits behind the CMS login). ActAsTwillUser then swaps in the
 * connector's own attribution user so drafts are credited to the connector
 * rather than to that admin.
 *
 * Mcp::web registers outside the `web` group, so there is no session and no
 * CSRF token to satisfy — correct for a machine endpoint.
 */
Mcp::web(config('twill-ai.mcp.path', 'mcp/twill'), TwillContentServer::class)
    ->middleware([
        'auth:twill-mcp',
        ActAsTwillUser::class,
        'throttle:'.config('twill-ai.mcp.throttle', '30,1'),
    ])
    ->name('mcp.twill');
