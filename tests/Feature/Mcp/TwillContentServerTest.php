<?php

use A17\Twill\TwillBlocks;
use TwillAi\Mcp\Servers\TwillContentServer;
use TwillAi\Mcp\Tools\CreateContent;
use TwillAi\Mcp\Tools\ListModules;
use TwillAi\Mcp\Tools\UpdateContent;
use TwillAi\Tests\Fixtures\Models\Article;

/**
 * `TwillBlocks::$dynamicRepeaters` / `$loadedDynamicRepeaters` are plain
 * class-level statics that Laravel's per-test container reboot never resets, so
 * repeaters registered by an earlier test file leak into this one's
 * block-editor state.
 */
beforeEach(function () {
    TwillBlocks::$dynamicRepeaters = [];
    TwillBlocks::$loadedDynamicRepeaters = [];
});

/**
 * @return array<int, class-string>
 */
function mcpRegisteredTools(): array
{
    $property = new ReflectionProperty(TwillContentServer::class, 'tools');

    return $property->getDefaultValue();
}

it('registers exactly the eight intended tools', function () {
    $names = collect(mcpRegisteredTools())->map(fn (string $tool) => app($tool)->name())->all();

    expect($names)->toEqualCanonicalizing([
        'list_modules',
        'get_module_schema',
        'list_blocks',
        'search_content',
        'get_content',
        'search_media',
        'create_content',
        'update_content',
    ]);
});

it('exposes no tool that can publish or delete', function () {
    $names = collect(mcpRegisteredTools())->map(fn (string $tool) => app($tool)->name());

    expect($names->filter(fn (string $name) => str_contains($name, 'publish') || str_contains($name, 'delete')))
        ->toBeEmpty();
});

it('marks read tools read-only and write tools not', function () {
    // Annotations come back as an array when populated, an empty object when not.
    $readOnly = fn (string $tool) => ((array) app($tool)->toArray()['annotations'])['readOnlyHint'] ?? false;

    expect($readOnly(ListModules::class))->toBeTrue()
        ->and($readOnly(CreateContent::class))->toBeFalse();
});

it('answers list_modules over MCP', function () {
    TwillContentServer::tool(ListModules::class)
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('articles');
});

it('creates content as an unpublished draft', function () {
    $payload = [
        'fields' => [
            'title' => ['en' => 'MCP Draft'],
        ],
        'locales' => ['en'],
        'blocks' => [
            'default' => [
                ['type' => 'fixture-text', 'content' => ['body' => ['en' => '<p>Body</p>']]],
            ],
        ],
    ];

    TwillContentServer::tool(CreateContent::class, [
        'module' => 'articles',
        'payload' => json_encode($payload),
        'external_ref' => 'draft-001',
    ])->assertOk()->assertHasNoErrors()->assertSee('edit_url');

    $article = Article::first();

    expect($article)->not->toBeNull()
        ->and((bool) $article->published)->toBeFalse();
});

it('updates an entry without dropping its blocks', function () {
    TwillContentServer::tool(CreateContent::class, [
        'module' => 'articles',
        'payload' => json_encode([
            'fields' => ['title' => ['en' => 'Before']],
            'locales' => ['en'],
            'blocks' => [
                'default' => [
                    ['type' => 'fixture-text', 'content' => ['body' => ['en' => '<p>Body</p>']]],
                ],
            ],
        ]),
        'external_ref' => 'update-001',
    ])->assertHasNoErrors();

    $article = Article::first();
    $blocksBefore = $article->blocks()->count();

    expect($blocksBefore)->toBeGreaterThan(0);

    TwillContentServer::tool(UpdateContent::class, [
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode([
            'fields' => ['title' => ['en' => 'After']],
            'locales' => ['en'],
        ]),
    ])->assertOk()->assertHasNoErrors();

    $article->refresh();

    expect($article->getTranslation('en')->title)->toBe('After')
        ->and($article->blocks()->count())->toBe($blocksBefore)
        ->and((bool) $article->published)->toBeFalse();
});

it('reports a failed create as an MCP error rather than a success', function () {
    TwillContentServer::tool(CreateContent::class, [
        'module' => 'no_such_module',
        'payload' => '{}',
        'external_ref' => 'bad-module',
    ])->assertHasErrors();

    expect(Article::count())->toBe(0);
});

it('rejects a payload that is not valid JSON', function () {
    TwillContentServer::tool(CreateContent::class, [
        'module' => 'articles',
        'payload' => 'not json at all',
        'external_ref' => 'bad-json',
    ])->assertHasErrors();
});
