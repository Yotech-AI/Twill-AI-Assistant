<?php

namespace TwillAi\Mcp\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use TwillAi\Mcp\ConnectorRegistrar;
use TwillAi\Mcp\Console\RevokeClientCommand;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Mcp\RedirectUri;
use TwillAi\Mcp\SelfService;

/**
 * The Connectors page: every connector, who approved it and when, Revoke, and
 * "Add an app by hand" for an app that cannot register itself.
 *
 * Only reachable through RequireConnectorApprover.
 */
class ConnectorsController extends Controller
{
    public function index(Request $request): View
    {
        $connectors = McpClient::query()
            ->with(['approver', 'oauthClient'])
            ->orderBy('id')
            ->get();

        return view('twill-ai::mcp.connectors', [
            'connectors' => $connectors,
            'hosts' => $connectors->mapWithKeys(fn (McpClient $connector) => [
                $connector->id => self::hostsOf($connector),
            ]),
            'created' => $request->session()->get('twill-ai.connector-created'),
            'status' => $request->session()->get('twill-ai.connector-status'),
            'selfService' => SelfService::enabled(),
            'endpoint' => url(trim((string) config('twill-ai.mcp.path', 'mcp/twill'), '/')),
            'routePrefix' => config('twill.admin_route_name_prefix', 'twill.').'ai.connectors.',
        ]);
    }

    /**
     * Add an app by hand, as mcp:client-create does from the command line.
     * Its secret is flashed for the next page only: shown once, never stored
     * anywhere it could be read again.
     */
    public function store(Request $request, ConnectorRegistrar $registrar): RedirectResponse
    {
        // With self-service off, connectors are a server admin's job, as in 1.3.
        abort_unless(SelfService::enabled(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect_uris' => ['required', 'string', function (string $attribute, mixed $value, $fail): void {
                foreach (self::lines((string) $value) as $uri) {
                    if (! RedirectUri::permitted($uri)) {
                        $fail("{$uri} is not a redirect URI OAuth 2.1 allows: use https, http on localhost, 127.0.0.1 or [::1], or the app's own scheme.");

                        return;
                    }

                    if (! SelfService::allowsRedirectTo($uri)) {
                        $fail(RedirectUri::host($uri).' is not on this site\'s list of allowed apps.');

                        return;
                    }
                }
            }],
        ]);

        $uris = self::lines($validated['redirect_uris']);

        if ($uris === []) {
            return back()->withErrors(['redirect_uris' => 'Add at least one redirect URI.'])->withInput();
        }

        [$connector, $client] = $registrar->addByHand($validated['name'], $uris, Auth::guard('twill_users')->user());

        return redirect()->route(config('twill.admin_route_name_prefix', 'twill.').'ai.connectors.index')
            ->with('twill-ai.connector-created', [
                'name' => $connector->name,
                'client_id' => (string) $client->getKey(),
                'client_secret' => (string) $client->plainSecret,
            ]);
    }

    /**
     * What mcp:client-revoke does: revoke the connector's access and refresh
     * tokens, so its next call is refused and only a new approval brings it
     * back.
     */
    public function revoke(McpClient $connector): RedirectResponse
    {
        [$access, $refresh] = $connector->oauth_client_id === null
            ? [0, 0]
            : RevokeClientCommand::revokeTokensOf($connector->oauth_client_id);

        return redirect()->route(config('twill.admin_route_name_prefix', 'twill.').'ai.connectors.index')
            ->with('twill-ai.connector-status', "Revoked \"{$connector->name}\": {$access} access token(s) and {$refresh} refresh token(s). It can only connect again through a new approval.");
    }

    /**
     * @return list<string>
     */
    protected static function hostsOf(McpClient $connector): array
    {
        $uris = (array) ($connector->oauthClient?->redirect_uris ?? []);

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $uri): string => is_string($uri) ? RedirectUri::host($uri) : '',
            $uris,
        ))));
    }

    /**
     * @return list<string>
     */
    protected static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
    }
}
