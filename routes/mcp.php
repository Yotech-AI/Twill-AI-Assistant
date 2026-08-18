<?php

use Laravel\Mcp\Facades\Mcp;
use TwillAi\Mcp\Http\Middleware\ActAsTwillUser;
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
 */
Mcp::oauthRoutes();

/*
 * Remote server: what an external MCP client such as Claude connects to.
 *
 * Auth is an OAuth access token issued by Passport on the package's own
 * `twill-mcp` guard — claiming `api` would collide with Sanctum in most host
 * applications. The MCP specification documents OAuth 2.1, and it is the only
 * scheme Claude's custom connector dialog offers.
 *
 * The token belongs to the Twill user who approved the connector (the approval
 * screen sits behind the CMS login). ActAsTwillUser then swaps in the
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
