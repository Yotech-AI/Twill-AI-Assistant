<?php

namespace TwillAi\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CMS login in front of the connector's approve and deny routes.
 *
 * Not `auth:twill_users`: Laravel's Authenticate middleware builds its
 * redirect from the host's `login` route before any exception handler runs,
 * so in a host with no such route it fails with RouteNotFound, and in one
 * that has it the approver is pointed at the customer login first. The only
 * login that can satisfy this route is the CMS login, so send them there.
 */
class RequireCmsLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('twill_users');

        if ($guard->guest()) {
            $login = config('twill.admin_route_name_prefix', 'twill.').'login.form';

            return $request->expectsJson() || ! Route::has($login)
                ? response()->json(['message' => 'Log in to the CMS to approve a connector.'], 401)
                : redirect()->guest(route($login));
        }

        Auth::shouldUse('twill_users');

        return $next($request);
    }
}
