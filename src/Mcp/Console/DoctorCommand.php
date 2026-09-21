<?php

namespace TwillAi\Mcp\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Throwable;
use TwillAi\Mcp\Http\Middleware\ServeConnectorDiscovery;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Mcp\Servers\TwillContentServer;

/**
 * Diagnoses the MCP integration on whichever machine it runs on.
 *
 * Its main job is telling "Cowork is misconfigured" apart from "the CMS is
 * broken" — the two produce identical-looking failures from the client side.
 */
class DoctorCommand extends Command
{
    protected $signature = 'mcp:doctor';

    protected $description = 'Diagnose the MCP endpoint, its tools and its clients';

    public function handle(): int
    {
        $this->info('MCP doctor');
        $this->line('  app env:    '.config('app.env'));
        $this->line('  app url:    '.config('app.url'));

        $https = ! app()->environment('local', 'testing');
        $this->line('  https:      '.($https ? 'enforced' : 'not enforced (local/testing)'));

        $this->newLine();

        $route = collect(Route::getRoutes())->first(
            fn ($route) => $route->uri() === 'mcp/twill' && in_array('POST', $route->methods(), true)
        );

        if ($route === null) {
            $this->error('  endpoint:   NOT REGISTERED — check routes/ai.php exists and is not cached stale.');

            return self::FAILURE;
        }

        $this->line('  endpoint:   POST /'.$route->uri());
        $this->line('  middleware: '.implode(', ', $route->gatherMiddleware()));

        $this->newLine();

        $keysOk = $this->checkOAuth();

        $this->newLine();

        // The live list, not the property default: the SEO tools are added at
        // construction, so reflecting the default under-reported them.
        $tools = TwillContentServer::effectiveTools();
        $broken = [];

        foreach ($tools as $tool) {
            try {
                app($tool)->toArray();
            } catch (Throwable $e) {
                $broken[] = class_basename($tool).': '.$e->getMessage();
            }
        }

        $this->line('  tools:      '.count($tools).' registered'.($broken === [] ? ', all resolve' : ''));

        foreach ($broken as $problem) {
            $this->error('    BROKEN — '.$problem);
        }

        $this->newLine();

        $clients = McpClient::query()->with('twillUser')->get();

        if ($clients->isEmpty()) {
            $this->warn('  clients:    none. Register one with: php artisan mcp:client-create "Claude Cowork"');
        } else {
            $this->line('  clients:    '.$clients->count());

            foreach ($clients as $client) {
                $attribution = $client->twillUser?->email;
                $liveTokens = $this->liveTokenCount($client);

                $this->line(sprintf(
                    '    #%d %s — oauth client: %s, live tokens: %d, attributed to: %s, last used: %s',
                    $client->id,
                    $client->name,
                    $client->oauth_client_id ?? 'NONE',
                    $liveTokens,
                    $attribution ?? 'NONE (requests will be refused)',
                    $client->last_used_at?->diffForHumans() ?? 'never',
                ));

                if ($attribution === null) {
                    $this->error('      This connector has no Twill user and cannot write. Re-create it.');
                }

                $provider = $client->oauth_client_id === null
                    ? null
                    : Client::query()->whereKey($client->oauth_client_id)->value('provider');

                if ($client->oauth_client_id !== null && $provider !== CreateClientCommand::connectorProvider()) {
                    $this->warn('      Its OAuth client is not bound to the '.CreateClientCommand::connectorProvider().' provider, so another');
                    $this->warn('      Passport guard (a customer API, say) would also accept its tokens. Clients made by');
                    $this->warn('      mcp:client-create are bound; for this older one, set oauth_clients.provider.');
                }

                if ($client->oauth_client_id === null) {
                    $this->error('      No OAuth client linked — this connector can never authenticate.');
                } elseif ($liveTokens === 0) {
                    $this->warn('      No live tokens. Expected until a Twill admin approves the connector.');
                }
            }
        }

        $unregistered = $this->unregisteredClientCount();

        if ($unregistered > 0) {
            $this->newLine();
            $this->warn("  {$unregistered} OAuth client(s) registered themselves but are not allow-listed, so they are refused.");
            $this->line('  Inspect them with: php artisan mcp:client-list --pending');
        }

        $this->newLine();
        $this->line('Block registry is the other common server-side failure: run twill-ai:doctor for that.');

        return $broken === [] && $keysOk ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Passport's signing keys and OAuth discovery routes.
     *
     * Missing keys are the most common deployment failure: they are generated
     * per-environment and gitignored, so a fresh server has none until
     * passport:keys runs, and every token request fails until it does.
     */
    protected function checkOAuth(): bool
    {
        $this->line('  approval:    /'.ServeConnectorDiscovery::issuerPath().'/authorize, behind the CMS login');
        $this->line('               (passport.guard "'.config('passport.guard', 'web').'" is left to the application)');

        $keysOk = (config('passport.private_key') !== null && config('passport.public_key') !== null)
            || (is_readable(storage_path('oauth-private.key')) && is_readable(storage_path('oauth-public.key')));

        if ($keysOk) {
            $this->line('  oauth keys:  present');
        } else {
            $this->error('  oauth keys:  MISSING — run php artisan passport:keys (or set PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY).');
        }

        $discovery = collect(Route::getRoutes())->contains(
            fn ($route) => str_starts_with($route->uri(), '.well-known/oauth-authorization-server')
        );

        if ($discovery) {
            $this->line('  discovery:   registered');
        } else {
            $this->error('  discovery:   MISSING — check Mcp::oauthRoutes() in routes/ai.php.');
        }

        $chain = $this->checkDiscoveryChain();

        return $keysOk && $discovery && $chain;
    }

    /**
     * Follow the discovery chain the way Claude does, through the real HTTP
     * stack: the 401 from the endpoint, the protected resource document it
     * names, and the authorization server metadata that document names. Each
     * link must lead to the connector's own approval screen.
     *
     * This is the check to run after upgrading laravel/mcp or Passport. The
     * connector's discovery answers depend on the 401 header laravel/mcp
     * writes; if that ever changes, this is where it shows.
     */
    protected function checkDiscoveryChain(): bool
    {
        $kernel = app(HttpKernel::class);
        $expectedResource = url('/.well-known/oauth-protected-resource/'.ServeConnectorDiscovery::resourcePath());

        // Absolute, like the two requests below: a bare path is sent to
        // localhost, and the 401 would then name localhost instead of APP_URL.
        $challenge = $kernel->handle(Request::create(url(ServeConnectorDiscovery::resourcePath()), 'POST', server: ['HTTP_ACCEPT' => 'application/json']));
        $header = (string) $challenge->headers->get('WWW-Authenticate');

        if (! str_contains($header, 'resource_metadata="'.$expectedResource.'"')) {
            $this->error('  chain:       BROKEN — the endpoint\'s 401 does not point at '.$expectedResource);
            $this->line('               (got: '.($header === '' ? 'no WWW-Authenticate header' : $header).')');

            return false;
        }

        $resource = json_decode((string) $kernel->handle(Request::create($expectedResource, 'GET'))->getContent(), true);

        if (($resource['authorization_servers'][0] ?? null) !== ServeConnectorDiscovery::issuer()) {
            $this->error('  chain:       BROKEN — the resource document does not name the connector issuer '.ServeConnectorDiscovery::issuer());

            return false;
        }

        $metadataUrl = url(ServeConnectorDiscovery::authorizationServerAddresses()[0]);
        $metadata = json_decode((string) $kernel->handle(Request::create($metadataUrl, 'GET'))->getContent(), true);

        if (($metadata['authorization_endpoint'] ?? null) !== route('twill-ai.mcp.oauth.authorize')) {
            $this->error('  chain:       BROKEN — the issuer metadata does not name the CMS approval screen');

            return false;
        }

        $this->line('  chain:       401 → resource document → issuer → CMS approval screen');

        return true;
    }

    protected function liveTokenCount(McpClient $client): int
    {
        if ($client->oauth_client_id === null) {
            return 0;
        }

        return Token::query()
            ->where('client_id', $client->oauth_client_id)
            ->where('revoked', false)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    protected function unregisteredClientCount(): int
    {
        $registered = McpClient::query()
            ->whereNotNull('oauth_client_id')
            ->pluck('oauth_client_id');

        return Client::query()->whereNotIn('id', $registered)->count();
    }
}
