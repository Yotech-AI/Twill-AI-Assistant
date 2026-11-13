<?php

namespace TwillAi\Mcp\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Exceptions\OAuthServerException;
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
    /**
     * An approval screen is never shown inside someone else's page, where a
     * click on Approve could be borrowed.
     */
    public const FRAMING_HEADERS = [
        'X-Frame-Options' => 'DENY',
        'Content-Security-Policy' => "frame-ancestors 'none'",
    ];

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

        if (SelfService::enabled() && $client !== null && ! $this->guard->guest()) {
            $refusal = self::refusalFor($this->guard->user(), $appName, $redirectUri);

            if ($refusal !== null) {
                return $refusal;
            }

            // Approving is what registers an app that registered itself, so
            // it always gets the screen, even where Passport would otherwise
            // approve silently because this user approved it before. An app
            // that asks for no screen at all gets OAuth's consent_required.
            if ($selfRegistered) {
                if (in_array('none', explode(' ', (string) $request->input('prompt', '')), true)) {
                    throw OAuthServerException::consentRequired(
                        $this->withErrorHandling(fn () => $this->server->validateAuthorizationRequest($psrRequest))
                    );
                }

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
                'appHost' => $redirectUri === null ? null : RedirectUri::host($redirectUri),
                'nameFromApp' => $selfRegistered,
            ])->withHeaders(self::FRAMING_HEADERS)
        ));
    }

    /**
     * The redirect URI this authorization goes to: the one the app asks for,
     * as it asks for it, or the client's only one when it asks for none.
     * Passport refuses a URI the client did not register, and accepts a
     * loopback one on any port (RFC 8252), so this is where the code would go.
     */
    public static function redirectUriFor(Client $client, mixed $requested): ?string
    {
        if (is_string($requested) && $requested !== '') {
            return $requested;
        }

        $registered = array_values(array_filter((array) $client->redirect_uris, 'is_string'));

        return count($registered) === 1 ? $registered[0] : null;
    }

    /**
     * The self-service checks an approval takes: the user must pass Twill's
     * edit gate, and the app's redirect host must be one the site allows. The
     * refusal screen for the first that fails, or null when both pass.
     */
    public static function refusalFor(?Authenticatable $user, ?string $appName, ?string $redirectUri): ?Response
    {
        $appHost = $redirectUri === null ? null : RedirectUri::host($redirectUri);

        if (! SelfService::mayApprove($user)) {
            return self::refusal($appName, $appHost, 'Ask a Twill editor or admin to connect this app.');
        }

        if ($redirectUri !== null && ! SelfService::allowsRedirectTo($redirectUri)) {
            return self::refusal($appName, $appHost, "This app is not allowed on this site: it sends its access to {$appHost}, which the site does not list.");
        }

        return null;
    }

    /**
     * The screen for an approval that cannot happen: no forms, nothing stored
     * in the session, so nothing can be approved from it.
     */
    public static function refusal(?string $appName, ?string $appHost, string $message, ?string $title = null): Response
    {
        return response()->view('twill-ai::mcp.refused', [
            'title' => $title,
            'appName' => $appName,
            'appHost' => $appHost,
            'message' => $message,
        ], 403)->withHeaders(self::FRAMING_HEADERS);
    }
}
