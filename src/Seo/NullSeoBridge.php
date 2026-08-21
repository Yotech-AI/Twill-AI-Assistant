<?php

namespace TwillAi\Seo;

use A17\Twill\Models\Contracts\TwillModelContract;
use TwillAi\Exceptions\TwillAiException;

/**
 * Bound when the SEO Suite is absent or switched off.
 *
 * Every method throws rather than returning something empty. The SEO tools are
 * not registered in that state, so reaching one of these is a wiring bug, and a
 * loud failure is more useful than a plausible-looking empty report.
 */
final class NullSeoBridge implements SeoBridgeContract
{
    public function available(): bool
    {
        return false;
    }

    public function describe(TwillModelContract $entry, string $locale): array
    {
        throw $this->unavailable();
    }

    public function analyzeText(array $paper): array
    {
        throw $this->unavailable();
    }

    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array
    {
        throw $this->unavailable();
    }

    private function unavailable(): TwillAiException
    {
        return new TwillAiException(
            'SEO features are unavailable: yotech-ai/twill-cms-seo-suite is not installed, or twill-ai.seo.enabled is false.'
        );
    }
}
