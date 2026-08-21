<?php

namespace TwillAi\Seo;

/**
 * May the agent edit an entry a human already published?
 *
 * Three states, because two could not express "decide from context":
 *   true  — always permitted
 *   false — never permitted
 *   null  — permitted only when the SEO Suite is installed
 *
 * `null` is the shipped default. Without the Suite it behaves exactly as
 * `false` did, so no existing site changes behaviour, and a host that published
 * config/twill-ai.php has a literal `false` in their file and keeps refusing
 * until they decide otherwise.
 *
 * The reasoning for tying it to the Suite: improving existing copy is the whole
 * point of the SEO integration, and existing copy is usually published. Without
 * scoring there is no signal to improve against, so the permission would be
 * granted for no benefit.
 *
 * CREATING is unaffected and not configurable. PayloadBuilder forces
 * published = false on every create, with no config path, and that does not
 * change here.
 */
final class PublishedEditPolicy
{
    public function __construct(private readonly SeoBridgeContract $seo) {}

    public function allows(): bool
    {
        $configured = config('twill-ai.allow_updating_published');

        // An explicit boolean is the host speaking; never second-guess it.
        if (is_bool($configured)) {
            return $configured;
        }

        return $this->seo->available();
    }
}
