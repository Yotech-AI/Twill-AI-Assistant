<?php

use TwillAi\TwillAiServiceProvider;

/**
 * The package fills a handful of host framework config keys so that a plain
 * `composer require` is enough. The contract is narrow and load-bearing: it only
 * ever fills keys that are ABSENT, and it records what it filled so
 * `twill-ai:doctor` can report it. A host that configured its own disk or queue
 * must always win.
 */
it('fills the private upload disk when the host has none', function () {
    expect(config('filesystems.disks.twill-ai.driver'))->toBe('local')
        // Never a public URL: chat attachments are not web-reachable.
        ->and(config('filesystems.disks.twill-ai.url'))->toBeNull();
});

it('fills the dedicated queue connection with a retry window wider than the job timeout', function () {
    $connection = config('queue.connections.twill-ai');

    expect($connection['driver'])->toBe('database')
        ->and($connection['queue'])->toBe('twill-ai')
        // If retry_after ever drops below the timeout, the queue re-dispatches a
        // run that is still in flight and the agent silently works twice.
        ->and($connection['retry_after'])->toBeGreaterThan((int) config('twill-ai.timeout', 600));
});

it('records the keys it filled so doctor can report them', function () {
    $provider = app()->getProvider(TwillAiServiceProvider::class);

    expect($provider->filledConfigKeys())
        ->toContain('filesystems.disks.twill-ai')
        ->toContain('queue.connections.twill-ai');
});

/**
 * Asserted against the fill primitive itself rather than by booting a second
 * application, because that is where the "absent keys only" rule actually lives
 * — every caller inherits it, including any added later.
 */
it('never overwrites a value the host already set', function () {
    $fill = fillConfigOn(app()->getProvider(TwillAiServiceProvider::class));

    config()->set('filesystems.disks.probe-host-owned', ['driver' => 'local', 'root' => '/host/owned']);

    $fill('filesystems.disks.probe-host-owned', ['driver' => 'local', 'root' => '/package/default']);

    expect(config('filesystems.disks.probe-host-owned.root'))->toBe('/host/owned');
});

it('fills a key the host left absent', function () {
    $fill = fillConfigOn(app()->getProvider(TwillAiServiceProvider::class));

    $fill('filesystems.disks.probe-absent', ['driver' => 'local', 'root' => '/package/default']);

    expect(config('filesystems.disks.probe-absent.root'))->toBe('/package/default');
});

it('treats an explicit null as absent', function () {
    // A host that publishes a config stub with the key present but null gets
    // the package default rather than a broken null.
    $fill = fillConfigOn(app()->getProvider(TwillAiServiceProvider::class));

    config()->set('filesystems.disks.probe-null', null);

    $fill('filesystems.disks.probe-null', ['driver' => 'local', 'root' => '/package/default']);

    expect(config('filesystems.disks.probe-null.root'))->toBe('/package/default');
});

function fillConfigOn(object $provider): Closure
{
    $method = new ReflectionMethod($provider, 'fillConfig');
    $method->setAccessible(true);

    return fn (string $key, mixed $value) => $method->invoke($provider, $key, $value);
}
