<?php

namespace TwillAi\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;
use TwillAi\Mcp\Models\McpClient;

/**
 * Binds an MCP connector's dedicated attribution user to the twill_users guard.
 *
 * Twill stamps every revision with Auth::guard('twill_users')->user()->id ?? null
 * (see HandleRevisions). Under OAuth the access token belongs to whichever CMS
 * admin approved the connector, so without this every draft would be credited
 * to that person rather than to the machine that wrote it. The token's
 * client_id identifies the connector, which mcp_clients maps to a dedicated,
 * password-less Twill user.
 *
 * This is also the allow-list. Passport's dynamic client registration endpoint
 * is public, so a client can register itself and be approved without ever
 * having been issued by mcp:client-create — such a client has no mcp_clients
 * row and is refused here.
 */
class ActAsTwillUser
{
    public function handle(Request $request, Closure $next): Response
    {
        // A token sent over plain HTTP is a leaked credential. Proxies are
        // trusted in bootstrap/app.php, so secure() reflects the real scheme
        // behind a load balancer. Local development stays on http.
        if (! $request->secure() && ! app()->environment('local', 'testing')) {
            abort(403, 'The MCP endpoint requires HTTPS.');
        }

        $user = $request->user();

        if ($user === null) {
            abort(401, 'This endpoint requires an OAuth access token.');
        }

        $client = $this->resolveClient($user);

        if ($client === null) {
            abort(403, 'This OAuth client is not a registered MCP connector. Issue one with php artisan mcp:client-create.');
        }

        $twillUser = $client->twillUser;

        if ($twillUser === null) {
            abort(403, sprintf(
                'MCP client "%s" has no linked Twill user, so its content could not be attributed. Re-create it with php artisan mcp:client-create.',
                $client->name,
            ));
        }

        Auth::guard('twill_users')->setUser($twillUser);

        /*
         * Audit logging needs the connector, not the request user. Under OAuth
         * the authenticated user is the admin who approved the connector, so a
         * tool reading $request->user() would credit a person for a machine's
         * write. Bound under a string key rather than the model class so an
         * unbound lookup cannot silently resolve to an empty model.
         */
        app()->instance('mcp.client', $client);

        $client->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }

    /**
     * Map the request's access token back to the connector that holds it.
     *
     * The token guard sets an AccessToken value object, not the Token model,
     * so oauth_client_id is read from its PSR request attributes rather than
     * from the database. TransientToken — what session-authenticated requests
     * carry — is deliberately excluded: a signed-in browser is not a connector.
     */
    protected function resolveClient(mixed $user): ?McpClient
    {
        $token = method_exists($user, 'currentAccessToken')
            ? $user->currentAccessToken()
            : null;

        if (! $token instanceof AccessToken) {
            return null;
        }

        $oauthClientId = $token->oauth_client_id;

        if (! is_string($oauthClientId) || $oauthClientId === '') {
            return null;
        }

        return McpClient::query()
            ->where('oauth_client_id', $oauthClientId)
            ->first();
    }
}
