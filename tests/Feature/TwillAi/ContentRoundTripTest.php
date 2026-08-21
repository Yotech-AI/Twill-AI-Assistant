<?php

use A17\Twill\TwillBlocks;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Services\ContentSerializer;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tools\UpdateContent;

/**
 * `TwillBlocks::$dynamicRepeaters` / `$loadedDynamicRepeaters` are plain
 * class-level statics, not container bindings, so Laravel's per-test container
 * reboot never resets them. `fixture-faq` carries an inline repeater, so without
 * this the repeaters another test file registered leak into this one's
 * block-editor state.
 */
beforeEach(function () {
    TwillBlocks::$dynamicRepeaters = [];
    TwillBlocks::$loadedDynamicRepeaters = [];
});

/**
 * The fixture "articles" module uses the unnamed `default` block editor, which
 * is the only editor `HasBlocks::renderBlocks()` reads.
 */
function twillAiCreateArticle(array $overrides = []): Article
{
    $payload = array_replace_recursive([
        'fields' => [
            'title' => ['en' => 'Round Trip'],
            'description' => ['en' => 'Description EN'],
        ],
        'locales' => ['en'],
        'blocks' => [
            'default' => [
                ['type' => 'fixture-text', 'content' => ['heading' => ['en' => 'Heading'], 'body' => ['en' => '<p>Body</p>']]],
                ['type' => 'fixture-section', 'content' => ['label' => ['en' => 'Section']]],
                ['type' => 'fixture-faq', 'content' => ['intro' => ['en' => 'FAQ']], 'children' => ['faq_items' => [
                    ['content' => ['question' => ['en' => 'Q1'], 'answer' => ['en' => '<p>A1</p>']]],
                ]]],
            ],
        ],
    ], $overrides);

    $fields = app(PayloadBuilder::class)->buildForCreate('articles', $payload);

    return app(ModuleRegistry::class)->repository('articles')->create($fields);
}

it('creates a full draft article with translations, slugs and a blocks tree', function () {
    $article = twillAiCreateArticle();

    expect((bool) $article->fresh()->published)->toBeFalse();

    expect(DB::table('article_translations')->where('article_id', $article->id)->pluck('title', 'locale')->all())
        ->toEqualCanonicalizing(['en' => 'Round Trip', 'nl' => null]);

    expect(DB::table('article_slugs')->where('article_id', $article->id)->where('locale', 'en')->value('slug'))
        ->toBe('round-trip');

    $blocks = DB::table('twill_blocks')
        ->where('blockable_type', Article::class)
        ->where('blockable_id', $article->id)
        ->get();

    expect($blocks->whereNull('parent_id')->pluck('type')->sort()->values()->all())
        ->toBe(['fixture-faq', 'fixture-section', 'fixture-text']);

    $faq = $blocks->firstWhere('type', 'fixture-faq');
    $child = $blocks->firstWhere('parent_id', $faq->id);

    expect($child)->not->toBeNull()
        ->and($child->type)->toBe('dynamic-repeater-faq_items')
        ->and($child->child_key)->toBe('faq_items');

    $textContent = json_decode($blocks->firstWhere('type', 'fixture-text')->content, true);
    expect($textContent['heading'])->toBe(['en' => 'Heading']);
});

it('updates fields only, leaving the block tree physically untouched', function () {
    $article = twillAiCreateArticle();
    $serializer = app(ContentSerializer::class);

    $beforeIds = DB::table('twill_blocks')->where('blockable_type', Article::class)
        ->where('blockable_id', $article->id)->orderBy('id')->pluck('id')->all();

    // A fields-only payload must not include a "blocks" section (blocks are not
    // rebuilt) and must not touch the publish state.
    $updateFields = app(PayloadBuilder::class)->buildForUpdate('articles', $article->fresh(), [
        'fields' => ['title' => ['en' => 'Round Trip EDITED']],
    ]);
    expect($updateFields)->not->toHaveKey('published')
        ->and($updateFields)->not->toHaveKey('blocks');

    $tool = app(UpdateContent::class);
    $result = (string) $tool->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Round Trip EDITED']]]),
    ]));
    expect($result)->toContain('"updated":true');

    $after = $serializer->toPayload('articles', $article->fresh());
    $afterIds = DB::table('twill_blocks')->where('blockable_type', Article::class)
        ->where('blockable_id', $article->id)->orderBy('id')->pluck('id')->all();

    expect($after['fields']['title']['en'])->toBe('Round Trip EDITED')
        ->and($after['fields']['description']['en'])->toBe('Description EN')
        // Blocks were left completely alone — same rows, same ids.
        ->and($afterIds)->toBe($beforeIds);
});

