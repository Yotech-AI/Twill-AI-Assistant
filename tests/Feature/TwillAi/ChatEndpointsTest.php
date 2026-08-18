<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use TwillAi\Jobs\RunTwillAiChat;
use TwillAi\Models\Chat;
use TwillAi\Models\ChatEvent;

// NOTE: the floating-widget case that lived here in pomofit (asserting against
// its own /admin/pages module) moved to WidgetComposerTest, which drives it from
// a Twill built-in route instead of a host module.

it('redirects guests to the Twill login', function () {
    $this->get(twillAiUrl())->assertRedirect();
    $this->getJson(twillAiUrl('chats'))->assertStatus(401);
});

it('renders the Twill AI page for admins', function () {
    $this->actingAs(twillAdmin(), 'twill_users')
        ->get(twillAiUrl())
        ->assertOk()
        ->assertSee('twill-ai-page', false);
});

it('creates, lists, shows, renames and deletes chats', function () {
    $this->actingAs(twillAdmin(), 'twill_users');

    $created = $this->postJson(twillAiUrl('chats'), [])->assertCreated()->json();

    expect($created['model_id'])->toBe(config('twill-ai.default_model'));

    $this->getJson(twillAiUrl('chats'))
        ->assertOk()
        ->assertJsonCount(1, 'chats');

    $this->getJson(twillAiUrl("chats/{$created['id']}"))
        ->assertOk()
        ->assertJsonPath('messages', []);

    $this->patchJson(twillAiUrl("chats/{$created['id']}"), ['model' => 'anthropic:claude-haiku-4-5'])
        ->assertOk()
        ->assertJsonPath('model_id', 'anthropic:claude-haiku-4-5');

    $this->deleteJson(twillAiUrl("chats/{$created['id']}"))
        ->assertOk();

    expect(Chat::count())->toBe(0);
});

it('rejects models that are not whitelisted', function () {
    $this->actingAs(twillAdmin(), 'twill_users');

    $this->postJson(twillAiUrl('chats'), ['model' => 'anthropic:claude-2.0'])
        ->assertStatus(422);
});

it('scopes chats to their owner', function () {
    $owner = twillAdmin('owner@example.com');
    $other = twillAdmin('other@example.com');

    $chat = Chat::create([
        'user_id' => $owner->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
    ]);

    $this->actingAs($other, 'twill_users')
        ->getJson(twillAiUrl("chats/{$chat->id}"))
        ->assertNotFound();

    $this->actingAs($other, 'twill_users')
        ->deleteJson(twillAiUrl("chats/{$chat->id}"))
        ->assertNotFound();
});

it('validates the message body', function () {
    $user = twillAdmin();

    $chat = Chat::create([
        'user_id' => $user->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
    ]);

    $this->actingAs($user, 'twill_users')
        ->postJson(twillAiUrl("chats/{$chat->id}/messages"), ['message' => ''])
        ->assertStatus(422);
});

it('queues the agent run on the dedicated queue and buffers the user message', function () {
    Queue::fake();

    $user = twillAdmin();
    $chat = Chat::create(['user_id' => $user->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);

    $this->actingAs($user, 'twill_users')
        ->postJson(twillAiUrl("chats/{$chat->id}/messages"), ['message' => 'Hello there'])
        ->assertStatus(202)
        ->assertJsonPath('queued', true)
        ->assertJsonPath('status', 'queued');

    Queue::assertPushedOn('twill-ai', RunTwillAiChat::class);

    expect($chat->fresh()->status)->toBe('queued');

    $buffered = ChatEvent::where('chat_id', $chat->id)->get();
    expect($buffered)->toHaveCount(1)
        ->and(json_decode($buffered->first()->event, true))
        ->toMatchArray(['type' => 'twill_ai.user_message', 'content' => 'Hello there', 'attachments' => [], 'mentions' => []]);
});

it('refuses a new message while a run is in flight', function () {
    Queue::fake();

    $user = twillAdmin();
    $chat = Chat::create(['user_id' => $user->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'status' => 'streaming']);

    $this->actingAs($user, 'twill_users')
        ->postJson(twillAiUrl("chats/{$chat->id}/messages"), ['message' => 'Another one'])
        ->assertStatus(409);

    Queue::assertNothingPushed();
});

it('serves buffered events incrementally and scopes them to the owner', function () {
    $owner = twillAdmin('owner-events@example.com');
    $other = twillAdmin('other-events@example.com');

    $chat = Chat::create(['user_id' => $owner->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);

    ChatEvent::record($chat->id, ['type' => 'text_delta', 'delta' => 'Hi']);
    ChatEvent::record($chat->id, ['type' => 'twill_ai.turn_complete']);

    $response = $this->actingAs($owner, 'twill_users')
        ->getJson(twillAiUrl("chats/{$chat->id}/events?after=0"))
        ->assertOk()
        ->json();

    expect($response['events'])->toHaveCount(2)
        ->and($response['events'][0]['data']['type'])->toBe('text_delta');

    $afterFirst = $this->actingAs($owner, 'twill_users')
        ->getJson(twillAiUrl("chats/{$chat->id}/events?after={$response['events'][0]['id']}"))
        ->json();

    expect($afterFirst['events'])->toHaveCount(1);

    $this->actingAs($other, 'twill_users')
        ->getJson(twillAiUrl("chats/{$chat->id}/events?after=0"))
        ->assertNotFound();
});

it('sets the cancel flag for a busy chat', function () {
    $user = twillAdmin();
    $chat = Chat::create(['user_id' => $user->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'status' => 'streaming']);

    $this->actingAs($user, 'twill_users')
        ->postJson(twillAiUrl("chats/{$chat->id}/cancel"))
        ->assertStatus(202);

    expect(Cache::get(Chat::cancelCacheKey($chat->id)))->toBeTrue();
});

it('treats a stale busy flag as idle so chats never lock up', function () {
    $user = twillAdmin();
    $chat = Chat::create(['user_id' => $user->id, 'provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'status' => 'streaming']);

    Chat::where('id', $chat->id)->update(['updated_at' => now()->subHours(2)]);

    expect($chat->fresh()->isBusy())->toBeFalse()
        ->and($chat->fresh()->effectiveStatus())->toBe('idle');
});

it('exposes the model whitelist via bootstrap', function () {
    $this->actingAs(twillAdmin(), 'twill_users')
        ->getJson(twillAiUrl('bootstrap'))
        ->assertOk()
        ->assertJsonPath('default_model', config('twill-ai.default_model'))
        ->assertJsonStructure(['models' => [['id', 'label']], 'title', 'active_chat']);
});
