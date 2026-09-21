<?php

namespace TwillAi\Mcp\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The connector's own approval screen.
 *
 * Passport's AuthorizationController with two things made local to this route
 * instead of global to the application:
 *
 * - the guard, bound in McpServiceProvider to `twill_users`, so a CMS admin is
 *   who approves and passport.guard can stay whatever the host needs it to be;
 * - the view, this package's screen, so the host keeps its own approval screen
 *   for its own OAuth clients.
 *
 * Approve and deny reuse Passport's controllers unchanged: they read the
 * request this screen stored in the session, and their routes sit behind
 * auth:twill_users.
 */
class ConnectorAuthorizationController extends AuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        ?AuthorizationViewResponse $viewResponse = null,
    ): Response|AuthorizationViewResponse {
        // Optional so the router never resolves the host's (possibly unbound)
        // global view; this screen always uses the package's own.
        return parent::authorize($psrRequest, $request, $psrResponse, new SimpleViewResponse(
            fn (array $parameters) => response()->view('twill-ai::mcp.authorize', $parameters + [
                'approveUrl' => route('twill-ai.mcp.oauth.approve'),
                'denyUrl' => route('twill-ai.mcp.oauth.deny'),
            ])
        ));
    }
}
