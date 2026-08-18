<?php

namespace TwillAi;

use A17\Twill\Facades\TwillNavigation;
use A17\Twill\View\Components\Navigation\NavigationLink;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Yotech\TwillPluginSupport\TwillPluginServiceProvider;

/**
 * Drop-in service provider for the Twill AI assistant.
 *
 * Registering this provider is the only wiring a host application needs —
 * routes, navigation, views, migrations, the private upload disk, the job
 * queue and (optionally) the MCP connector's auth guard are all self-registered
 * from the host's own Twill configuration, so the feature adapts to custom
 * admin prefixes automatically.
 */
class TwillAiServiceProvider extends TwillPluginServiceProvider
{
    /**
     * The package ships no Twill capsules, so skip the capsule directory scan
     * TwillPackageServiceProvider performs by default.
     */
    protected $autoRegisterCapsules = false;

    /**
     * Config keys this provider filled in because the host had not set them.
     * Reported by `twill-ai:doctor` so the wiring is never invisible.
     *
     * @var array<int, string>
     */
    protected array $filledConfigKeys = [];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__ . '/../config/twill-ai.php', 'twill-ai');

        $this->app->singleton(Services\ModuleRegistry::class);
        $this->app->singleton(Services\BlockSchemaService::class);
        $this->app->singleton(Services\PromptComposer::class);

        $this->registerRuntimeConfigDefaults();

        if ($this->app->runningInConsole()) {
            $this->commands([
                Console\DoctorCommand::class,
                Console\InstallCommand::class,
                Console\RefreshModelsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        // Registers this package on the shared Plugins page.
        parent::boot();

        $this->registerPublishing();

        if (! config('twill-ai.enabled')) {
            return;
        }

        // Twill fills its block-directory lists in register() and CONSUMES them
        // when the block collection is first built. A long-running queue worker
        // boots providers once, so from the second agent run on the registry is
        // empty and every block resolves as "Unknown". Snapshot it now (boot,
        // still intact) so each queued run can re-seed it before working.
        $this->app->make(Services\BlockSchemaService::class)->captureRegistration();

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'twill-ai');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->registerRoutes();
        $this->registerNavigation();
        $this->registerFloatingWidget();
        $this->registerMcp();
    }

    /**
     * Describe this package for the shared Plugins page.
     */
    protected function twillPlugin(): array
    {
        return [
            'name' => config('twill-ai.ui.title', 'Twill AI'),
            'description' => 'AI content assistant for the Twill admin, with an optional MCP connector for external clients.',
            'package' => 'yotech-ai/twill-cms-ai-assistant',
            'route' => config('twill.admin_route_name_prefix', 'twill.') . 'ai.index',
        ];
    }

    /**
     * Fill the host application's framework config so a plain `composer require`
     * is enough. Only ever fills keys that are ABSENT — a host that configured
     * its own disk, queue or guard always wins.
     */
    protected function registerRuntimeConfigDefaults(): void
    {
        $config = $this->app->make('config');

        $this->fillConfig('filesystems.disks.twill-ai', [
            'driver' => 'local',
            'root' => storage_path('app/twill-ai'),
            'throw' => false,
            'report' => false,
        ]);

        // retry_after must exceed the job timeout, or the queue re-dispatches a
        // run that is still in flight and the agent works twice.
        $this->fillConfig('queue.connections.twill-ai', [
            'driver' => 'database',
            'connection' => $config->get('queue.connections.database.connection'),
            'table' => $config->get('queue.connections.database.table', 'jobs'),
            'queue' => 'twill-ai',
            'retry_after' => ((int) $config->get('twill-ai.timeout', 600)) + 60,
            'after_commit' => false,
        ]);

        if (! $this->mcpAvailable()) {
            return;
        }

        // A dedicated guard rather than claiming `api`, which in another host's
        // application is very likely Sanctum's.
        $this->fillConfig('auth.guards.twill-mcp', [
            'driver' => 'passport',
            'provider' => 'twill_users',
        ]);

        $this->fillConfig('passport.guard', 'twill_users');
    }

    protected function fillConfig(string $key, mixed $value): void
    {
        $config = $this->app->make('config');

        if ($config->has($key) && $config->get($key) !== null) {
            return;
        }

        $config->set($key, $value);
        $this->filledConfigKeys[] = $key;
    }

    /**
     * @return array<int, string>
     */
    public function filledConfigKeys(): array
    {
        return $this->filledConfigKeys;
    }

    protected function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/twill-ai.php' => config_path('twill-ai.php'),
        ], 'twill-ai-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/twill-ai'),
        ], 'twill-ai-views');

        // Optional: the assets are served from a package route by default and
        // never need publishing. Publishing them only shortens the URL.
        $this->publishes([
            __DIR__ . '/../resources/dist' => public_path('vendor/twill-ai'),
        ], 'twill-ai-assets');
    }

    protected function registerRoutes(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Route::middleware(['web', 'twill_auth:twill_users', 'impersonate', 'localization'])
            ->prefix(rtrim(ltrim(config('twill.admin_app_path', 'admin'), '/'), '/') . '/ai')
            ->name(config('twill.admin_route_name_prefix', 'twill.') . 'ai.')
            ->group(__DIR__ . '/../routes/admin.php');

        // Built JS/CSS, served with a far-future cache header and an ETag so no
        // publish step is required and the files can never go stale.
        Route::middleware(['web'])
            ->prefix(rtrim(ltrim(config('twill.admin_app_path', 'admin'), '/'), '/') . '/ai')
            ->name(config('twill.admin_route_name_prefix', 'twill.') . 'ai.')
            ->group(function (): void {
                Route::get('asset/{file}', Http\Controllers\AssetController::class)
                    ->where('file', 'twill-ai\.(iife\.js|css)')
                    ->name('asset');
            });
    }

    protected function registerNavigation(): void
    {
        TwillNavigation::addLink(
            NavigationLink::make()
                ->title(config('twill-ai.ui.title', 'Twill AI'))
                ->forRoute(config('twill.admin_route_name_prefix', 'twill.') . 'ai.index')
        );
    }

    /**
     * Push the floating chat widget onto every Twill admin page.
     *
     * Twill renders @stack('extra_js') immediately before </body> on
     * twill::layouts.main, so a view composer on that layout can push the
     * widget there. This replaces the byte-copied vendor footer override the
     * feature used to require, and with it the "re-check after every Twill
     * upgrade" note.
     */
    protected function registerFloatingWidget(): void
    {
        if (! config('twill-ai.floating_widget.enabled', true)) {
            return;
        }

        View::composer('twill::layouts.main', function () {
            if (! auth('twill_users')->check()) {
                return;
            }

            // The full-page chat already mounts the app; two instances on one
            // page would fight over the same DOM ids.
            $routeName = Route::currentRouteName() ?? '';
            $aiPrefix = config('twill.admin_route_name_prefix', 'twill.') . 'ai.';

            if (str_starts_with($routeName, $aiPrefix)) {
                return;
            }

            /** @var ViewFactory $factory */
            $factory = $this->app->make('view');
            $factory->startPush('extra_js', view('twill-ai::widget')->render());
        });
    }

    /**
     * The MCP connector is optional and gated twice: a host can turn it off,
     * and a host that never installed laravel/mcp must not see it try.
     */
    protected function registerMcp(): void
    {
        if (! $this->mcpAvailable()) {
            return;
        }

        $this->app->register(Mcp\McpServiceProvider::class);
    }

    protected function mcpAvailable(): bool
    {
        return (bool) config('twill-ai.mcp.enabled', false)
            && class_exists(\Laravel\Mcp\Server::class);
    }
}
