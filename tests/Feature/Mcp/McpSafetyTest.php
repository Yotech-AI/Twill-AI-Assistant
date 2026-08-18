<?php

use A17\Twill\TwillBlocks;
use Illuminate\Support\Facades\Log;
use TwillAi\Mcp\Servers\TwillContentServer;
use TwillAi\Mcp\Tools\CreateContent;
use TwillAi\Mcp\Tools\UpdateContent;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tests\Fixtures\Models\Singleton;

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

function safetyCreate(array $payload, string $reference = 'safety-001')
{
    return TwillContentServer::tool(CreateContent::class, [
        'module' => 'articles',
        'payload' => json_encode($payload),
        'external_ref' => $reference,
    ]);
}

it('always sets published to false on the built create payload', function () {
    // Pins PayloadBuilder's explicit `$fields['published'] = false`.
    //
    // The end-to-end tests below cannot do this: `published` defaults to false
    // at the model level and `build()` strips it from payloads independently,
    // so deleting that line leaves every behavioural test green. This asserts
    // the guarantee at the layer that owns it, so removing the line fails.
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Explicit']],
        'locales' => ['en'],
    ]);

    expect($fields)->toHaveKey('published')
        ->and($fields['published'])->toBeFalse();
});

it('ignores an explicit attempt to publish on create', function () {
    safetyCreate([
        'fields' => ['title' => ['en' => 'Sneaky']],
        'locales' => ['en'],
        'published' => true,
    ])->assertHasNoErrors();

    expect((bool) Article::first()->published)->toBeFalse();
});

it('rejects an attempt to publish inside the fields section', function () {
    // Stronger than the top-level case: "published" is not a writable field, so
    // smuggling it into "fields" is refused outright rather than ignored. The
    // identical payload without it succeeds in the test above.
    safetyCreate([
        'fields' => ['title' => ['en' => 'Sneaky Fields'], 'published' => true],
        'locales' => ['en'],
    ])->assertHasErrors();

    expect(Article::where('published', true)->count())->toBe(0);
});

it('cannot publish an already-published entry through update', function () {
    safetyCreate([
        'fields' => ['title' => ['en' => 'Draft']],
        'locales' => ['en'],
    ])->assertHasNoErrors();

    $article = Article::first();

    // A human publishes it.
    $article->update(['published' => true]);

    TwillContentServer::tool(UpdateContent::class, [
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode([
            'fields' => ['title' => ['en' => 'Edited']],
            'locales' => ['en'],
            'published' => false,
        ]),
    ]);

    // The agent must not have flipped the publish state either way.
    expect((bool) $article->fresh()->published)->toBeTrue();
});

it('rejects an unknown block type', function () {
    safetyCreate([
        'fields' => ['title' => ['en' => 'Bad Block']],
        'locales' => ['en'],
        'blocks' => [
            'default' => [
                ['type' => 'fixture-does-not-exist', 'content' => []],
            ],
        ],
    ])->assertHasErrors();
});

it('rejects a block placed in an editor that does not allow it', function () {
    // fixture-banner is registered with Twill but allowed in the singleton's
    // editor only — never for an article.
    safetyCreate([
        'fields' => ['title' => ['en' => 'Wrong Editor']],
        'locales' => ['en'],
        'blocks' => [
            'default' => [
                ['type' => 'fixture-banner', 'content' => ['message' => ['en' => 'x']]],
            ],
        ],
    ])->assertHasErrors();
});

it('rejects an update to an entry that does not exist', function () {
    TwillContentServer::tool(UpdateContent::class, [
        'module' => 'articles',
        'id' => 999999,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Ghost']], 'locales' => ['en']]),
    ])->assertHasErrors();
});

it('refuses to create in a module that only allows reads and updates', function () {
    // The fixture singleton is registered read|update — there is one, and a
    // human made it. The connector may rewrite it but never create another.
    TwillContentServer::tool(CreateContent::class, [
        'module' => 'singleton',
        'payload' => json_encode(['fields' => ['title' => 'Nope']]),
        'external_ref' => 'module-guard',
    ])->assertHasErrors();

    expect(Singleton::count())->toBe(0);
});

it('refuses to write to a module that is not registered at all', function () {
    // Application data, not CMS copy. It must not exist from the connector's
    // viewpoint — the registry is an allow-list, not a deny-list.
    TwillContentServer::tool(CreateContent::class, [
        'module' => 'supportTickets',
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Nope']], 'locales' => ['en']]),
        'external_ref' => 'registry-guard',
    ])->assertHasErrors();
});

it('logs content writes so they can be traced to a client', function () {
    Log::spy();

    safetyCreate([
        'fields' => ['title' => ['en' => 'Audited']],
        'locales' => ['en'],
    ])->assertHasNoErrors();

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context) => $message === '[mcp] create_content'
            && $context['module'] === 'articles'
            && $context['outcome'] === 'ok')
        ->once();
});
