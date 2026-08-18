<?php

namespace TwillAi\Console;

use A17\Twill\Facades\TwillBlocks;
use Illuminate\Console\Command;
use Laravel\Mcp\Server;
use Laravel\Passport\Contracts\OAuthenticatable;
use Throwable;
use TwillAi\Services\BlockSchemaService;
use TwillAi\Services\ModuleRegistry;

/**
 * Diagnoses the Twill AI environment — most usefully on a server, where a
 * queue worker or deployment can leave the agent with an incomplete block
 * registry. Reports whether every block the agent is allowed to use is
 * actually registered in THIS process.
 */
class DoctorCommand extends Command
{
    protected $signature = 'twill-ai:doctor';

    protected $description = 'Diagnose the Twill AI environment (block registry, queue and API key wiring).';

    public function handle(ModuleRegistry $registry, BlockSchemaService $blocks): int
    {
        $this->info('Twill AI doctor');
        $this->line('  app env:       '.config('app.env'));
        $this->line('  queue:         '.config('twill-ai.queue_connection').' / '.config('twill-ai.queue'));
        $this->line('  anthropic key: '.(filled(config('ai.providers.anthropic.key')) ? 'set' : 'MISSING'));

        try {
            $this->line('  blocks loaded: '.TwillBlocks::getBlocks()->count());
        } catch (Throwable $e) {
            $this->error('  blocks loaded: ERROR — '.$e->getMessage());
        }

        $this->checkHostWiring();

        $this->newLine();

        $missing = [];

        foreach ($registry->all() as $key => $config) {
            $this->line("Module: {$key}");

            foreach (($config['block_editors'] ?? []) as $editor => $allowed) {
                foreach ($allowed as $name) {
                    $ok = $blocks->blockExists($name);

                    if (! $ok) {
                        $missing[] = "{$key}.{$editor}: {$name}";
                    }

                    $this->line(sprintf('  [%s] %s: %s', $ok ? 'OK ' : 'XXX', $editor, $name));
                }
            }
        }

        $this->newLine();

        if ($missing !== []) {
            $this->error(count($missing).' configured block(s) are NOT registered in this environment:');

            foreach ($missing as $entry) {
                $this->line('  - '.$entry);
            }

            $this->newLine();
            $this->line('→ The agent cannot create blocks it cannot resolve. Ensure the block view files are deployed,');
            $this->line('  then restart the queue worker so it reloads them: php artisan queue:restart');

            return self::FAILURE;
        }

        $this->info('All configured blocks resolve in this process.');
        $this->line('If the agent still fails, the long-running queue worker is on stale code — restart it:');
        $this->line('  php artisan queue:restart');

        return self::SUCCESS;
    }

    /**
     * Reports the host-application wiring the package cannot supply itself.
     */
    protected function checkHostWiring(): void
    {
        $this->newLine();
        $this->line('Host wiring');

        // The upload disk must exist and be writable, or every attachment fails.
        $disk = config('twill-ai.uploads.disk', 'twill-ai');
        $root = config("filesystems.disks.{$disk}.root");

        if ($root === null) {
            $this->error("  [XXX] upload disk \"{$disk}\" is not configured.");
        } elseif (! is_dir($root) && ! @mkdir($root, 0755, true)) {
            $this->error("  [XXX] upload disk \"{$disk}\" root is not creatable: {$root}");
        } elseif (! is_writable($root)) {
            $this->error("  [XXX] upload disk \"{$disk}\" root is not writable: {$root}");
        } else {
            $this->line("  [OK ] upload disk \"{$disk}\" is writable.");
        }

        // A chat that is never picked up by a worker looks like a hung agent.
        $connection = config('twill-ai.queue_connection', 'twill-ai');

        if (config("queue.connections.{$connection}") === null) {
            $this->error("  [XXX] queue connection \"{$connection}\" is not configured.");
        } else {
            $this->line("  [OK ] queue connection \"{$connection}\" is configured.");
            $this->line('         A worker must be running: php artisan queue:work '.$connection
                .' --queue='.config('twill-ai.queue', 'twill-ai'));
        }

        if (! config('twill-ai.mcp.enabled')) {
            $this->line('  [ - ] MCP connector is disabled.');

            return;
        }

        if (! class_exists(Server::class)) {
            $this->error('  [XXX] MCP is enabled but laravel/mcp is not installed.');

            return;
        }

        // Passport must be able to sign tokens, and the guard's user provider
        // must resolve a model that can own them.
        $userModel = config('twill.models.user');

        if ($userModel === null) {
            $this->warn('  [ ! ] twill.models.user is not set — MCP needs a user model implementing');
            $this->line('         Laravel\Passport\Contracts\OAuthenticatable. Point it at');
            $this->line('         TwillAi\Models\TwillUser, or apply TwillAi\Concerns\ActsAsOAuthUser to your own.');
        } elseif (! is_a($userModel, OAuthenticatable::class, true)) {
            $this->error("  [XXX] twill.models.user ({$userModel}) does not implement OAuthenticatable.");
        } else {
            $this->line('  [OK ] twill.models.user implements OAuthenticatable.');
        }

        // The MCP approval screen is rendered on Passport's /oauth/authorize
        // route, which authenticates on config('passport.guard'). A Twill admin
        // is logged in on twill_users, so any other value sends them to the
        // wrong login and the connector can never be approved.
        $passportGuard = config('passport.guard');

        if ($passportGuard !== 'twill_users') {
            $this->error("  [XXX] passport.guard is \"{$passportGuard}\" — the MCP approval screen will not");
            $this->line('         recognise a logged-in Twill admin. Set it in config/passport.php:');
            $this->line("             'guard' => 'twill_users',");
            $this->line('         (If Passport also serves your own customer API, that API must move to');
            $this->line('          its own guard first — this setting is global to Passport.)');
        } else {
            $this->line('  [OK ] passport.guard is twill_users.');
        }

        $keyPath = storage_path('oauth-private.key');

        if (! is_readable($keyPath)) {
            $this->error('  [XXX] Passport keys are missing or unreadable — run: php artisan passport:keys');
        } else {
            $this->line('  [OK ] Passport keys are readable.');
        }
    }
}
