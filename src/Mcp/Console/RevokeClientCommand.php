<?php

namespace TwillAi\Mcp\Console;

use Illuminate\Console\Command;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;
use TwillAi\Mcp\Models\McpClient;

class RevokeClientCommand extends Command
{
    protected $signature = 'mcp:client-revoke
                            {id : The MCP connector id (see mcp:client-list)}
                            {--delete : Also delete the connector record and its OAuth client}';

    protected $description = 'Revoke an MCP connector\'s OAuth tokens, immediately cutting off its access';

    public function handle(): int
    {
        $client = McpClient::find($this->argument('id'));

        if ($client === null) {
            $this->error("No MCP connector with id {$this->argument('id')}.");

            return self::FAILURE;
        }

        if ($client->oauth_client_id === null) {
            $this->warn("\"{$client->name}\" has no OAuth client, so there is nothing to revoke.");
        } else {
            $this->revokeTokens($client->oauth_client_id);
        }

        if ($this->option('delete')) {
            $this->deleteClient($client);
        }

        return self::SUCCESS;
    }

    /**
     * Revoke access tokens and the refresh tokens that could mint new ones.
     * Passport checks the revoked flag on every request, so this takes effect
     * on the connector's next call rather than at expiry.
     */
    protected function revokeTokens(string $oauthClientId): void
    {
        $tokenIds = Token::query()
            ->where('client_id', $oauthClientId)
            ->where('revoked', false)
            ->pluck('id');

        $accessRevoked = Token::query()
            ->whereIn('id', $tokenIds)
            ->update(['revoked' => true]);

        $refreshRevoked = RefreshToken::query()
            ->whereIn('access_token_id', $tokenIds)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        $this->info("Revoked {$accessRevoked} access token(s) and {$refreshRevoked} refresh token(s). Access stops immediately.");
    }

    protected function deleteClient(McpClient $client): void
    {
        if ($client->oauth_client_id !== null) {
            Client::query()->whereKey($client->oauth_client_id)->update(['revoked' => true]);
            $this->info('OAuth client revoked, so it cannot be re-approved.');
        }

        $client->delete();

        $this->info('Connector record deleted. The Twill attribution user was kept so existing revisions stay attributable.');
    }
}
