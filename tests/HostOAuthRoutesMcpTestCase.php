<?php

namespace TwillAi\Tests;

use TwillAi\Tests\Fixtures\HostOAuthRoutesProvider;
use TwillAi\TwillAiServiceProvider;

/**
 * The connector on a host that registered laravel/mcp's OAuth routes itself.
 */
abstract class HostOAuthRoutesMcpTestCase extends McpTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        $position = array_search(TwillAiServiceProvider::class, $providers, true);

        array_splice($providers, $position === false ? count($providers) : $position, 0, [HostOAuthRoutesProvider::class]);

        return $providers;
    }
}
