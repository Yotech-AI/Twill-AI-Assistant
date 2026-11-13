<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Once;
use Laravel\Passport\Client;
use TwillAi\Mcp\Models\McpClient;

/*
 * The Connectors page in Twill: every connector, who approved it and when,
 * Revoke, and "Add an app by hand" for apps that cannot register themselves.
 */

function connectorsPage(): string
{
    return twillAiUrl('connectors');
}

function pageEditor()
{
    return twillAdmin('editor@example.test', 'PUBLISHER');
}

/**
 * An app that registered itself and was approved on the connector's screen.
 *
 * @return array{0: string, 1: string} client id and access token
 */
function connectedApp($editor, string $name, string $redirectUri): array
{
    $clientId = (string) test()->postJson('/oauth/register', ['client_name' => $name, 'redirect_uris' => [$redirectUri]])
        ->assertSuccessful()
        ->json('client_id');

    [$verifier, $challenge] = connectorPkce();
    $approval = approveConnector($editor, $clientId, $redirectUri, $challenge)->assertRedirect();

    return [$clientId, exchangeAuthorizationCode($clientId, $redirectUri, (string) authorizationCode($approval), $verifier)];
}

it('lists every connector with its host, approver, last use and live tokens', function () {
    test()->artisan('mcp:client-create', ['name' => 'Claude Cowork'])->assertSuccessful();

    $editor = pageEditor();
    connectedApp($editor, 'ChatGPT', 'https://chatgpt.com/connector_platform_oauth_redirect');

    test()->actingAs($editor, 'twill_users')
        ->get(connectorsPage())
        ->assertOk()
        ->assertSeeInOrder(['Claude Cowork', 'claude.ai', 'artisan', 'never', '0'])
        ->assertSeeInOrder(['ChatGPT', 'chatgpt.com', 'Admin', 'never', '1']);
});

it('is only for users who may approve a connector', function () {
    test()->actingAs(twillAdmin('viewer@example.test', 'VIEWONLY'), 'twill_users')
        ->get(connectorsPage())
        ->assertForbidden()
        ->assertSee('Ask a Twill editor or admin');
});

it('cuts a connector off when it is revoked on the page', function () {
    $editor = pageEditor();
    [$clientId, $token] = connectedApp($editor, 'Claude', 'https://claude.ai/api/mcp/auth_callback');

    mcpRpc($token, 'tools/list')->assertOk();

    $connector = McpClient::query()->where('oauth_client_id', $clientId)->sole();

    test()->actingAs($editor, 'twill_users')
        ->post(route('twill.ai.connectors.revoke', $connector))
        ->assertRedirect(connectorsPage());

    // A real server starts every request with fresh guards and clears
    // Passport's once() lookups; the test runs every request in one process.
    Auth::forgetGuards();
    Once::flush();

    expect(mcpRpc($token, 'tools/list')->status())->toBeIn([401, 403]);
});

it('adds an app by hand, shows its ID and secret once, and the app works after approval', function () {
    $editor = pageEditor();
    $redirectUri = 'https://chat.mistral.ai/connector/callback';

    test()->actingAs($editor, 'twill_users')
        ->post(route('twill.ai.connectors.store'), [
            'name' => 'Le Chat',
            'redirect_uris' => "{$redirectUri}\n",
        ])
        ->assertRedirect(connectorsPage());

    $connector = McpClient::query()->sole();
    $clientId = (string) $connector->oauth_client_id;

    $page = test()->actingAs($editor, 'twill_users')->get(connectorsPage())->assertOk()->getContent();

    expect(preg_match('/data-client-secret>([^<]+)</', $page, $match))->toBe(1)
        ->and($page)->toContain($clientId);

    $secret = trim($match[1]);

    test()->actingAs($editor, 'twill_users')->get(connectorsPage())->assertOk()->assertDontSee($secret);

    expect($connector->approved_by)->toBe($editor->id)
        ->and(Client::query()->findOrFail($clientId)->provider)->toBe('twill_users');

    [$verifier, $challenge] = connectorPkce();
    $approval = approveConnector($editor, $clientId, $redirectUri, $challenge)->assertRedirect();
    $token = exchangeAuthorizationCode($clientId, $redirectUri, (string) authorizationCode($approval), $verifier, $secret);

    mcpRpc($token, 'tools/list')->assertOk();
});

it('refuses a hand-added app whose redirect URI OAuth 2.1 does not allow', function () {
    test()->actingAs(pageEditor(), 'twill_users')
        ->from(connectorsPage())
        ->post(route('twill.ai.connectors.store'), [
            'name' => 'Sketchy',
            'redirect_uris' => 'http://example.com/cb',
        ])
        ->assertRedirect(connectorsPage())
        ->assertSessionHasErrors('redirect_uris');

    expect(McpClient::query()->count())->toBe(0);
});

/**
 * The URLs the Twill AI page hands its Vue app, as the page renders them.
 *
 * @return array<string, string>
 */
function aiPageUrls($user): array
{
    $html = (string) test()->actingAs($user, 'twill_users')->get(twillAiUrl())->assertOk()->getContent();

    preg_match("/data-twill-ai-config='([^']+)'/", $html, $match);

    return json_decode(html_entity_decode($match[1] ?? '{}', ENT_QUOTES), true)['urls'] ?? [];
}

it('links the Connectors page from the Twill AI page, for users who may approve', function () {
    expect(aiPageUrls(pageEditor()))->toHaveKey('connectors', route('twill.ai.connectors.index'))
        ->and(aiPageUrls(twillAdmin('viewer@example.test', 'VIEWONLY')))->not->toHaveKey('connectors');
});
