<?php

namespace TwillAi\Mcp;

use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

/**
 * Keeps the MCP integration self-contained.
 *
 * Registered by TwillAiServiceProvider only when config('twill-ai.mcp.enabled')
 * is true AND laravel/mcp is installed, so a host that never wanted the
 * connector never loads any of this.
 */
class McpServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerServers();
        $this->registerAuthorizationScreen();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\CreateClientCommand::class,
                Console\DoctorCommand::class,
                Console\ListClientsCommand::class,
                Console\RevokeClientCommand::class,
            ]);
        }
    }

    /**
     * laravel/mcp auto-loads base_path('routes/ai.php') only — it does not scan
     * packages, so the servers are registered here instead. The guard mirrors
     * laravel/mcp's own: route definitions must still run for artisan commands
     * (mcp:inspector, route:list) even when the route cache is warm.
     */
    protected function registerServers(): void
    {
        if ($this->app->runningInConsole() || ! $this->app->routesAreCached()) {
            require __DIR__ . '/../../routes/mcp.php';
        }
    }

    /**
     * The screen a Twill admin sees when a connector asks for access.
     *
     * Passport renders its own generic view otherwise; this one names the
     * connector requesting approval, which matters because dynamic client
     * registration lets anyone create a client and the admin's judgement is
     * what stops an unexpected one being approved.
     */
    protected function registerAuthorizationScreen(): void
    {
        Passport::authorizationView(
            fn (array $parameters) => view('twill-ai::mcp.authorize', $parameters)
        );
    }
}
