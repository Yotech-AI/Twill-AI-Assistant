<?php

namespace TwillAi\Mcp\Console;

use A17\Twill\Models\Enums\UserRole;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Models\TwillUser;

class CreateClientCommand extends Command
{
    /**
     * Anthropic's callback for custom MCP connectors. Only a default: it is not
     * contractual, and a connector that self-registers supplies its own. Pass
     * --redirect if Cowork reports a redirect_uri mismatch.
     */
    protected const CLAUDE_REDIRECT_URI = 'https://claude.ai/api/mcp/auth_callback';

    protected $signature = 'mcp:client-create
                            {name : A label for the client, e.g. "Claude Cowork"}
                            {--email= : Email for the attribution user (defaults to a derived, non-login address)}
                            {--redirect=* : OAuth redirect URI(s); defaults to Anthropic\'s connector callback}
                            {--oauth-client= : Adopt an existing self-registered OAuth client by id instead of creating one}';

    protected $description = 'Register an MCP connector: an OAuth client plus the Twill user its content is attributed to';

    public function handle(ClientRepository $clients): int
    {
        $name = (string) $this->argument('name');
        $email = (string) ($this->option('email') ?: $this->defaultEmail($name));

        $twillUser = $this->resolveAttributionUser($name, $email);

        $adopting = (string) ($this->option('oauth-client') ?: '');

        $client = $adopting !== ''
            ? $this->adoptClient($clients, $adopting)
            : $clients->createAuthorizationCodeGrantClient(
                $name,
                $this->redirectUris(),
            );

        if ($client === null) {
            return self::FAILURE;
        }

        if (McpClient::query()->where('oauth_client_id', $client->getKey())->exists()) {
            $this->error("OAuth client {$client->getKey()} is already registered as an MCP connector.");

            return self::FAILURE;
        }

        $mcpClient = McpClient::create([
            'name' => $name,
            'oauth_client_id' => $client->getKey(),
            'twill_user_id' => $twillUser->id,
        ]);

        $this->report($mcpClient, $client, $adopting !== '');

        return self::SUCCESS;
    }

    /**
     * Allow-list an OAuth client that registered itself through Passport's
     * dynamic client registration endpoint.
     */
    protected function adoptClient(ClientRepository $clients, string $id): ?Client
    {
        $client = $clients->find($id);

        if ($client === null) {
            $this->error("No OAuth client with id {$id}. Check php artisan mcp:client-list --pending.");

            return null;
        }

        return $client;
    }

    /**
     * @return string[]
     */
    protected function redirectUris(): array
    {
        $supplied = (array) $this->option('redirect');

        return $supplied === [] ? [self::CLAUDE_REDIRECT_URI] : $supplied;
    }

    protected function resolveAttributionUser(string $name, string $email)
    {
        $userClass = config('twill.models.user', TwillUser::class);

        $twillUser = $userClass::withTrashed()->firstWhere('email', $email);

        if ($twillUser !== null) {
            $this->info("Reusing existing Twill user: {$email}");

            return $twillUser;
        }

        $twillUser = new $userClass;
        $twillUser->name = $name;
        $twillUser->email = $email;
        $twillUser->role = UserRole::VIEWONLY;
        // No password: this account exists to attribute content, and must
        // never be able to sign in to the admin.
        $twillUser->password = null;
        $twillUser->published = false;
        $twillUser->save();

        $this->info("Created Twill attribution user: {$email}");

        return $twillUser;
    }

    protected function report(McpClient $mcpClient, Client $client, bool $adopted): void
    {
        $this->newLine();
        $this->info("MCP connector #{$mcpClient->id} \"{$mcpClient->name}\" registered.");
        $this->newLine();

        if ($adopted) {
            $this->line('Adopted the existing OAuth client, so no new secret was issued.');
            $this->line('  Client ID : '.$client->getKey());
        } else {
            $this->line('Give the connector administrator these values:');
            $this->line('  URL           : '.url('mcp/twill'));
            $this->line('  Client ID     : '.$client->getKey());
            $this->line('  Client Secret : '.($client->plainSecret ?? '(none — public client)'));
            $this->newLine();
            $this->line('The secret is shown once. Store it in a password manager.');
        }

        $this->newLine();
        $this->line('A Twill admin must then approve the connector in the browser when prompted.');
        $this->warn('Anyone completing that approval can create drafts in the CMS. It cannot publish or delete.');
    }

    protected function defaultEmail(string $name): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';

        return 'mcp+'.Str::slug($name).'@'.$host;
    }
}
