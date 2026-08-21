<?php

namespace TwillAi\Tests\Fixtures;

use A17\Twill\Models\Contracts\TwillModelContract;
use TwillAi\Seo\SeoBridgeContract;

/**
 * Lets tool behaviour be tested without booting the SEO Suite, and records every
 * call so a test can assert a tool passed through what it claimed to rather than
 * only that it returned something plausible.
 */
final class FakeSeoBridge implements SeoBridgeContract
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    public function __construct(public bool $available = true) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function describe(TwillModelContract $entry, string $locale): array
    {
        $this->calls[] = ['describe', $entry->id, $locale];

        return [
            'meta' => [
                'seo_title' => 'Stored title',
                'focus_keyphrase' => 'deep work',
            ],
            'content_source' => 'blocks',
            'report' => [
                'locale' => $locale,
                'seo' => ['score' => 42, 'rating' => 'ok', 'results' => []],
            ],
        ];
    }

    public function analyzeText(array $paper): array
    {
        $this->calls[] = ['analyzeText', $paper];

        return [
            'locale' => $paper['locale'] ?? 'en',
            'seo' => ['score' => 71, 'rating' => 'good', 'results' => []],
        ];
    }

    public function updateMeta(TwillModelContract $entry, string $locale, array $fields): array
    {
        $this->calls[] = ['updateMeta', $entry->id, $locale, $fields];

        return array_keys($fields);
    }
}
