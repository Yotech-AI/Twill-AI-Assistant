<?php

use A17\Twill\TwillBlocks;
use Astrotomic\Translatable\Locales;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use TwillAi\Services\ContentSerializer;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tools\UpdateContent;

/*
 * update_content is partial per locale: a translated field keeps every locale
 * the payload does not send. Twill itself fills a missing locale with null, so
 * before this an English-only update wiped the Dutch and German copy.
 */

beforeEach(function () {
    // Three locales, so "the ones not sent" is more than one.
    config()->set('translatable.locales', ['en', 'nl', 'de']);
    app(Locales::class)->load();

    // See ContentRoundTripTest: these statics outlive the container.
    TwillBlocks::$dynamicRepeaters = [];
    TwillBlocks::$loadedDynamicRepeaters = [];
});

/**
 * A draft written in en, nl and de, with en and nl active and de not.
 */
function trilingualDraft(): Article
{
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => [
            'title' => ['en' => 'Title EN', 'nl' => 'Titel NL', 'de' => 'Titel DE'],
            'description' => ['en' => 'Description EN', 'nl' => 'Omschrijving NL', 'de' => 'Beschreibung DE'],
        ],
        'locales' => ['en', 'nl'],
        'blocks' => [
            'default' => [
                ['type' => 'fixture-text', 'content' => [
                    'heading' => ['en' => 'Heading EN', 'nl' => 'Kop NL', 'de' => 'Kopf DE'],
                    'body' => ['en' => '<p>Body EN</p>', 'nl' => '<p>Tekst NL</p>', 'de' => '<p>Text DE</p>'],
                ]],
                ['type' => 'fixture-faq', 'content' => ['intro' => ['en' => 'FAQ EN', 'nl' => 'FAQ NL', 'de' => 'FAQ DE']], 'children' => ['faq_items' => [
                    ['content' => [
                        'question' => ['en' => 'Q EN', 'nl' => 'V NL', 'de' => 'F DE'],
                        'answer' => ['en' => '<p>A EN</p>', 'nl' => '<p>A NL</p>', 'de' => '<p>A DE</p>'],
                    ]],
                ]]],
            ],
        ],
    ]);

    return app(ModuleRegistry::class)->repository('articles')->create($fields);
}

function updateArticle(Article $article, array $payload): string
{
    return (string) app(UpdateContent::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode($payload),
    ]));
}

/**
 * @return array<string, array<string, mixed>>
 */
function storedTranslations(Article $article): array
{
    return DB::table('article_translations')
        ->where('article_id', $article->id)
        ->get()
        ->keyBy('locale')
        ->map(fn ($row) => ['title' => $row->title, 'description' => $row->description, 'active' => (bool) $row->active])
        ->all();
}

function storedBlock(Article $article, string $type): array
{
    $content = DB::table('twill_blocks')
        ->where('blockable_type', Article::class)
        ->where('blockable_id', $article->id)
        ->where('type', $type)
        ->value('content');

    return json_decode((string) $content, true);
}

it('keeps the locales an update does not send', function () {
    $article = trilingualDraft();

    expect(updateArticle($article, ['fields' => ['title' => ['en' => 'New EN']]]))->toContain('"updated":true');

    $translations = storedTranslations($article);

    expect($translations['en']['title'])->toBe('New EN')
        ->and($translations['nl']['title'])->toBe('Titel NL')
        ->and($translations['de']['title'])->toBe('Titel DE');
});

it('clears only the locale sent as an empty string', function () {
    $article = trilingualDraft();

    updateArticle($article, ['fields' => ['title' => ['nl' => '']]]);

    $translations = storedTranslations($article);

    expect($translations['nl']['title'])->toBeEmpty()
        ->and($translations['en']['title'])->toBe('Title EN')
        ->and($translations['de']['title'])->toBe('Titel DE');
});

it('clears a locale sent as null', function () {
    $article = trilingualDraft();

    updateArticle($article, ['fields' => ['title' => ['de' => null]]]);

    $translations = storedTranslations($article);

    expect($translations['de']['title'])->toBeNull()
        ->and($translations['en']['title'])->toBe('Title EN')
        ->and($translations['nl']['title'])->toBe('Titel NL');
});

