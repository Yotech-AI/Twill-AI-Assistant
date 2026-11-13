<?php

namespace TwillAi\Mcp;

/**
 * The redirect URIs OAuth 2.1 lets a client register, and where each one
 * sends access.
 *
 * OAuth 2.1 allows three kinds: https; http on a loopback address, for a
 * desktop app listening on a local port; and a private-use scheme a native app
 * owns (RFC 8252), such as cursor://. Anything else, such as plain http to
 * another host, javascript: or data:, could hand an authorization code to
 * someone other than the app.
 */
final class RedirectUri
{
    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Schemes that already mean something in a browser or on the network, so
     * none of them can be an app's own.
     */
    private const NOT_PRIVATE_USE = [
        'about', 'blob', 'data', 'file', 'filesystem', 'ftp', 'http', 'https',
        'javascript', 'mailto', 'sms', 'tel', 'urn', 'vbscript', 'view-source',
        'ws', 'wss',
    ];

    public static function permitted(string $uri): bool
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme'])) {
            return false;
        }

        // A redirect URI never carries a fragment (RFC 6749, 3.1.2), and
        // credentials have no place in one.
        if (isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower($parts['host'] ?? '');

        return match (strtolower($parts['scheme'])) {
            'https' => $host !== '',
            'http' => in_array($host, self::LOOPBACK, true),
            default => self::isPrivateUseScheme($parts['scheme']),
        };
    }

    public static function isPrivateUseScheme(string $scheme): bool
    {
        $scheme = strtolower($scheme);

        return preg_match('/^[a-z][a-z0-9+.\-]*$/', $scheme) === 1
            && ! in_array($scheme, self::NOT_PRIVATE_USE, true);
    }

    /**
     * Where access goes: the host for http and https, the scheme for a
     * desktop app's own. This is what the approval screen and the Connectors
     * page show, and what twill-ai.mcp.allowed_redirect_hosts lists.
     */
    public static function host(string $uri): string
    {
        $parts = parse_url($uri) ?: [];
        $scheme = strtolower($parts['scheme'] ?? '');

        return in_array($scheme, ['http', 'https'], true)
            ? strtolower($parts['host'] ?? '')
            : $scheme;
    }
}