it('adds a new block on update without hanging or dropping existing blocks', function () {
    // Regression: Twill 3.5.x infinite-loops when an update diffs a mix of kept
    // and newly-added sibling blocks. The tool replaces the tree wholesale.
    $article = twillAiCreateArticle();
    $serializer = app(ContentSerializer::class);

    $current = $serializer->toPayload('articles', $article->fresh());
    $blocks = $current['blocks']['default'];

    // Append a brand-new block + add a repeater item to the existing FAQ block.
    $blocks[] = ['type' => 'fixture-text', 'content' => ['heading' => ['en' => 'New closing text']]];

    $faqIndex = collect($blocks)->search(fn (array $block) => $block['type'] === 'fixture-faq');
    $blocks[$faqIndex]['children']['faq_items'][] = ['content' => ['question' => ['en' => 'Q2'], 'answer' => ['en' => '<p>A2</p>']]];

    $tool = app(UpdateContent::class);
    $result = (string) $tool->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['blocks' => ['default' => $blocks]]),
    ]));

    expect($result)->toContain('"updated":true');

    $after = $serializer->toPayload('articles', $article->fresh());
    $afterFaq = collect($after['blocks']['default'])->firstWhere('type', 'fixture-faq');

    expect(count($after['blocks']['default']))->toBe(4)
        ->and(collect($after['blocks']['default'])->pluck('type')->filter(fn ($t) => $t === 'fixture-text')->count())->toBe(2)
        ->and(count($afterFaq['children']['faq_items']))->toBe(2);
});

it('ignores foreign block ids on update and never steals another entry\'s blocks', function () {
    $articleA = twillAiCreateArticle();
    $articleB = twillAiCreateArticle(['fields' => ['title' => ['en' => 'Other']]]);

    $foreignBlockId = DB::table('twill_blocks')
        ->where('blockable_type', Article::class)
        ->where('blockable_id', $articleA->id)
        ->whereNull('parent_id')
        ->value('id');

    // Block ids in update payloads are ignored (the tree is rebuilt fresh), so a
    // foreign block id can never reassign another entry's block.
    $tool = app(UpdateContent::class);
    $result = (string) $tool->handle(new Request([
        'module' => 'articles',
        'id' => $articleB->id,
        'payload' => json_encode(['blocks' => ['default' => [
            ['id' => $foreignBlockId, 'type' => 'fixture-text', 'content' => ['body' => ['en' => '<p>Hijack</p>']]],
        ]]]),
    ]));

    expect($result)->toContain('"updated":true')
        // articleA still owns its original block...
        ->and(DB::table('twill_blocks')->where('id', $foreignBlockId)->where('blockable_id', $articleA->id)->exists())->toBeTrue()
        // ...and articleB's content is its own freshly-created block.
        ->and(DB::table('twill_blocks')->where('blockable_id', $articleB->id)->where('id', $foreignBlockId)->exists())->toBeFalse();
});

/**
 * The fixture Singleton has no HasTranslation trait and no translations table,
 * so its text columns are declared as extra_fields in the registry and written
 * as plain strings. PayloadBuilder must not emit the per-locale "languages" key
 * for it — that key would reach a repository with no HandleTranslations
 * behaviour.
 */
