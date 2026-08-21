<?php

use TwillAi\Agents\TwillAssistant;
use TwillAi\Models\Chat;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PromptComposer;
use TwillAi\Tests\Fixtures\FakeSeoBridge;
use TwillAi\Tests\Fixtures\Models\SeoArticle;

it('reports which modules have an SEO surface', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));

    // Registered here rather than in the shared fixture registry: SeoArticle
    // pulls in a TwillSeo trait, and the shared registry is loaded by every
    // test — including the CI job that runs without the Suite installed.
    config()->set('twill-ai.modules', array_merge(config('twill-ai.modules'), [
        'seoArticles' => array_merge(config('twill-ai.modules.articles'), [
            'label' => 'SEO Articles',
            'model' => SeoArticle::class,
        ]),
    ]));

    $registry = app(ModuleRegistry::class);

    // SeoArticle uses HasSeo; Singleton deliberately does not, so "this module
    // has no SEO surface" has a real subject rather than a hypothetical one.
    expect($registry->describe('seoArticles')['seo']['available'])->toBeTrue()
        ->and($registry->describe('singleton')['seo']['available'])->toBeFalse();
});

it('omits the key entirely without the Suite', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));

    expect(app(ModuleRegistry::class)->describe('articles'))->not->toHaveKey('seo');
});

it('adds SEO guidance to the prompt only when available', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));
    expect(app(PromptComposer::class)->seoGuidance())
        ->toContain('analyze_seo_text')
        ->toContain('was_published');

    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));
    expect(app(PromptComposer::class)->seoGuidance())->toBe('');
});

it('lets a host override the SEO fragment like any other', function () {
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));
    config()->set('twill-ai.prompts.seo', 'Site-specific SEO rules.');

    expect(app(PromptComposer::class)->seoGuidance())->toBe('Site-specific SEO rules.');
});

it('keeps SEO out of the assistant instructions without the Suite', function () {
    $chat = new Chat(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);

    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: true));
    expect((new TwillAssistant($chat))->instructions())->toContain('analyze_seo_text');

    app()->instance(SeoBridgeContract::class, new FakeSeoBridge(available: false));
    expect((new TwillAssistant($chat))->instructions())
        ->not->toContain('analyze_seo_text')
        // The rest of the prompt is unaffected — SEO is additive.
        ->toContain('update_content');
});
