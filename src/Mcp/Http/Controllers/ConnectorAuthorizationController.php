<?php

namespace TwillAi\Mcp\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Http\Responses\SimpleViewResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;
use TwillAi\Mcp\ConnectorRegistrar;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Mcp\RedirectUri;
use TwillAi\Mcp\SelfService;

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
 * With self-service on, two checks come before Passport, so they also hold
 * where Passport would approve silently for a user who approved before: the
 * user must pass Twill's edit gate, and the app's redirect host must be one
 * the site allows. ConnectorApprovalController checks both again on approve.
 *
 * Approve and deny reuse Passport's controllers: they read the request this
 * screen stored in the session, and their routes sit behind the CMS login.
 */
class ConnectorAuthorizationController extends AuthorizationController
{
    public function authorize(
        ServerRequestInterface $psrRequest,
        Request $request,
        ResponseInterface $psrResponse,
        ?AuthorizationViewResponse $viewResponse = null,
    ): Response|AuthorizationViewResponse {
        $client = $this->clients->find((string) $request->query('client_id'));
        $redirectUri = $client === null ? null : self::redirectUriFor($client, $request->query('redirect_uri'));
        $connector = $client === null ? null : McpClient::query()->where('oauth_client_id', $client->getKey())->first();
        $selfRegistered = $client !== null && $connector === null && ConnectorRegistrar::canSelfRegister($client);
        $appName = $connector?->name ?? ($client === null ? null : ConnectorRegistrar::appName($client));
        $appHost = $redirectUri === null ? null : RedirectUri::host($redirectUri);

        if (SelfService::enabled() && $client !== null && ! $this->guard->guest()) {
            if (! SelfService::mayApprove($this->guard->user())) {
                return self::refusal($appName, $appHost, 'Ask a Twill editor or admin to connect this app.');
            }

            if ($redirectUri !== null && ! SelfService::allowsRedirectTo($redirectUri)) {
                return self::refusal($appName, $appHost, "This app is not allowed on this site: it sends its access to {$appHost}, which the site does not list.");
            }

            // Approving is what registers an app that registered itself, so
            // it always gets the screen, even where Passport would otherwise
            // approve silently because this user approved it before.
            if ($selfRegistered) {
                $request->merge(['prompt' => trim($request->input('prompt', '').' consent')]);
            }
        }

        // Optional so the router never resolves the host's (possibly unbound)
        // global view; this screen always uses the package's own.
        return parent::authorize($psrRequest, $request, $psrResponse, new SimpleViewResponse(
            fn (array $parameters) => response()->view('twill-ai::mcp.authorize', $parameters + [
                'approveUrl' => route('twill-ai.mcp.oauth.approve'),
                'denyUrl' => route('twill-ai.mcp.oauth.deny'),
                'appName' => $appName,
                'appHost' => $appHost,
                'nameFromApp' => $selfRegistered,
            ])
        ));
    }

    /**
     * The redirect URI this authorization goes to, as Passport resolves it:
     * the one requested when the client registered it, or the client's only
     * one when none was requested. Null when Passport will refuse the request
     * anyway, which it then does.
     */
    public static function redirectUriFor(Client $client, mixed $requested): ?string
    {
        $registered = array_values(array_filter((array) $client->redirect_uris, 'is_string'));

        if (is_string($requested) && $requested !== '') {
            return in_array($requested, $registered, true) ? $requested : null;
        }

        return count($registered) === 1 ? $registered[0] : null;
    }

    /**
     * The screen for an approval that cannot happen: no forms, nothing stored
     * in the session, so nothing can be approved from it.
     */
    public static function refusal(?string $appName, ?string $appHost, string $message): Response
    {
        return response()->view('twill-ai::mcp.refused', [
            'appName' => $appName,
            'appHost' => $appHost,
            'message' => $message,
        ], 403);
    }
}
