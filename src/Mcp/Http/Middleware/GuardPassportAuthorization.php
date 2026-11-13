<?php

namespace TwillAi\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Symfony\Component\HttpFoundation\Response;
use TwillAi\Mcp\Console\CreateClientCommand;
use TwillAi\Mcp\Models\McpClient;

/**
 * Keeps connector clients off Passport's own approval screen.
 *
 * Passport's /oauth/authorize logs in on passport.guard, which on a host with
 * its own customers is the customer login. A connector client authorized
 * there would get a token carrying a customer's id, and the connector's
 * twill-mcp guard reads that id as a Twill user's. So a connector client that
 * asks there is sent to the connector's own screen, behind the CMS login,
 * with the same query.
 *
 * The old setup, passport.guard = twill_users, keeps Passport's screen: it is
 * a CMS screen there, and McpServiceProvider gives it the connector screen's
 * checks. That needs the session, which global middleware runs before, so it
 * happens in the view instead.
 *
 * Global middleware, so it runs before Passport could approve silently.
 */
class GuardPassportAuthorization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || config('passport.guard') === 'twill_users') {
            return $next($request);
        }

        $route = Route::getRoutes()->getByName('passport.authorizations.authorize');

        if ($route === null || trim($request->decodedPath(), '/') !== trim($route->uri(), '/')) {
            return $next($request);
        }

        $client = app(ClientRepository::class)->find((string) $request->query('client_id'));

        if ($client === null || ! self::isConnectorClient($client)) {
            return $next($request);
        }

        return redirect()->to(route('twill-ai.mcp.oauth.authorize', $request->query()));
    }

    /**
     * A connector's client, or one bound to the connector's user provider. An
     * app that only registered itself is not one yet: it may just as well be
     * a client of the host's own MCP server.
     */
    public static function isConnectorClient(Client $client): bool
    {
        return $client->provider === CreateClientCommand::connectorProvider()
            || McpClient::query()->where('oauth_client_id', $client->getKey())->exists();
    }
}
