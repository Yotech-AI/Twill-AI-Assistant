<?php

/**
 * App.vue mounts ChatPanel twice — once in the floating widget, once on the
 * full page — and the two drifted: the widget gained a history dropdown and a
 * header title without the listener that keeps them current, so every chat in
 * it read "New chat" forever.
 *
 * There is no JS test runner in this package, and adding one to guard a single
 * missing attribute is not worth it. A source-level assertion of the invariant
 * is: any ChatPanel that displays history must re-read it when a run ends.
 */
function chatPanelInstances(): array
{
    $source = file_get_contents(__DIR__.'/../../../resources/js/App.vue');

    preg_match_all('/<ChatPanel\b(.*?)\/>/s', $source, $matches);

    return $matches[1];
}

it('mounts ChatPanel more than once, which is why this file exists', function () {
    expect(chatPanelInstances())->toHaveCount(2);
});

it('refreshes the chat list on every panel when a run finishes', function () {
    // The title is only known AFTER the run: the chat has no conversation_id
    // until the job persists one, and until then Chat::title() reports the
    // "New chat" placeholder. A panel that never re-reads the list keeps that
    // placeholder for the life of the page.
    // Collected rather than asserted in the loop, so a failure names WHICH
    // instance drifted instead of stopping at the first one. (toContain takes
    // more values to find, not a failure message.)
    $missing = [];

    foreach (chatPanelInstances() as $index => $instance) {
        if (! str_contains($instance, '@stream-finished')) {
            $missing[] = $index;
        }
    }

    expect($missing)->toBe([]);
});
