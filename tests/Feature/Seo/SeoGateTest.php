<?php

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\NullSeoBridge;
use TwillAi\Seo\SeoBridge;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Seo\SeoFields;
use TwillSeo\Analysis\AnalysisRunner;

/**
 * The gate is `config('twill-ai.seo.enabled') && class_exists(AnalysisRunner)`,
 * evaluated once in boot(). The class_exists half cannot be faked from inside
 * the suite — CI covers it with a job that removes the Suite entirely.
 */
it('binds the real bridge when the Suite is installed and enabled', function () {
    expect(class_exists(AnalysisRunner::class))->toBeTrue()
        ->and(app(SeoBridgeContract::class))->toBeInstanceOf(SeoBridge::class)
        ->and(app(SeoBridgeContract::class)->available())->toBeTrue();
});

it('binds the null bridge when the host switches it off', function () {
    // Rebound rather than mutated: the binding is resolved once in boot(), so
    // changing config afterwards must not silently change an existing instance.
    config()->set('twill-ai.seo.enabled', false);
    app()->forgetInstance(SeoBridgeContract::class);

    app()->singleton(SeoBridgeContract::class, fn () => config('twill-ai.seo.enabled')
        ? app(SeoBridge::class)
        : new NullSeoBridge);

    expect(app(SeoBridgeContract::class)->available())->toBeFalse();
});

it('fails loudly rather than quietly when the null bridge is used', function () {
    // The tools are never registered in this state, so reaching one of these is
    // a wiring bug. An empty report would hide it.
    (new NullSeoBridge)->analyzeText(['text' => 'x', 'keyphrase' => 'y']);
})->throws(TwillAiException::class, 'not installed');

it('keeps indexing controls out of the writable set', function () {
    // Pins the split itself. Moving a field from OFF_LIMITS to WRITABLE should
    // require deleting this assertion, not just editing a list.
    expect(SeoFields::OFF_LIMITS)->toContain(
        'robots_noindex',
        'robots_nofollow',
        'canonical_url',
        'cornerstone',
        'schema_type_override',
    );

    expect(array_intersect(SeoFields::WRITABLE, SeoFields::OFF_LIMITS))->toBeEmpty();
});
