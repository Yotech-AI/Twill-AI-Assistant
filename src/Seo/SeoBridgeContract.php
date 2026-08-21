<?php

namespace TwillAi\Seo;

use A17\Twill\Models\Contracts\TwillModelContract;

/**
 * Everything in this package talks to the SEO Suite through this interface.
 *
 * Only TwillAi\Seo\SeoBridge is permitted to import TwillSeo\*, so a Suite
 * refactor breaks one file rather than every tool, and the tools stay testable
 * without booting the Suite at all.
 */
interface SeoBridgeContract
{
    /**
     * Is the SEO Suite installed, enabled, and usable?
     *
     * Asked rather than re-reading config, so tool registration and tool
     * behaviour can never disagree about whether SEO exists.
     */
    public function available(): bool;

    /**
     * An entry's stored metadata plus a freshly computed analysis.
     *
     * @return array{meta: array<string, mixed>, content_source: string, report: array<string, mixed>}
     */
    public function describe(TwillModelContract $entry, string $locale): array;

    /**
     * Score arbitrary text. No entry, no persistence, no side effects.
     *
     * @param  array<string, mixed>  $paper
     * @return array<string, mixed>
     */
    public function analyzeText(array $paper): array;

    /**
     * Write whitelisted metadata and return the field names that changed.
     *
     * @param  array<string, mixed>  $fields
     * @return array<int, string>
     */
    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array;
}
