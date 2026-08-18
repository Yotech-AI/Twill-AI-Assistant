<?php

namespace TwillAi\Console;

use Illuminate\Console\Command;
use TwillAi\TwillAiServiceProvider;

/**
 * One-step setup for a host application.
 *
 * The package fills the host's framework config at runtime (disk, queue and —
 * with MCP on — the auth guard), so this command mostly publishes the module
 * registry and tells the operator exactly what is left to do by hand.
 */
class InstallCommand extends Command
{
    protected $signature = 'twill-ai:install {--force : Overwrite an existing config/twill-ai.php}';

    protected $description = 'Publish the Twill AI config and report the remaining setup steps.';

    public function handle(): int
    {
        $this->info('Installing Twill AI');
        $this->newLine();

        $this->call('vendor:publish', array_filter([
            '--tag' => 'twill-ai-config',
            '--force' => $this->option('force') ?: null,
        ]));

        $filled = $this->filledConfigKeys();

        if ($filled !== []) {
            $this->newLine();
            $this->line('Registered automatically (your own config always wins):');

            foreach ($filled as $key) {
                $this->line('  - ' . $key);
            }
        }

        $this->newLine();
        $this->line('Remaining steps:');
        $this->line('  1. php artisan migrate');
        $this->line('  2. Describe your modules in config/twill-ai.php — the agent can only see what is listed there.');
        $this->line('  3. Run a queue worker for the chat:');
        $this->line('       php artisan queue:work twill-ai --queue=twill-ai --timeout='
            . ((int) config('twill-ai.timeout', 600) + 20));
        $this->line('  4. Open the admin, go to ' . config('twill-ai.ui.title', 'Twill AI')
            . ' → Settings and save a provider API key.');

        if (config('twill-ai.mcp.enabled')) {
            $this->line('  5. MCP is enabled: run php artisan passport:keys, then twill-ai:mcp-client to authorise a connector.');
        } else {
            $this->newLine();
            $this->line('The MCP connector is off. To enable it, install laravel/mcp and laravel/passport,');
            $this->line('then set TWILL_AI_MCP_ENABLED=true.');
        }

        $this->newLine();
        $this->line('Verify anytime with: php artisan twill-ai:doctor');

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    protected function filledConfigKeys(): array
    {
        foreach ($this->laravel->getLoadedProviders() as $provider => $loaded) {
            if ($provider === TwillAiServiceProvider::class && $loaded) {
                $instance = $this->laravel->getProvider(TwillAiServiceProvider::class);

                return $instance instanceof TwillAiServiceProvider ? $instance->filledConfigKeys() : [];
            }
        }

        return [];
    }
}
