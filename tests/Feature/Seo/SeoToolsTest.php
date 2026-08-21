<?php

use Laravel\Ai\Tools\Request;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Seo\SeoFields;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tests\Fixtures\FakeSeoBridge;
use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tools\AnalyzeSeoText;
use TwillAi\Tools\GetSeo;
use TwillAi\Tools\UpdateSeo;

/**
 * Local to this file rather than shared: helpers declared in a Pest test file
 * are global once loaded, and load order across files is not guaranteed, so
 * borrowing another suite's helper is a race waiting to happen.
 */
function seoTestArticle(): Article
{
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Focus without interruptions']],
        'locales' => ['en'],
    ]);

    return app(ModuleRegistry::class)->repository('articles')->create($fields);
}

beforeEach(function () {
    $this->bridge = new FakeSeoBridge;
    app()->instance(SeoBridgeContract::class, $this->bridge);
});

/* ---------- get_seo ---------- */

it('returns stored metadata and a fresh report for an entry', function () {
    $article = seoTestArticle();

    $result = (string) app(GetSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'locale' => 'en',
    ]));

    expect($result)->toContain('Stored title')
        ->toContain('"score":42')
        ->and($this->bridge->calls[0])->toBe(['describe', $article->id, 'en']);
});

it('falls back to the first site locale', function () {
    $article = seoTestArticle();

    app(GetSeo::class)->handle(new Request(['module' => 'articles', 'id' => $article->id]));

    expect($this->bridge->calls[0][2])->toBe('en');
});

it('refuses a locale the site does not have', function () {
    $article = seoTestArticle();

    $result = (string) app(GetSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'locale' => 'fr',
    ]));

    // Same JSON escaping as above — the locale comes back as \"fr\".
    expect($result)->toContain('Unknown locale')
        ->toContain('fr')
        // The bridge is never reached: an unknown locale is rejected before any
        // work is done, rather than analysed and thrown away.
        ->and($this->bridge->calls)->toBeEmpty();
});

it('refuses a module the registry does not list', function () {
    $result = (string) app(GetSeo::class)->handle(new Request([
        'module' => 'supportTickets',
        'id' => 1,
    ]));

    expect($result)->toContain('Unknown module');
});

/* ---------- analyze_seo_text ---------- */

it('scores proposed text and writes nothing', function () {
    $before = Article::count();

    $result = (string) app(AnalyzeSeoText::class)->handle(new Request([
        'text' => '<p>Deep work needs uninterrupted time.</p>',
        'keyphrase' => 'deep work',
    ]));

    expect($result)->toContain('"score":71')
        ->and(Article::count())->toBe($before)
        ->and($this->bridge->calls[0][0])->toBe('analyzeText');
});

it('requires both text and a keyphrase', function (array $payload) {
    $result = (string) app(AnalyzeSeoText::class)->handle(new Request($payload));

    expect($result)->toContain('required')
        ->and($this->bridge->calls)->toBeEmpty();
})->with([
    'no keyphrase' => [['text' => 'Some copy']],
    'no text' => [['keyphrase' => 'deep work']],
    'blank text' => [['text' => '   ', 'keyphrase' => 'deep work']],
]);

/* ---------- update_seo ---------- */

it('writes whitelisted metadata', function () {
    $article = seoTestArticle();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'locale' => 'en',
        'fields' => ['seo_title' => 'Better title', 'focus_keyphrase' => 'deep work'],
    ]));

    expect($result)->toContain('"updated":true')
        ->and($this->bridge->calls[0][3])
        ->toBe(['seo_title' => 'Better title', 'focus_keyphrase' => 'deep work']);
});

it('refuses every off-limits field by name and writes nothing', function (string $field) {
    $article = seoTestArticle();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'fields' => [$field => '1'],
    ]));

    // Named, not silently dropped: the agent has to be able to correct itself
    // rather than believe the write succeeded.
    expect($result)->toContain($field)
        ->toContain('human decisions')
        ->and($this->bridge->calls)->toBeEmpty();
})->with(SeoFields::OFF_LIMITS);

it('refuses a field that is neither writable nor known', function () {
    $article = seoTestArticle();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'fields' => ['not_a_field' => 'x'],
    ]));

    // Asserted without the quotes: the payload is JSON, so the field name comes
    // back escaped as \"not_a_field\".
    expect($result)->toContain('Unknown SEO field')
        ->toContain('not_a_field')
        ->and($this->bridge->calls)->toBeEmpty();
});

it('flags a metadata change to a live entry', function () {
    $article = seoTestArticle();
    $article->forceFill(['published' => true])->save();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'fields' => ['seo_title' => 'Live edit'],
    ]));

    expect($result)->toContain('"was_published":true')
        ->toContain('PUBLISHED');
});

it('does not flag a draft', function () {
    $article = seoTestArticle();

    $result = (string) app(UpdateSeo::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'fields' => ['seo_title' => 'Draft edit'],
    ]));

    expect($result)->toContain('"was_published":false')
        ->not->toContain('PUBLISHED and live');
});
