<?php

use A17\Twill\Facades\TwillBlocks;
use Illuminate\Support\Facades\Facade;
use TwillAi\Services\BlockSchemaService;
use TwillAi\Services\ModuleRegistry;

it('keeps Twill\'s block registry intact across queue worker job boundaries', function () {
    $blocks = app(BlockSchemaService::class);

    // Any block the registry says the agent may use (keeps this test portable
    // across projects — no hard-coded block names).
    $sample = collect(app(ModuleRegistry::class)->all())
        ->flatMap(fn (array $module) => collect($module['block_editors'] ?? [])->flatten())
        ->unique()
        ->first();

    expect($sample)->not->toBeNull();

    // First run resolves fine and builds (and thereby consumes) Twill's
    // block-directory registration.
    expect($blocks->blockExists($sample))->toBeTrue();

    // A long-running queue:work worker clears resolved facade instances between
    // jobs; Twill then rebuilds its (already-consumed) registry as empty. This
    // is the production bug — block creation fails from the 2nd agent run on.
    Facade::clearResolvedInstances();
    expect(TwillBlocks::getBlocks()->count())->toBe(0);

    // Each agent run re-seeds the registry from the boot-time snapshot
    // (RunTwillAiChat::handle calls this), restoring it fully.
    $blocks->ensureRegistered();

    expect($blocks->blockExists($sample))->toBeTrue()
        ->and(TwillBlocks::getBlocks()->count())->toBeGreaterThan(0);
});
