<?php

use Illuminate\Database\QueryException;
use TwillAi\Mcp\Models\ContentRef;
use TwillAi\Mcp\Servers\TwillContentServer;
use TwillAi\Mcp\Tools\CreateContent;
use TwillAi\Tests\Fixtures\Models\Article;

// NOTE: pomofit's copy opened with a beforeEach stubbing `sitemap:generate`,
// because its repositories regenerate a sitemap on every save. That is host
// behaviour, not package behaviour, so it is deliberately not ported.

function mcpCreate(string $reference, string $title = 'Draft', string $module = 'articles')
{
    return TwillContentServer::tool(CreateContent::class, [
        'module' => $module,
        'payload' => json_encode([
            'fields' => ['title' => ['en' => $title]],
            'locales' => ['en'],
        ]),
        'external_ref' => $reference,
    ]);
}

it('creates one entry when the same reference is used twice', function () {
    mcpCreate('article-001')->assertHasNoErrors();
    mcpCreate('article-001', 'Different Title')->assertHasNoErrors();

    expect(Article::count())->toBe(1);
});

it('returns the original entry on a repeated reference instead of creating', function () {
    mcpCreate('article-002')->assertHasNoErrors()->assertSee('"created":true');

    $id = Article::first()->id;

    mcpCreate('article-002')
        ->assertOk()
        ->assertHasNoErrors()
        ->assertSee('"created":false')
        ->assertSee('"id":'.$id);
});

it('creates separate entries for different references', function () {
    mcpCreate('article-003')->assertHasNoErrors();
    mcpCreate('article-004')->assertHasNoErrors();

    expect(Article::count())->toBe(2)
        ->and(ContentRef::count())->toBe(2);
});

it('requires an external_ref', function () {
    TwillContentServer::tool(CreateContent::class, [
        'module' => 'articles',
        'payload' => json_encode(['fields' => ['title' => ['en' => 'No Ref']], 'locales' => ['en']]),
        'external_ref' => '   ',
    ])->assertHasErrors();

    expect(Article::count())->toBe(0);
});

it('refuses a reference already claimed by another module', function () {
    mcpCreate('shared-ref')->assertHasNoErrors();

    // The claim is looked up before the module is validated, so this exercises
    // the cross-module guard itself rather than a generic unknown-module error.
    mcpCreate('shared-ref', 'Singleton Attempt', 'singleton')->assertHasErrors();

    expect(Article::count())->toBe(1)
        // The reference stays bound to the module that claimed it first.
        ->and(ContentRef::where('external_ref', 'shared-ref')->value('module'))->toBe('articles');
});

it('releases the claim when the create fails so a corrected retry works', function () {
    TwillContentServer::tool(CreateContent::class, [
        'module' => 'articles',
        'payload' => 'not json',
        'external_ref' => 'retry-001',
    ])->assertHasErrors();

    expect(ContentRef::count())->toBe(0);

    mcpCreate('retry-001', 'Corrected')->assertHasNoErrors();

    expect(Article::count())->toBe(1);
});

it('recreates when the referenced entry was deleted by a human', function () {
    mcpCreate('article-005')->assertHasNoErrors();

    Article::first()->forceDelete();

    mcpCreate('article-005', 'Recreated')->assertHasNoErrors()->assertSee('"created":true');

    expect(Article::count())->toBe(1)
        ->and(ContentRef::count())->toBe(1);
});

it('enforces reference uniqueness at the database level', function () {
    ContentRef::create(['external_ref' => 'dup', 'module' => 'articles', 'record_id' => 1]);

    expect(fn () => ContentRef::create(['external_ref' => 'dup', 'module' => 'articles', 'record_id' => 2]))
        ->toThrow(QueryException::class);
});
