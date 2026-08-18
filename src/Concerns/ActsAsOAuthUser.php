<?php

namespace TwillAi\Concerns;

use Laravel\Passport\HasApiTokens;

/**
 * Makes an existing Twill user model usable by the MCP connector.
 *
 * Apply to a host application's own Twill user subclass and add the
 * Laravel\Passport\Contracts\OAuthenticatable interface:
 *
 *     class TwillUser extends A17\Twill\Models\User implements OAuthenticatable
 *     {
 *         use TwillAi\Concerns\ActsAsOAuthUser;
 *     }
 *
 * Hosts with no user subclass of their own can point
 * config('twill.models.user') at TwillAi\Models\TwillUser instead.
 */
trait ActsAsOAuthUser
{
    use HasApiTokens;
}
