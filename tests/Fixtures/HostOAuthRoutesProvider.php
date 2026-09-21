<?php

namespace TwillAi\Tests\Fixtures;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

/**
 * A host that runs its own MCP server and registers laravel/mcp's OAuth
 * routes itself, inside a rate limit, before this package boots. yostaq does
 * exactly this in routes/ai.php.
 */
class HostOAuthRoutesProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('throttle:3,1')->group(fn () => Mcp::oauthRoutes());
    }
}
