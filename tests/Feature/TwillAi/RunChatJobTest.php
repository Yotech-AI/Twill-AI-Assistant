<?php

use TwillAi\Agents\TwillAssistant;
use TwillAi\Jobs\RunTwillAiChat;
use TwillAi\Models\Chat;
use TwillAi\Models\ChatEvent;

it('streams the agent run into the event buffer and finishes idle', function () {
    TwillAssistant::fake(['Here is your draft.']);

    $user = twillAdmin('job-test@example.com');
    $chat = Chat::create([
        'user_id' => $user->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'status' => 'queued',
    ]);

    (new RunTwillAiChat($chat->id, $user->id, 'Make me a draft'))->handle();

    expect($chat->fresh()->status)->toBe('idle');

    $events = ChatEvent::where('chat_id', $chat->id)->orderBy('id')->get()
        ->map(fn (ChatEvent $event) => json_decode($event->event, true));

    expect($events->last()['type'])->toBe('twill_ai.turn_complete');

    $text = $events->where('type', 'text_delta')->pluck('delta')->implode('');
    expect($text)->toBe('Here is your draft.');
});

it('recovers with an error event when the run blows up', function () {
    $user = twillAdmin('job-error@example.com');
    $chat = Chat::create([
        'user_id' => $user->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-6',
        'status' => 'queued',
    ]);

    // Point the provider at an unreachable endpoint so the run fails fast and
    // deterministically (no real API call from the test suite).
    config()->set('ai.providers.anthropic.key', 'invalid');
    config()->set('ai.providers.anthropic.url', 'http://127.0.0.1:9/v1');

    (new RunTwillAiChat($chat->id, $user->id, 'Make me a draft'))->handle();

    expect($chat->fresh()->status)->toBe('idle');

    $events = ChatEvent::where('chat_id', $chat->id)->orderBy('id')->get()
        ->map(fn (ChatEvent $event) => json_decode($event->event, true));

    // laravel/ai error events carry provider-specific type strings, so match by
    // shape (message + recoverable) rather than a literal type.
    $errorEvents = $events->filter(fn (array $event) => isset($event['message'], $event['recoverable']));

    expect($errorEvents)->not->toBeEmpty()
        ->and($events->last()['type'])->toBe('twill_ai.turn_complete');
});
