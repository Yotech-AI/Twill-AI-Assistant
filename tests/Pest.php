<?php

use A17\Twill\Models\User as TwillUser;
use Laravel\Passport\Passport;
use TwillAi\Tests\McpTestCase;
use TwillAi\Tests\TestCase;

// Pest refuses overlapping uses() paths, so these are listed as siblings rather
// than as 'Feature' plus an override. Feature/Mcp boots with the connector
// enabled; everything else keeps it off.
uses(TestCase::class)->in('Feature/Package', 'Feature/TwillAi', 'Unit');

// Guarded so the suite still loads for a host that installed neither
// laravel/mcp nor laravel/passport: McpTestCase touches Passport at class level,
// and CI runs one job with both packages removed.
if (class_exists(Passport::class)) {
    uses(McpTestCase::class)->in('Feature/Mcp');
}

/**
 * A saved, published Twill admin. Several suites need one to own a chat or to
 * act on an admin route.
 */
function twillAdmin(string $email = 'admin@example.test', string $role = 'SUPERADMIN'): TwillUser
{
    $userClass = config('twill.models.user', TwillUser::class);

    $user = new $userClass;
    $user->name = 'Admin';
    $user->email = $email;
    $user->password = bcrypt('secret');
    $user->published = true;
    $user->role = $role;
    $user->save();

    return $user;
}

/**
 * Build a package admin URL from Twill's configured admin path rather than
 * hardcoding "/admin/ai". A host is free to rename that path, and the package
 * follows it — so the tests have to as well, or they would assert a convention
 * the package deliberately does not depend on.
 */
function twillAiUrl(string $path = ''): string
{
    $prefix = trim((string) config('twill.admin_app_path', 'admin'), '/');

    return '/'.trim($prefix.'/ai/'.ltrim($path, '/'), '/');
}
