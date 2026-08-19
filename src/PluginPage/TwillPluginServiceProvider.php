<?php

namespace TwillAi\PluginPage;

use A17\Twill\Facades\TwillNavigation;
use A17\Twill\Facades\TwillRoutes;
use A17\Twill\TwillNavigation as BaseTwillNavigation;
use A17\Twill\TwillPackageServiceProvider;
use ArrayObject;
use Composer\InstalledVersions;

/**
 * Base service provider for Yotech Twill plugins.
 *
 * Every Yotech plugin package extends this provider and declares a manifest
 * via twillPlugin(). All installed plugins share a single "Plugins" admin page
 * (next to the Media Library in the top navigation) listing every plugin with
 * a link to its own admin screen.
 *
 * The mechanism is package-independent: the shared state lives in the Laravel
 * container under well-known string keys, holding only PHP built-ins. The
 * first plugin provider to register wins ownership of the page and navigation
 * link; every later provider detects the existing binding and only adds its
 * own manifest to the registry. This means the support code can be bundled
 * (copied or required) by every plugin without conflicts or duplicate pages.
 */
abstract class TwillPluginServiceProvider extends TwillPackageServiceProvider
{
    /**
     * Container key holding an ArrayObject of plugin manifests, keyed by
     * composer package name. Only built-in types cross this boundary so
     * that differently-namespaced copies of this class interoperate.
     */
    public const REGISTRY_BINDING = 'yotech.twill-plugins.registry';

    /**
     * Container key holding the provider class-string that owns the shared
     * Plugins page (navigation link, route, controller and view).
     */
    public const PAGE_OWNER_BINDING = 'yotech.twill-plugins.page-owner';

    /**
     * Describe this plugin for the shared Plugins page.
     *
     * Supported keys:
     * - name        (required) display name
     * - description short explanation shown on the Plugins page
     * - package     composer package name, used as registry key and for
     *               version detection
     * - route       admin route name to link to (e.g. "twill.redirect")
     * - url         external/absolute URL, used when no route is given
     * - icon        short emoji/text badge
     * - version     explicit version; auto-detected from composer if omitted
     */
    abstract protected function twillPlugin(): array;

    public function register(): void
    {
        if (! $this->app->bound(self::REGISTRY_BINDING)) {
            $this->app->instance(self::REGISTRY_BINDING, new ArrayObject);
        }

        if (! $this->app->bound(self::PAGE_OWNER_BINDING)) {
            $this->app->instance(self::PAGE_OWNER_BINDING, static::class);

            // Twill does not bind its navigation builder explicitly, so the
            // first resolution goes through the container. Binding a subclass
            // here lets us append the Plugins link to the right-hand group
            // (after Media Library), which has no public extension API.
            $this->app->singleton(BaseTwillNavigation::class, PluginsNavigation::class);
        }
    }

    public function boot(): void
    {
        parent::boot();

        $this->registerPluginManifest();

        if ($this->ownsPluginsPage()) {
            $this->bootPluginsPage();
        }
    }

    protected function registerPluginManifest(): void
    {
        $manifest = $this->twillPlugin();
        $manifest['provider'] = static::class;
        $manifest['version'] ??= $this->detectVersion($manifest['package'] ?? null);

        /** @var ArrayObject $registry */
        $registry = $this->app->make(self::REGISTRY_BINDING);
        $registry[$manifest['package'] ?? static::class] = $manifest;
    }

    protected function ownsPluginsPage(): bool
    {
        return $this->app->bound(self::PAGE_OWNER_BINDING)
            && $this->app->make(self::PAGE_OWNER_BINDING) === static::class;
    }

    protected function bootPluginsPage(): void
    {
        $this->loadViewsFrom(__DIR__.'/views', 'twill-plugins');

        TwillRoutes::registerRoutes(
            $this->app->make('router'),
            TwillRoutes::getRouteGroupOptions(),
            TwillRoutes::getRouteMiddleware(),
            TwillRoutes::supportSubdomainRouting(),
            __NAMESPACE__.'\\Http\\Controllers',
            __DIR__.'/routes/plugins.php'
        );

        // If the application bound its own TwillNavigation, our subclass never
        // went live — fall back to a regular (left group) navigation link so
        // the Plugins page stays reachable.
        $this->app->booted(function (): void {
            if (! $this->app->make(BaseTwillNavigation::class) instanceof PluginsNavigation) {
                TwillNavigation::addLink(PluginsNavigation::pluginsLink());
            }
        });
    }

    protected function detectVersion(?string $package): ?string
    {
        if ($package !== null && InstalledVersions::isInstalled($package)) {
            return InstalledVersions::getPrettyVersion($package);
        }

        return null;
    }
}
