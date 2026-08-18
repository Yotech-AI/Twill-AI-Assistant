<?php

namespace TwillAi\Mcp;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
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
        $this->registerApproverLoginRedirect();

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
            require __DIR__.'/../../routes/mcp.php';
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

    /**
     * Send an unauthenticated connector-approver to the CMS login.
     *
     * The approval screen runs on the `twill_users` guard, because approving a
     * connector grants access to CMS content. Laravel's default handler sends
     * every unauthenticated visitor to the application's own `login` route —
     * the customer login in most hosts — where signing in can never satisfy
     * that guard, so the admin loops and the connector can never be approved.
     * In a host with no `login` route at all it is worse: a RouteNotFound
     * error.
     *
     * This lived in the host's bootstrap/app.php before the package existed.
     * It belongs here: the package owns the guard and the screen, so it owns
     * getting an admin to a login that can actually satisfy them.
     */
    protected function registerApproverLoginRedirect(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        if (! method_exists($handler, 'renderable')) {
            return;
        }

        $handler->renderable(function (AuthenticationException $exception, Request $request) {
            if (! in_array('twill_users', $exception->guards(), true) || $request->expectsJson()) {
                return null;
            }

            $route = config('twill.admin_route_name_prefix', 'twill.').'login.form';

            return Route::has($route)
                ? redirect()->guest(route($route))
                : null;
        });
    }
}
