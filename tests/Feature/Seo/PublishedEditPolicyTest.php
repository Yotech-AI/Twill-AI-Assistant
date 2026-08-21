<?php

use Laravel\Ai\Tools\Request;
use TwillAi\Seo\PublishedEditPolicy;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tests\Fixtures\FakeSeoBridge;
use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tools\CreateContent;
use TwillAi\Tools\UpdateContent;

function policyArticle(bool $published = false): Article
{
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Existing copy']],
        'locales' => ['en'],
    ]);

    $article = app(ModuleRegistry::class)->repository('articles')->create($fields);

    if ($published) {
        $article->forceFill(['published' => true])->save();
    }

    return $article->fresh();
}

function withSeo(bool $available): void
{
    app()->instance(SeoBridgeContract::class, new FakeSeoBridge($available));
}

/* ---------- The four combinations ---------- */

it('refuses when the host said false, Suite or not', function (bool $available) {
    config()->set('twill-ai.allow_updating_published', false);
    withSeo($available);

    expect(app(PublishedEditPolicy::class)->allows())->toBeFalse();
})->with([true, false]);

it('permits when the host said true, Suite or not', function (bool $available) {
    config()->set('twill-ai.allow_updating_published', true);
    withSeo($available);

    expect(app(PublishedEditPolicy::class)->allows())->toBeTrue();
})->with([true, false]);

it('permits on null when the Suite is installed', function () {
    config()->set('twill-ai.allow_updating_published', null);
    withSeo(true);

    expect(app(PublishedEditPolicy::class)->allows())->toBeTrue();
});

it('refuses on null when it is not — exactly as false did', function () {
    config()->set('twill-ai.allow_updating_published', null);
    withSeo(false);

    expect(app(PublishedEditPolicy::class)->allows())->toBeFalse();
});

/* ---------- End to end through the tool ---------- */

it('lets the agent edit a published entry once the Suite is present', function () {
    config()->set('twill-ai.allow_updating_published', null);
    withSeo(true);

    $article = policyArticle(published: true);

    $result = (string) app(UpdateContent::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Improved copy']]]),
    ]));

    expect($result)->toContain('"updated":true')
        ->toContain('"was_published":true')
        ->toContain('PUBLISHED');
});

it('still refuses a published entry without the Suite', function () {
    config()->set('twill-ai.allow_updating_published', null);
    withSeo(false);

    $article = policyArticle(published: true);

    $result = (string) app(UpdateContent::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Sneaky']]]),
    ]));

    expect($result)->toContain('You may only edit drafts');
});

it('does not flag a draft edit as live', function () {
    config()->set('twill-ai.allow_updating_published', null);
    withSeo(true);

    $article = policyArticle();

    $result = (string) app(UpdateContent::class)->handle(new Request([
        'module' => 'articles',
        'id' => $article->id,
        'payload' => json_encode(['fields' => ['title' => ['en' => 'Draft edit']]]),
    ]));

    expect($result)->toContain('"was_published":false')
        ->not->toContain('PUBLISHED and live');
});

/* ---------- Creating is never affected ---------- */

it('creates drafts whatever the flag and whatever the Suite says', function (mixed $flag, bool $available) {
    config()->set('twill-ai.allow_updating_published', $flag);
    withSeo($available);

    app(CreateContent::class)->handle(new Request([
        'module' => 'articles',
        'payload' => json_encode([
            'fields' => ['title' => ['en' => 'Brand new']],
            'locales' => ['en'],
            // Even asked for explicitly, this must not survive.
            'published' => true,
        ]),
    ]));

    expect(Article::latest('id')->first()->published)->toBeFalsy();
})->with([
    'true + suite' => [true, true],
    'true, no suite' => [true, false],
    'null + suite' => [null, true],
    'false + suite' => [false, true],
]);
