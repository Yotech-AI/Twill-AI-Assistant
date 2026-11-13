<?php

namespace TwillAi\Mcp\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use TwillAi\Mcp\ConnectorRegistrar;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Mcp\RedirectUri;
use TwillAi\Mcp\SelfService;

/**
 * Approve on the connector's own screen.
 *
 * Passport's approve, with self-service around it. The approver must pass
 * Twill's edit gate and the app's redirect host must be one the site allows,
 * checked again here rather than trusted to a hidden button. Approving an app
 * that registered itself registers it, in this same request, so the code
 * Passport then issues leads to a token ActAsTwillUser accepts.
 *
 * With self-service off this is Passport's approve, unchanged, as in 1.3.
 */
class ConnectorApprovalController extends ApproveAuthorizationController
{
    public function __construct(
        AuthorizationServer $server,
        protected ClientRepository $clients,
        protected ConnectorRegistrar $registrar,
    ) {
        parent::__construct($server);
    }

    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        if (! SelfService::enabled()) {
            return parent::approve($request, $psrResponse);
        }

        // RequireCmsLogin has made sure there is one.
        $approver = Auth::guard('twill_users')->user();

        if (! SelfService::mayApprove($approver)) {
            $request->session()->forget(['authToken', 'authRequest']);

            return ConnectorAuthorizationController::refusal(null, null, 'Ask a Twill editor or admin to connect this app.');
        }

        $authRequest = $this->pendingAuthRequest($request);

        // No request matches this token: Passport's approve refuses it.
        if ($authRequest === null) {
            return parent::approve($request, $psrResponse);
        }

        $client = $this->clients->find($authRequest->getClient()->getIdentifier());
        $redirectUri = $authRequest->getRedirectUri()
            ?? ($client === null ? null : ConnectorAuthorizationController::redirectUriFor($client, null));

        if ($redirectUri !== null && ! SelfService::allowsRedirectTo($redirectUri)) {
            $request->session()->forget(['authToken', 'authRequest']);

            return ConnectorAuthorizationController::refusal(
                $client === null ? null : ConnectorRegistrar::appName($client),
                RedirectUri::host($redirectUri),
                'This app is not allowed on this site: it sends its access to '.RedirectUri::host($redirectUri).', which the site does not list.',
            );
        }

        if ($client !== null
            && ConnectorRegistrar::canSelfRegister($client)
            && ! McpClient::query()->where('oauth_client_id', $client->getKey())->exists()) {
            $this->registrar->approve($client, $approver);
        }

        return parent::approve($request, $psrResponse);
    }

    /**
     * The authorization request the approval screen stored, if this POST
     * carries its token. Read through Passport's own check, then put back:
     * Passport's approve takes it from the session next.
     */
    protected function pendingAuthRequest(Request $request): ?AuthorizationRequestInterface
    {
        $session = $request->session();
        $token = $session->get('authToken');
        $stored = $session->get('authRequest');

        try {
            $authRequest = $this->getAuthRequestFromSession($request);
        } catch (Throwable) {
            return null;
        }

        $session->put('authToken', $token);
        $session->put('authRequest', $stored);

        return $authRequest;
    }
}
