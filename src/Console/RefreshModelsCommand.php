<?php

namespace TwillAi\Console;

use TwillAi\Services\SettingsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Refreshes the configured provider's model list. Run on a schedule (e.g.
 * weekly) so new models appear without re-saving the API key.
 */
class RefreshModelsCommand extends Command
{
    protected $signature = 'twill-ai:refresh-models';

    protected $description = "Refresh the configured provider's model list for Twill AI.";

    public function handle(SettingsService $settings): int
    {
        if (! $settings->isConfigured()) {
            $this->info('Twill AI has no verified provider key; nothing to refresh.');

            return self::SUCCESS;
        }

        try {
            $settings->refreshModels();
            $this->info('Refreshed the Twill AI model list.');
        } catch (Throwable $e) {
            $this->error('Refresh failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
