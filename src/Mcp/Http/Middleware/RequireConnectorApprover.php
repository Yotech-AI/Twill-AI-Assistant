<?php

namespace TwillAi\Mcp\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use TwillAi\Mcp\SelfService;

/**
 * The Connectors page is for the users who may approve a connector: the ones
 * who pass Twill's edit gate. Everyone else is turned away, with who to ask.
 */
class RequireConnectorApprover
{
    public function handle(Request $request, Closure $next): Response
    {
        if (SelfService::mayApprove(Auth::guard('twill_users')->user())) {
            return $next($request);
        }

        return response()->view('twill-ai::mcp.refused', [
            'title' => 'Connectors',
            'appName' => null,
            'appHost' => null,
            'message' => 'Only Twill editors and admins can manage connectors. Ask a Twill editor or admin.',
        ], 403);
    }
}