it('writes the non-translatable singleton as plain fields with no languages key', function () {
    $singleton = app(ModuleRegistry::class)->repository('singleton')->create([
        'title' => 'Original',
        'published' => false,
    ]);

    $fields = app(PayloadBuilder::class)->buildForUpdate('singleton', $singleton->fresh(), [
        'fields' => ['title' => 'Focus hard. Move often.', 'seo_title' => 'Fixture'],
    ]);

    expect($fields)->not->toHaveKey('languages')
        ->and($fields['title'])->toBe('Focus hard. Move often.')
        ->and($fields['seo_title'])->toBe('Fixture');

    $tool = app(UpdateContent::class);
    $result = (string) $tool->handle(new Request([
        'module' => 'singleton',
        'id' => $singleton->id,
        'payload' => json_encode(['fields' => ['title' => 'Focus hard. Move often.']]),
    ]));

    expect($result)->toContain('"updated":true')
        ->and($singleton->fresh()->title)->toBe('Focus hard. Move often.');
});

/**
 * New for the package: the registry's extra_fields is a whitelist, so a column
 * the model marks fillable but the registry omits must be stripped rather than
 * written. pomofit listed every column, so this path was never exercised.
 */
it('strips non-translated fields the registry does not whitelist', function () {
    $singleton = app(ModuleRegistry::class)->repository('singleton')->create([
        'title' => 'Original',
        'published' => false,
    ]);

    app(PayloadBuilder::class)->buildForUpdate('singleton', $singleton->fresh(), [
        'fields' => ['title' => 'Fine', 'internal_note' => 'should never be written'],
    ]);
})->throws(TwillAiException::class, 'Unknown field "internal_note"');

/**
 * Regression: the registry's `browsers` entries describe their target with a
 * "module" key — that is the vocabulary ModuleRegistry::describe() reports back
 * to the agent as `related_module`. PayloadBuilder used to read a "model" key
 * only and died with `Undefined array key "model"` on any registry written that
 * way. Nothing caught it because pomofit sets `browsers => []` on every module,
 * so the path never ran.
 */
it('resolves a browser target from the module key the registry advertises', function () {
    $target = twillAiCreateArticle(['fields' => ['title' => ['en' => 'Target']]]);
    $article = twillAiCreateArticle(['fields' => ['title' => ['en' => 'Source']]]);

    $fields = app(PayloadBuilder::class)->buildForUpdate('articles', $article->fresh(), [
        'browsers' => ['related_articles' => [$target->id]],
    ]);

    expect($fields['browsers']['related_articles'])->toHaveCount(1)
        ->and($fields['browsers']['related_articles'][0]['id'])->toBe($target->id);
});

it('enforces the browser max from the registry', function () {
    $article = twillAiCreateArticle();
    $targets = collect(range(1, 4))
        ->map(fn (int $i) => twillAiCreateArticle(['fields' => ['title' => ['en' => "Target {$i}"]]])->id)
        ->all();

    app(PayloadBuilder::class)->buildForUpdate('articles', $article->fresh(), [
        'browsers' => ['related_articles' => $targets], // max is 3
    ]);
})->throws(TwillAiException::class, 'at most 3');

it('rejects browsers the registry does not list', function () {
    $article = twillAiCreateArticle();

    app(PayloadBuilder::class)->buildForUpdate('articles', $article->fresh(), [
        'browsers' => ['unrelated_things' => [1]],
    ]);
})->throws(TwillAiException::class, 'Unknown browser "unrelated_things"');

/**
 * Pins the refusal itself, with the flag set explicitly.
 *
 * It used to rely on the shipped default, which was `false`. That default is now
 * `null` — "permitted only when the SEO Suite is installed" — and this harness
 * has the Suite, so relying on the default made this test assert the opposite of
 * what it was named for. What the default resolves to in each configuration is
 * covered by PublishedEditPolicyTest; this one is about the guard working.
 */
it('refuses updating published entries when the host has said false', function () {
    config()->set('twill-ai.allow_updating_published', false);

    $article = twillAiCreateArticle();
    $article->forceFill(['published' => true])->save();

    $tool = app(UpdateContent::class);

    $result = (string) $tool->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Sneaky edit']]]),
    ]));

    expect($result)->toContain('PUBLISHED')
        ->and(DB::table('article_translations')->where('article_id', $article->id)->where('locale', 'en')->value('title'))
        ->toBe('Round Trip');
});
