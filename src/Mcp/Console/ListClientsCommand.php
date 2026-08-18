<?php

namespace TwillAi\Mcp\Console;

use TwillAi\Mcp\Models\McpClient;
use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\Token;

class ListClientsCommand extends Command
{
    protected $signature = 'mcp:client-list
                            {--pending : Also list OAuth clients that registered themselves but are not allow-listed}';

    protected $description = 'List registered MCP connectors, their live token count and last use';

    public function handle(): int
    {
        $this->listRegistered();

        if ($this->option('pending')) {
            $this->newLine();
            $this->listPending();
        }

        return self::SUCCESS;
    }

    protected function listRegistered(): void
    {
        $clients = McpClient::query()->with('twillUser')->get();

        if ($clients->isEmpty()) {
            $this->warn('No MCP connectors. Register one with: php artisan mcp:client-create "Claude Cowork"');

            return;
        }

        $this->table(
            ['ID', 'Name', 'OAuth client', 'Attributed to', 'Live tokens', 'Last used'],
            $clients->map(fn (McpClient $client) => [
                $client->id,
                $client->name,
                $client->oauth_client_id ?? '(none)',
                $client->twillUser?->email ?? '(none — will be refused)',
                $this->liveTokenCount($client),
                $client->last_used_at?->diffForHumans() ?? 'never',
            ])->all(),
        );
    }

    /**
     * OAuth clients Passport created through dynamic registration that nobody
     * has allow-listed. These can complete an approval flow but are refused at
     * the endpoint, so they are worth surfacing.
     */
    protected function listPending(): void
    {
        $registered = McpClient::query()
            ->whereNotNull('oauth_client_id')
            ->pluck('oauth_client_id');

        $pending = Client::query()->whereNotIn('id', $registered)->get();

        if ($pending->isEmpty()) {
            $this->info('No unregistered OAuth clients.');

            return;
        }

        $this->warn('Self-registered OAuth clients with no MCP connector record (currently refused):');

        $this->table(
            ['OAuth client', 'Name', 'Registered'],
            $pending->map(fn (Client $client) => [
                $client->getKey(),
                $client->name,
                $client->created_at?->diffForHumans() ?? 'unknown',
            ])->all(),
        );

        $this->line('Adopt one with: php artisan mcp:client-create "Claude Cowork" --oauth-client=<id>');
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
}
