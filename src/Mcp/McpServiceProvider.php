<?php

namespace TwillAi\Mcp;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use TwillAi\Mcp\Http\Controllers\ConnectorAuthorizationController;
use TwillAi\Mcp\Http\Middleware\ServeConnectorDiscovery;

/**
 * Keeps the MCP integration self-contained.
 *
 * Registered by TwillAiServiceProvider only when config('twill-ai.mcp.enabled')
 * is true AND laravel/mcp is installed, so a host that never wanted the
 * connector never loads any of this.
 */
class McpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The connector's approval screen authenticates on the CMS guard, for
        // this controller only. Passport's own controller keeps passport.guard.
        $this->app->when(ConnectorAuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard('twill_users'));
    }

    public function boot(): void
    {
        $this->registerServers();
        $this->registerDiscovery();
        $this->registerLegacyAuthorizationScreen();
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
     * The connector's discovery documents, answered before routing.
     * ServeConnectorDiscovery explains why this is global middleware and not
     * a route.
     */
    protected function registerDiscovery(): void
    {
        $this->app->make(HttpKernel::class)->prependMiddleware(ServeConnectorDiscovery::class);
    }

    /**
     * Passport's global approval screen, for hosts on the old setup only.
     *
     * Before the connector had its own approval routes, a host set
     * passport.guard to twill_users and Claude approved on Passport's
     * /oauth/authorize, rendered with this package's view. New connections are
     * sent to the connector's own screen instead, so the global view is only
     * replaced where Passport's /oauth/authorize is already a CMS screen. A
     * host whose passport.guard serves its customers keeps its own view.
     */
    protected function registerLegacyAuthorizationScreen(): void
    {
        if (config('passport.guard') !== 'twill_users') {
            return;
        }

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