it('leaves other fields and the blocks unchanged', function () {
    $article = trilingualDraft();

    $blocksBefore = DB::table('twill_blocks')->where('blockable_id', $article->id)->orderBy('id')->get(['id', 'content'])->toArray();

    updateArticle($article, ['fields' => ['title' => ['en' => 'New EN']]]);

    $translations = storedTranslations($article);

    expect(array_map(fn (array $row) => $row['description'], $translations))
        ->toEqual(['en' => 'Description EN', 'nl' => 'Omschrijving NL', 'de' => 'Beschreibung DE'])
        ->and(DB::table('twill_blocks')->where('blockable_id', $article->id)->orderBy('id')->get(['id', 'content'])->toArray())
        ->toEqual($blocksBefore);
});

it('leaves each locale\'s active flag as it is', function () {
    $article = trilingualDraft();

    // Even an echoed "locales" from a create payload must not switch a
    // language on or off: that is publishing, and stays with the editor.
    updateArticle($article, ['fields' => ['title' => ['en' => 'New EN', 'de' => 'Neu DE']], 'locales' => ['en']]);

    expect(array_map(fn (array $row) => $row['active'], storedTranslations($article)))
        ->toEqual(['en' => true, 'nl' => true, 'de' => false]);
});

it('keeps the locales a block update does not send, for a block sent with its id', function () {
    $article = trilingualDraft();
    $blocks = app(ContentSerializer::class)->toPayload('articles', $article->fresh())['blocks']['default'];

    $text = collect($blocks)->search(fn (array $block) => $block['type'] === 'fixture-text');
    $faq = collect($blocks)->search(fn (array $block) => $block['type'] === 'fixture-faq');

    $blocks[$text]['content'] = ['heading' => ['en' => 'New heading EN'], 'body' => ['nl' => '']];
    $blocks[$faq]['children']['faq_items'][0]['content'] = ['question' => ['en' => 'New Q EN']];

    expect(updateArticle($article, ['blocks' => ['default' => $blocks]]))->toContain('"updated":true');

    $heading = storedBlock($article, 'fixture-text')['heading'];
    $body = storedBlock($article, 'fixture-text')['body'];
    $item = storedBlock($article, 'dynamic-repeater-faq_items');

    expect($heading)->toBe(['en' => 'New heading EN', 'nl' => 'Kop NL', 'de' => 'Kopf DE'])
        ->and($body['nl'])->toBeEmpty()
        ->and($body['en'])->toBe('<p>Body EN</p>')
        ->and($body['de'])->toBe('<p>Text DE</p>')
        ->and($item['question'])->toBe(['en' => 'New Q EN', 'nl' => 'V NL', 'de' => 'F DE'])
        ->and($item['answer'])->toBe(['en' => '<p>A EN</p>', 'nl' => '<p>A NL</p>', 'de' => '<p>A DE</p>']);
});

it('treats a block sent without an id as new, with only the locales it sends', function () {
    $article = trilingualDraft();

    updateArticle($article, ['blocks' => ['default' => [
        ['type' => 'fixture-text', 'content' => ['heading' => ['en' => 'Fresh EN']]],
    ]]]);

    expect(storedBlock($article, 'fixture-text')['heading'])->toBe(['en' => 'Fresh EN']);
});

it('does not merge a block id that belongs to another entry', function () {
    $article = trilingualDraft();
    $other = trilingualDraft();

    $foreignId = DB::table('twill_blocks')
        ->where('blockable_id', $other->id)
        ->where('type', 'fixture-text')
        ->value('id');

    updateArticle($article, ['blocks' => ['default' => [
        ['id' => $foreignId, 'type' => 'fixture-text', 'content' => ['heading' => ['en' => 'Mine EN']]],
    ]]]);

    expect(storedBlock($article, 'fixture-text')['heading'])->toBe(['en' => 'Mine EN']);
});
