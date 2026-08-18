<?php

use TwillAi\Services\PromptComposer;
use TwillAi\Tests\Fixtures\Models\PlainArticle;
use TwillAi\Tests\Fixtures\Repositories\ArticleRepository;

/**
 * The prompts used to hardcode pomofit's own module and block names. Generating
 * them from the registry is what makes the package reusable, so these pin that
 * the generated text really does follow the host's CMS — and that a host can
 * still override any fragment outright.
 */
it('builds the worked example from the registry rather than a hardcoded module', function () {
    $example = app(PromptComposer::class)->createPayloadExample();

    // Blocks the fixture registry actually allows in that module's editor...
    expect($example)->toContain('fixture-text')
        ->toContain('fixture-faq')
        ->toContain('"default"')
        // ...and nothing left over from the CMS this was extracted from.
        ->not->toContain('content-hero')
        ->not->toContain('home-brandbox');
});

/**
 * Regression: the example's blocks were generated from the registry but its
 * `fields` and `medias` lines still named pomofit's columns (seo_title,
 * seo_description, seo_image). An agent that followed the example on a module
 * without those columns got "Unknown field" straight back from PayloadBuilder.
 */
it('names the example module\'s real fields and media roles, not a fixed set', function () {
    $example = app(PromptComposer::class)->createPayloadExample();

    // The fixture Article's actual translated attributes and media role.
    expect($example)->toContain('"title"')
        ->toContain('"description"')
        ->toContain('"cover"')
        ->not->toContain('seo_title')
        ->not->toContain('seo_description')
        ->not->toContain('seo_image');
});

it('omits the medias line entirely for a module with no media roles', function () {
    // Showing a role that does not exist teaches a call the builder rejects.
    config()->set('twill-ai.modules', [
        'plain' => [
            'label' => 'Plain',
            'model' => PlainArticle::class,
            'repository' => ArticleRepository::class,
            'route' => 'plain',
            'operations' => ['read', 'create', 'update'],
            'block_editors' => ['default' => ['fixture-text']],
        ],
    ]);

    expect(app(PromptComposer::class)->createPayloadExample())
        ->not->toContain('"medias"');
});

it('names the real editors the registry declares', function () {
    $editors = app(PromptComposer::class)->editorNames();

    expect($editors)->toContain('default')
        ->and($editors)->toContain('singleton_content');
});

it('reports the host locales', function () {
    $composer = app(PromptComposer::class);

    expect($composer->locales())->toBe(['en', 'nl'])
        ->and($composer->primaryLocale())->toBe('en')
        ->and($composer->isMultilingual())->toBeTrue();
});

it('describes the site as single-language when it has one locale', function () {
    config()->set('translatable.locales', ['en']);
    config()->set('twill-ai.locales', ['en']);

    $composer = app(PromptComposer::class);

    expect($composer->isMultilingual())->toBeFalse()
        ->and($composer->locales())->toBe(['en']);
});

it('picks an example module the agent may actually create in', function () {
    $example = app(PromptComposer::class)->exampleModule();

    // Never the singleton: it is read/update only, so an example built on it
    // would teach the agent a call it is not allowed to make.
    expect($example)->not->toBeNull()
        ->and($example['module'] ?? $example['key'] ?? null)->not->toBe('singleton');
});

it('lets a host override any fragment outright', function () {
    config()->set('twill-ai.prompts.editor_guidance', 'HOST OVERRIDE WINS.');

    expect(app(PromptComposer::class)->resolve('editor_guidance', 'generated text'))
        ->toBe('HOST OVERRIDE WINS.');
});

it('falls back to the generated text when no override is set', function () {
    expect(app(PromptComposer::class)->resolve('editor_guidance', 'generated text'))
        ->toBe('generated text');
});

it('generates MCP instructions describing the host CMS, not the one this came from', function () {
    $instructions = app(PromptComposer::class)->mcpInstructions();

    expect($instructions)
        // The non-translatable module is named from the registry, because the
        // agent has to know which module's fields are plain strings.
        ->toContain('singleton')
        ->toContain('multilingual')
        ->not->toContain('pomofit')
        ->not->toContain('NIE');
});
