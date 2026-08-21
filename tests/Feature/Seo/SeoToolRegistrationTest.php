<?php

use TwillAi\Agents\TwillAssistant;
use TwillAi\Models\Chat;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Tests\Fixtures\FakeSeoBridge;

function assistantToolNames(): array
{
    $chat = new Chat(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);

    return collect((new TwillAssistant($chat))->tools())
        ->map(fn ($tool) => $tool->name())
        ->all();
}

it('offers the SEO tools when the bridge reports availability', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    expect(assistantToolNames())->toContain('get_seo', 'analyze_seo_text', 'update_seo');
});

it('offers none of them when it does not, and still works', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(assistantToolNames())
        ->not->toContain('get_seo')
        ->not->toContain('analyze_seo_text')
        ->not->toContain('update_seo')
        // The assistant is otherwise untouched — SEO is additive, never a
        // precondition for the content tools.
        ->toContain('update_content', 'create_content', 'get_content');
});

it('still exposes nothing that can publish or delete, in either state', function (bool $available) {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge($available));

    foreach (assistantToolNames() as $name) {
        expect(preg_match('/delete|destroy|remove|publish|restore/i', $name))->toBe(
            0,
            "Tool {$name} looks like it can delete or publish — that is forbidden."
        );
    }
})->with([true, false]);
