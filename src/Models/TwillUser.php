<?php

namespace TwillAi\Models;

use A17\Twill\Models\User as TwillBaseUser;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * A Twill user that can own OAuth tokens.
 *
 * The MCP connector authenticates external clients with Passport against the
 * twill_users provider, and Passport requires the authenticatable to implement
 * OAuthenticatable. Twill's own user model does not, so a host application
 * without its own subclass gets this one as the fallback for
 * config('twill.models.user').
 *
 * A host that already subclasses Twill's user model should instead apply
 * TwillAi\Concerns\ActsAsOAuthUser to that class and leave
 * config('twill.models.user') pointing at it.
 *
 * This class is only loadable when laravel/passport is installed; every
 * reference to it is guarded by class_exists on the Passport contract.
 */
class TwillUser extends TwillBaseUser implements OAuthenticatable
{
    use HasApiTokens;
}
