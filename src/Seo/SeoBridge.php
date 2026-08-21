<?php

namespace TwillAi\Seo;

use A17\Twill\Models\Contracts\TwillModelContract;
use Throwable;
use TwillAi\Exceptions\TwillAiException;
use TwillSeo\Analysis\AnalysisRunner;
use TwillSeo\Analysis\Paper\Paper;
use TwillSeo\Services\PaperFactory;
use TwillSeo\Services\ScoreCache;
use TwillSeo\Services\Sitemap\SitemapCache;

/**
 * The ONLY class in this package permitted to import TwillSeo\*.
 *
 * Everything else — tools, prompts, the registry — depends on
 * SeoBridgeContract, so a Suite refactor breaks this file and nothing else, and
 * the tools stay testable against a fake without booting the Suite.
 */
final class SeoBridge implements SeoBridgeContract
{
    public function __construct(
        private readonly PaperFactory $papers,
        private readonly AnalysisRunner $runner,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function describe(TwillModelContract $entry, string $locale): array
    {
        $this->assertHasSeo($entry);

        // Reuses the Suite's own model-to-Paper resolution rather than
        // reimplementing content extraction, so what the agent is told matches
        // what the SEO panel shows for the same entry.
        $build = $this->papers->fromModel($entry, $locale);

        $meta = $entry->seo($locale);

        return [
            'meta' => collect(SeoFields::WRITABLE)
                ->mapWithKeys(fn (string $field): array => [$field => $meta?->{$field}])
                ->all(),
            'content_source' => $build->contentSource,
            'report' => $this->runner->analyze($build->paper)->toArray(),
        ];
    }

    public function analyzeText(array $paper): array
    {
        return $this->runner->analyze(new Paper(
            text: (string) ($paper['text'] ?? ''),
            keyword: (string) ($paper['keyphrase'] ?? ''),
            title: (string) ($paper['title'] ?? ''),
            description: (string) ($paper['description'] ?? ''),
            slug: (string) ($paper['slug'] ?? ''),
            locale: (string) ($paper['locale'] ?? config('app.locale', 'en')),
        ))->toArray();
    }

    /**
     * Mirrors HandleSeo::afterSaveHandleSeo, the Suite's own writer: get-or-
     * create the entry, set columns through translationOrNew(), save only dirty
     * translations, then refresh the caches.
     *
     * The cache calls are not optional. Writing metadata without them leaves the
     * SEO panel and the content listing showing a stale score, and a cached
     * sitemap page that no longer matches the entry.
     *
     * Both are wrapped exactly as the Suite wraps them: an analysis or sitemap
     * failure must never take down a write that has already succeeded.
     */
    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array
    {
        $this->assertHasSeo($entry);

        $seoEntry = $entry->seoEntry()->firstOrCreate();

        foreach ($fields as $column => $value) {
            $trimmed = is_string($value) ? trim($value) : $value;

            $seoEntry->translationOrNew($locale)->{$column} = ($trimmed === '' ? null : $trimmed);
        }

        foreach ($seoEntry->translations as $translation) {
            if ($translation->isDirty()) {
                $translation->save();
            }
        }

        $this->refreshCaches($entry);

        return array_keys($fields);
    }

    private function refreshCaches(TwillModelContract $entry): void
    {
        try {
            app(ScoreCache::class)->refresh($entry);
        } catch (Throwable $e) {
            report($e);
        }

        try {
            app(SitemapCache::class)->forgetFor($entry);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function assertHasSeo(TwillModelContract $entry): void
    {
        // A module whose model does not use HasSeo has no SEO surface at all.
        // Say so, rather than failing obscurely deeper in the Suite.
        if (! method_exists($entry, 'seoEntry')) {
            throw new TwillAiException(sprintf(
                'The "%s" model has no SEO surface — it does not use the HasSeo behaviour, so it has no metadata or score to read.',
                class_basename($entry)
            ));
        }
    }
}
