<?php

namespace TwillAi\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The OAuth discovery documents for the connector endpoint, and only for it.
 *
 * laravel/mcp serves one set of discovery documents for the whole application:
 * every MCP endpoint names the same authorization server and the same approval
 * route. A host that runs its own MCP server for its customers needs that
 * approval behind its customer login, while this connector needs it behind the
 * CMS login. One answer cannot serve both.
 *
 * So this middleware answers the connector's addresses itself: the protected
 * resource document for the connector path (RFC 9728), naming this package's
 * issuer, and that issuer's authorization server metadata (RFC 8414, plus the
 * OpenID Connect addresses some clients try). Every other request passes
 * through untouched, including the host's own discovery documents.
 *
 * It is global middleware on purpose. laravel/mcp registers a catch-all route
 * for `.well-known/oauth-protected-resource/{path}`, and which of two matching
 * routes wins depends on registration order when routes are not cached and on
 * Symfony's matcher when they are. Answering before routing makes the result
 * the same in every environment.
 */
class ServeConnectorDiscovery
{
    /**
     * The scope laravel/mcp registers with Passport. A literal because 0.5
     * has no constant for it (0.9 names it Registrar::OAUTH_SCOPE).
     */
    public const SCOPE = 'mcp:use';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if ($path === '.well-known/oauth-protected-resource/'.self::resourcePath()) {
            return new JsonResponse(self::protectedResourceMetadata());
        }

        if (in_array($path, self::authorizationServerAddresses(), true)) {
            return new JsonResponse(self::authorizationServerMetadata());
        }

        return $next($request);
    }

    public static function resourcePath(): string
    {
        return trim((string) config('twill-ai.mcp.path', 'mcp/twill'), '/');
    }

    public static function issuerPath(): string
    {
        return trim((string) config('twill-ai.mcp.oauth_prefix', 'twill-ai/oauth'), '/');
    }

    public static function issuer(): string
    {
        return url(self::issuerPath());
    }

    /**
     * RFC 8414 inserts the issuer path after the well-known segment; OpenID
     * Connect discovery appends it. Clients differ in which they try first.
     *
     * @return list<string>
     */
    public static function authorizationServerAddresses(): array
    {
        $issuer = self::issuerPath();

        return [
            '.well-known/oauth-authorization-server/'.$issuer,
            '.well-known/openid-configuration/'.$issuer,
            $issuer.'/.well-known/oauth-authorization-server',
            $issuer.'/.well-known/openid-configuration',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function protectedResourceMetadata(): array
    {
        return [
            'resource' => url(self::resourcePath()),
            'authorization_servers' => [self::issuer()],
            'scopes_supported' => [self::SCOPE],
        ];
    }

    /**
     * The approval screen is this package's; the token and registration
     * endpoints are Passport's and laravel/mcp's, shared with the host, because
     * neither depends on who is logged in.
     *
     * @return array<string, mixed>
     */
    public static function authorizationServerMetadata(): array
    {
        return [
            'issuer' => self::issuer(),
            'authorization_endpoint' => route('twill-ai.mcp.oauth.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => url('oauth/register'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [self::SCOPE],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ];
    }
}
