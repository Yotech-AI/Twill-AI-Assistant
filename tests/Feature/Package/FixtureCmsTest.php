<?php

use TwillAi\Services\BlockSchemaService;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tests\Fixtures\Models\Singleton;
use TwillAi\Tests\Fixtures\Repositories\ArticleRepository;

it('creates a translatable article with slugs through the Twill repository', function () {
    $article = app(ArticleRepository::class)->create([
        'published' => false,
        'en' => ['title' => 'Hello world', 'description' => 'English body', 'active' => true],
        'nl' => ['title' => 'Hallo wereld', 'description' => 'Nederlandse tekst', 'active' => true],
    ]);

    expect($article)->toBeInstanceOf(Article::class)
        ->and($article->translations)->toHaveCount(2);

    // Translations resolve per locale, which is what the agent writes into.
    app()->setLocale('en');
    expect($article->refresh()->title)->toBe('Hello world');

    app()->setLocale('nl');
    expect($article->refresh()->title)->toBe('Hallo wereld');
});

it('creates the non-translatable singleton with plain columns', function () {
    $singleton = Singleton::create([
        'published' => false,
        'title' => 'Home',
        'description' => 'Plain, untranslated column',
        'seo_title' => 'Home | Fixture',
        'internal_note' => 'should be strippable',
    ]);

    // No translations relation at all — this is the extra_fields path.
    expect(method_exists($singleton, 'translations'))->toBeFalse()
        ->and($singleton->title)->toBe('Home');
});

it('registers all three fixture blocks with Twill', function () {
    $blocks = app(BlockSchemaService::class);

    expect($blocks->blockExists('fixture-text'))->toBeTrue()
        ->and($blocks->blockExists('fixture-faq'))->toBeTrue()
        ->and($blocks->blockExists('fixture-section'))->toBeTrue();
});

it('reflects the plain block into translatable scalar fields', function () {
    $schema = app(BlockSchemaService::class)->describeBlock('fixture-text');

    expect($schema['block'])->toBe('fixture-text')
        ->and($schema['title'])->toBe('Fixture Text');

    $fields = collect($schema['fields'])->keyBy('name');

    expect($fields)->toHaveKeys(['heading', 'body'])
        ->and($fields['heading']['translatable'])->toBeTrue()
        ->and($fields['body']['translatable'])->toBeTrue()
        ->and($schema['repeaters'])->toBeEmpty()
        ->and($schema['nested_editors'])->toBeEmpty();
});

it('reflects the inline repeater block', function () {
    $schema = app(BlockSchemaService::class)->describeBlock('fixture-faq');

    expect($schema['repeaters'])->toHaveCount(1);

    $repeater = $schema['repeaters'][0];

    expect($repeater['key'])->toBe('faq_items')
        ->and(collect($repeater['fields'])->pluck('name')->all())
        ->toBe(['question', 'answer']);
});

it('reflects the nested editor block, its media role and its browser', function () {
    $schema = app(BlockSchemaService::class)->describeBlock('fixture-section');

    expect($schema['nested_editors'])->toHaveCount(1)
        ->and($schema['nested_editors'][0]['name'])->toBe('section_content')
        ->and($schema['nested_editors'][0]['allowed_blocks'])
        ->toBe(['fixture-text', 'fixture-faq']);

    expect(collect($schema['media_roles'])->pluck('name')->all())->toBe(['background']);
    expect(collect($schema['browsers'])->pluck('name')->all())->toBe(['related_articles']);
});

it('describes both fixture modules through the registry', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->has('articles'))->toBeTrue()
        ->and($registry->has('singleton'))->toBeTrue();

    // Translatable, non-singleton, default editor.
    $articles = $registry->describe('articles');
    expect($articles['singleton'])->toBeFalse()
        ->and($articles['translated_fields'])->toContain('title', 'description')
        ->and($articles['locales'])->toBe(['en', 'nl'])
        ->and(collect($articles['block_editors'])->pluck('editor')->all())->toBe(['default'])
        ->and(collect($articles['browsers'])->pluck('name')->all())->toBe(['related_articles']);

    // Non-translatable singleton, named editor, extra_fields whitelist.
    $singleton = $registry->describe('singleton');
    expect($singleton['singleton'])->toBeTrue()
        ->and($singleton['translated_fields'])->toBeEmpty()
        ->and(array_keys($singleton['extra_fields']))->toBe(['title', 'description', 'seo_title'])
        ->and(collect($singleton['block_editors'])->pluck('editor')->all())->toBe(['singleton_content']);
});

it('enforces the registry operation whitelist', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->allows('articles', 'create'))->toBeTrue()
        // A singleton may never be created.
        ->and($registry->allows('singleton', 'create'))->toBeFalse()
        ->and($registry->allows('singleton', 'update'))->toBeTrue()
        // There is no delete operation anywhere, by design.
        ->and($registry->allows('articles', 'delete'))->toBeFalse();
});
