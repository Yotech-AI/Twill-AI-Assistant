<?php

namespace TwillAi\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Self-service connectors: whether they are on, which apps the site lets
 * connect, and who may approve one.
 *
 * Read at request time, never at boot, so the settings can change without
 * re-registering anything.
 */
final class SelfService
{
    public static function enabled(): bool
    {
        return (bool) config('twill-ai.mcp.self_service', true);
    }

    /**
     * The hosts apps may connect from, lowercased, or null for any.
     *
     * @return list<string>|null
     */
    public static function allowedHosts(): ?array
    {
        $hosts = config('twill-ai.mcp.allowed_redirect_hosts');

        if ($hosts === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $host): string => strtolower(trim((string) $host)),
            (array) $hosts,
        ))));
    }

    public static function allowsRedirectTo(string $redirectUri): bool
    {
        $hosts = self::allowedHosts();

        return $hosts === null || in_array(RedirectUri::host($redirectUri), $hosts, true);
    }

    /**
     * Twill's edit gate: whoever may change content may connect an app that
     * changes content, and a view-only user may not. With Twill's permission
     * management on, the legacy "edit" gate is not defined at all; the global
     * ability it maps to there is "edit-settings".
     */
    public static function mayApprove(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        $ability = config('twill.enabled.permissions-management') ? 'edit-settings' : 'edit';

        return Gate::forUser($user)->allows($ability);
    }
}
