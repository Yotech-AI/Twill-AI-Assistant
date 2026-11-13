<?php

namespace TwillAi\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TwillAi\Mcp\RedirectUri;
use TwillAi\Mcp\SelfService;

/**
 * Refuses a dynamic client registration whose redirect URIs OAuth 2.1 does
 * not allow, before laravel/mcp registers it.
 *
 * With self-service on, registering is the first step to a working connector,
 * so an authorization code may only ever go to https, to http on a loopback
 * address for a desktop app, or to an app's private-use scheme. laravel/mcp on
 * its own accepts plain http to any host.
 *
 * Global middleware rather than a route's, because POST /oauth/register may be
 * the host's own route (see routes/mcp.php), shared by every MCP server on the
 * site. It runs before that route's throttle, so a refused registration does
 * not count against it.
 */
class ValidateClientRegistration
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! SelfService::enabled() || ! $request->isMethod('POST') || trim($request->path(), '/') !== 'oauth/register') {
            return $next($request);
        }

        $uris = $request->input('redirect_uris');

        // Missing or malformed: laravel/mcp's own validation answers that.
        if (! is_array($uris)) {
            return $next($request);
        }

        $schemes = [];

        foreach (array_values($uris) as $index => $uri) {
            if (! is_string($uri)) {
                continue;
            }

            if (! RedirectUri::permitted($uri)) {
                return new JsonResponse([
                    'error' => 'invalid_redirect_uri',
                    'error_description' => "redirect_uris.{$index} must use https, http on a loopback address (localhost, 127.0.0.1 or [::1]), or an app's own private-use scheme.",
                ], 400);
            }

            $scheme = strtolower((string) parse_url($uri, PHP_URL_SCHEME));

            if (! in_array($scheme, ['http', 'https'], true)) {
                $schemes[] = $scheme;
            }
        }

        // laravel/mcp 0.9 refuses every scheme but http and https unless
        // mcp.custom_schemes lists it. The ones that got this far are the
        // private-use schemes OAuth 2.1 allows, so this registration may use
        // them. (0.5 has no such list; its url rule refuses them regardless.)
        if ($schemes !== []) {
            config(['mcp.custom_schemes' => array_values(array_unique([
                ...(array) config('mcp.custom_schemes', []),
                ...$schemes,
            ]))]);
        }

        return $next($request);
    }
}
