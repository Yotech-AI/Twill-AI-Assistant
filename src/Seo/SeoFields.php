<?php

namespace TwillAi\Seo;

/**
 * Which SEO metadata the assistant may set, and which it may never set.
 *
 * The split is not arbitrary. The Suite stores editorial copy and indexing
 * controls in the same table, and only the copy is safe for an agent to write.
 */
final class SeoFields
{
    /**
     * Editorial copy. Wrong values here read badly; they do not remove a page
     * from search results.
     *
     * @var array<int, string>
     */
    public const WRITABLE = [
        'seo_title',
        'seo_description',
        'focus_keyphrase',
        'og_title',
        'og_description',
        'twitter_title',
        'twitter_description',
    ];

    /**
     * Refused by name, on the same reasoning that stops the agent publishing or
     * deleting: robots_noindex removes a page from search results and
     * canonical_url hands its ranking signals to a different URL. Neither looks
     * dangerous at the moment of the call, and both are reachable from a
     * plausible instruction such as "stop this page competing with the new one".
     *
     * @var array<int, string>
     */
    public const OFF_LIMITS = [
        'robots_noindex',
        'robots_nofollow',
        'canonical_url',
        'cornerstone',
        'schema_type_override',
    ];
}
