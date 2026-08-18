<?php

namespace TwillAi\Mcp\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use ReflectionProperty;
use Throwable;
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

        $tools = (new ReflectionProperty(TwillContentServer::class, 'tools'))->getDefaultValue();
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
        $this->line('  oauth guard: '.config('passport.guard', 'web').' (who approves a connector)');

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

        return $keysOk && $discovery;
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
