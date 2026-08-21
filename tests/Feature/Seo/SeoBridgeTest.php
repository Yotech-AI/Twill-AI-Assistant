<?php

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Seo\SeoFields;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tests\Fixtures\Models\SeoArticle;
use TwillAi\Tests\Fixtures\Repositories\SeoArticleRepository;

/**
 * The bridge is the ONLY file in this package that imports TwillSeo, so it is
 * the only one whose assumptions about the Suite can be wrong. Everything else
 * is tested against the fake; this runs against the real thing.
 */
/**
 * Registers the SEO fixture module for this file only. The shared registry is
 * loaded by every test, including the CI job that runs with the Suite removed,
 * and SeoArticle pulls in a TwillSeo trait at class-load time.
 */
beforeEach(function () {
    config()->set('twill-ai.modules', array_merge(config('twill-ai.modules'), [
        'seoArticles' => [
            'label' => 'SEO Articles',
            'model' => SeoArticle::class,
            'repository' => SeoArticleRepository::class,
            'route' => 'seoArticles',
            'operations' => ['read', 'create', 'update'],
            'block_editors' => [],
            'browsers' => [],
            'sync_fields' => [],
            'extra_fields' => [],
        ],
    ]));
});

function bridgeArticle(): SeoArticle
{
    $fields = app(PayloadBuilder::class)->buildForCreate('seoArticles', [
        'fields' => ['title' => ['en' => 'Deep work without interruptions']],
        'locales' => ['en'],
    ]);

    return app(ModuleRegistry::class)->repository('seoArticles')->create($fields);
}

it('scores arbitrary text without touching the database', function () {
    $before = SeoArticle::count();

    $report = app(SeoBridgeContract::class)->analyzeText([
        'text' => '<p>Deep work needs uninterrupted time. Deep work is how teams ship.</p>',
        'keyphrase' => 'deep work',
        'locale' => 'en',
    ]);

    expect($report)->toHaveKeys(['locale', 'seo', 'readability'])
        ->and($report['seo'])->toHaveKeys(['score', 'rating', 'results'])
        ->and($report['seo']['score'])->toBeInt()
        // A pure function: no entry created, nothing persisted.
        ->and(SeoArticle::count())->toBe($before);
});

it('describes a saved entry with its metadata and a fresh report', function () {
    $article = bridgeArticle();

    $described = app(SeoBridgeContract::class)->describe($article, 'en');

    expect($described)->toHaveKeys(['meta', 'content_source', 'report'])
        ->and($described['report']['seo'])->toHaveKey('score')
        // Every writable field is reported, present or not, so the agent sees
        // what it is allowed to set rather than only what is already set.
        ->and(array_keys($described['meta']))->toBe(SeoFields::WRITABLE);
});

it('writes metadata through the Suite and reads it back', function () {
    $article = bridgeArticle();
    $bridge = app(SeoBridgeContract::class);

    $changed = $bridge->updateMeta($article, 'en', [
        'seo_title' => 'Deep work, uninterrupted',
        'focus_keyphrase' => 'deep work',
    ]);

    expect($changed)->toBe(['seo_title', 'focus_keyphrase']);

    // Read back through describe() rather than the model, so this covers the
    // round trip the tools actually make.
    $meta = $bridge->describe($article->fresh(), 'en')['meta'];

    expect($meta['seo_title'])->toBe('Deep work, uninterrupted')
        ->and($meta['focus_keyphrase'])->toBe('deep work');
});

it('keeps locales apart', function () {
    $article = bridgeArticle();
    $bridge = app(SeoBridgeContract::class);

    $bridge->updateMeta($article, 'en', ['seo_title' => 'English title']);
    $bridge->updateMeta($article->fresh(), 'nl', ['seo_title' => 'Nederlandse titel']);

    expect($bridge->describe($article->fresh(), 'en')['meta']['seo_title'])->toBe('English title')
        ->and($bridge->describe($article->fresh(), 'nl')['meta']['seo_title'])->toBe('Nederlandse titel');
});

it('stores an empty value as null rather than an empty string', function () {
    $article = bridgeArticle();
    $bridge = app(SeoBridgeContract::class);

    $bridge->updateMeta($article, 'en', ['seo_title' => 'Something']);
    $bridge->updateMeta($article->fresh(), 'en', ['seo_title' => '   ']);

    // Clearing a field must leave nothing, not a blank string that renders as
    // an empty <title> and reads as "set" to every consumer downstream.
    expect($bridge->describe($article->fresh(), 'en')['meta']['seo_title'])->toBeNull();
});

it('refuses a model with no SEO surface, by name', function () {
    $singleton = app(ModuleRegistry::class)->repository('singleton')->create([
        'title' => 'Home',
        'published' => false,
    ]);

    app(SeoBridgeContract::class)->describe($singleton, 'en');
})->throws(TwillAiException::class, 'no SEO surface');
