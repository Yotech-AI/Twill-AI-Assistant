<?php

namespace TwillAi\Mcp;

use A17\Twill\Models\Enums\UserRole;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use TwillAi\Mcp\Console\CreateClientCommand;
use TwillAi\Mcp\Models\McpClient;
use TwillAi\Models\TwillUser;

/**
 * Makes connectors from the CMS: an app a Twill user approved after it
 * registered itself, and an app added by hand on the Connectors page.
 *
 * Each gets what `php artisan mcp:client-create` gives a connector: its own
 * password-less, unpublished, view-only Twill user to attribute drafts to, and
 * an OAuth client bound to the CMS user provider. It also records who approved
 * it and when.
 */
class ConnectorRegistrar
{
    public function __construct(protected ClientRepository $clients) {}

    /**
     * Register an app that registered itself through /oauth/register.
     */
    public function approve(Client $client, Authenticatable $approver): McpClient
    {
        return DB::transaction(fn () => $this->register($client, self::appName($client), $approver));
    }

    /**
     * Add an app that cannot register itself. Its secret is in plainSecret on
     * the returned client, and nowhere else.
     *
     * @param  list<string>  $redirectUris
     * @return array{0: McpClient, 1: Client}
     */
    public function addByHand(string $name, array $redirectUris, Authenticatable $approver): array
    {
        return DB::transaction(function () use ($name, $redirectUris, $approver) {
            $client = $this->clients->createAuthorizationCodeGrantClient($name, $redirectUris);

            return [$this->register($client, $name, $approver), $client];
        });
    }

    /**
     * Whether approving this client may register it as a connector: only what
     * /oauth/register makes, a public client for the authorization code grant
     * that no other user provider claimed. A host's own OAuth clients, which
     * have a secret or a provider of their own, never become connectors this
     * way, even if someone approves one on the connector's screen.
     */
    public static function canSelfRegister(Client $client): bool
    {
        return ! $client->confidential()
            && in_array('authorization_code', (array) $client->grant_types, true)
            && in_array($client->provider, [null, CreateClientCommand::connectorProvider()], true);
    }

    /**
     * The name an app gave itself, or where its access goes when it gave none.
     */
    public static function appName(Client $client): string
    {
        $name = trim((string) $client->name);

        if ($name !== '') {
            return $name;
        }

        $first = $client->redirect_uris[0] ?? null;

        return is_string($first) && RedirectUri::host($first) !== ''
            ? RedirectUri::host($first)
            : 'MCP client';
    }

    protected function register(Client $client, string $name, Authenticatable $approver): McpClient
    {
        // As mcp:client-create does: Passport's token guard then refuses this
        // client's tokens on any guard with another provider.
        $client->forceFill(['provider' => CreateClientCommand::connectorProvider()])->save();

        return McpClient::create([
            'name' => Str::limit($name, 255, ''),
            'oauth_client_id' => $client->getKey(),
            'twill_user_id' => $this->attributionUser($name, $approver)->id,
            'approved_by' => $approver->getAuthIdentifier(),
            'approved_at' => now(),
        ]);
    }

    /**
     * The connector's own Twill user, made as mcp:client-create makes one, so
     * every draft it writes is credited to it and it can never sign in.
     */
    protected function attributionUser(string $name, Authenticatable $approver): mixed
    {
        $userClass = config('twill.models.user', TwillUser::class);
        $approvedBy = filled($approver->name ?? null) ? $approver->name : ($approver->email ?? 'a Twill user');

        $user = new $userClass;
        $user->name = Str::limit("{$name} (approved by {$approvedBy})", 255, '');
        $user->email = $this->uniqueEmail($name, $userClass);
        // The enum's key, which is what Twill stores and reads back through
        // role_value. (UserRole::VIEWONLY is the label, "View only".)
        $user->role = UserRole::VIEWONLY()->getKey();
        $user->password = null;
        $user->published = false;
        $user->save();

        return $user;
    }

    /**
     * mcp+<app>-<random>@<APP_URL host>: never a real mailbox, and unique,
     * because every approval gets a user of its own.
     */
    protected function uniqueEmail(string $name, string $userClass): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'example.com';
        $slug = Str::limit(Str::slug($name) ?: 'app', 40, '');

        do {
            $email = 'mcp+'.$slug.'-'.Str::lower(Str::random(6)).'@'.$host;
        } while ($userClass::withTrashed()->where('email', $email)->exists());

        return $email;
    }
}
