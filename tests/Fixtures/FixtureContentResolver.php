<?php

namespace TwillAi\Tests\Fixtures;

use TwillSeo\Contracts\ResolvedContent;
use TwillSeo\Contracts\SeoContentResolver;

/**
 * Feeds the SEO analysis from the fixture entry's own translated fields.
 *
 * The Suite's default resolver renders Twill blocks, which resolves block views
 * through Twill capsules — and the fixture CMS deliberately registers no
 * capsules (`$autoRegisterCapsules = false`), so that path throws
 * NoCapsuleFoundException here.
 *
 * Swapping the resolver is a documented extension point: a registry entry's
 * `content` key exists precisely for a model whose real content lives somewhere
 * the default does not cover. Using it keeps the bridge under test against the
 * real Suite, rather than stubbing the Suite out to avoid the problem.
 */
final class FixtureContentResolver implements SeoContentResolver
{
    public function resolve(object $model, string $locale): ResolvedContent
    {
        $translation = method_exists($model, 'translations')
            ? $model->translations->firstWhere('locale', $locale)
            : null;

        $html = collect(['title', 'description'])
            ->map(fn (string $field) => trim((string) ($translation->{$field} ?? '')))
            ->filter()
            ->map(fn (string $value) => '<p>'.e($value).'</p>')
            ->implode("\n");

        return new ResolvedContent($html, 'fixture-fields');
    }
}
