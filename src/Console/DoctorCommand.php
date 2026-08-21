<?php

namespace TwillAi\Console;

use A17\Twill\Facades\TwillBlocks;
use Illuminate\Console\Command;
use Laravel\Mcp\Server;
use Laravel\Passport\Contracts\OAuthenticatable;
use Throwable;
use TwillAi\Models\TwillAiSetting;
use TwillAi\Seo\PublishedEditPolicy;
use TwillAi\Seo\SeoBridgeContract;
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
        $this->line('  api key:       '.$this->describeApiKey());

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
    /**
     * Where the API key actually comes from.
     *
     * Reading config alone was wrong: a key entered on the admin Settings
     * screen is encrypted into twill_ai_settings and only pushed into
     * `ai.providers.*.key` by SettingsService::applyRuntimeConfig() when a chat
     * runs. So the normal, fully-working setup reported MISSING, and the one
     * genuinely broken state — a key saved but never verified, which
     * applyRuntimeConfig refuses to apply — was indistinguishable from it.
     */
    protected function describeApiKey(): string
    {
        $stored = $this->storedSettings();

        if ($stored !== null && filled($stored->api_key)) {
            $masked = $stored->maskedKey() ?? 'set';

            if ($stored->verified_at === null) {
                return "admin settings, {$masked} — NOT VERIFIED, so it is never applied."
                    .' Re-save it on the Settings screen.';
            }

            if (empty($stored->available_models)) {
                return "admin settings, {$masked} — verified, but no model list is cached,"
                    .' so it is never applied. Run: php artisan twill-ai:refresh-models';
            }

            return "admin settings, {$masked} (verified {$stored->verified_at->toDateString()})";
        }

        // Second source: a host that never opens the Settings screen and wires
        // the key through config/ai.php or the environment instead.
        $provider = $stored?->provider ?: 'anthropic';

        if (filled(config("ai.providers.{$provider}.key"))) {
            return "config, ai.providers.{$provider}.key";
        }

        return 'MISSING — add it in the admin under Twill AI > Settings, or set it in config/ai.php';
    }

    /**
     * Read-only on purpose. A diagnostic must not create the settings row, and
     * must still run on a site whose migrations have not been run yet.
     */
    protected function storedSettings(): ?TwillAiSetting
    {
        try {
            return TwillAiSetting::query()->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The SEO integration is gated on a config flag AND the Suite being
     * installed, and it moves a safety default — whether the agent may edit
     * entries a human already published. Both belong in a wiring report:
     * "which of the two reasons is it off for" and "can it touch live pages"
     * are the questions this integration actually raises.
     */
    protected function reportSeo(): void
    {
        $available = app(SeoBridgeContract::class)->available();

        if ($available) {
            $this->line('  [OK ] SEO Suite integration is on — get_seo, analyze_seo_text and update_seo added.');
        } elseif (! config('twill-ai.seo.enabled', true)) {
            $this->line('  [ - ] SEO Suite integration is disabled (twill-ai.seo.enabled is false).');
        } else {
            $this->line('  [ - ] SEO Suite integration is off — yotech-ai/twill-cms-seo-suite is not installed.');
        }

        // Spelled out rather than dumped, because `null` is the default and it
        // means "ask the Suite" — printing the raw value explains nothing.
        $configured = config('twill-ai.allow_updating_published');
        $allows = app(PublishedEditPolicy::class)->allows();

        $because = match (true) {
            is_bool($configured) => 'allow_updating_published is set to '.($configured ? 'true' : 'false'),
            $available => 'allow_updating_published is null and the Suite is installed',
            default => 'allow_updating_published is null and the Suite is not installed',
        };

        $this->line(sprintf(
            '  [%s] editing ALREADY PUBLISHED entries is %s — %s.',
            $allows ? ' ! ' : 'OK ',
            $allows ? 'PERMITTED' : 'refused',
            $because
        ));

        if ($allows) {
            $this->line('         New entries are still created as drafts, and no tool can change');
            $this->line('         any entry\'s publish state. Set allow_updating_published to false');
            $this->line('         in config/twill-ai.php to refuse live edits as well.');
        }
    }

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

        $this->reportSeo();
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
