<?php

namespace TwillAi\Tests\Fixtures;

use A17\Twill\Facades\TwillBlocks;
use Illuminate\Support\ServiceProvider;
use TwillAi\Tests\Fixtures\Blocks\FixtureBanner;
use TwillAi\Tests\Fixtures\Blocks\FixtureFaq;
use TwillAi\Tests\Fixtures\Blocks\FixtureSection;
use TwillAi\Tests\Fixtures\Blocks\FixtureText;

/**
 * Stands up the fixture CMS: the component blocks and the view namespace their
 * render() methods point at.
 *
 * Blocks are registered as MANUAL blocks rather than through
 * twill.block_editor.directories.source.blocks, because Twill only scans those
 * directories for blade blocks — component blocks (the kind BlockSchemaService
 * reflects, via getForm()) are discovered from a namespace or registered by
 * class. Registering by class keeps the fixtures inside the test autoloader
 * instead of requiring a resource_path() shadow tree.
 */
class FixtureServiceProvider extends ServiceProvider
{
    /** @var array<int, class-string> */
    public const BLOCKS = [
        FixtureText::class,
        FixtureFaq::class,
        FixtureSection::class,
        FixtureBanner::class,
    ];

    public function register(): void
    {
        foreach (self::BLOCKS as $block) {
            TwillBlocks::registerManualBlock($block);
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'twill-ai-fixtures');
    }
}
