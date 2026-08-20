<?php

namespace TwillAi\PluginPage;

use A17\Twill\TwillNavigation as BaseTwillNavigation;
use A17\Twill\View\Components\Navigation\NavigationLink;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Drop-in replacement for Twill's navigation builder that appends a
 * "Plugins" link to the right-hand navigation group, directly after the
 * Media Library link. Twill hardcodes that group in buildNavigationTree()
 * without an extension point, hence the subclass.
 */
class PluginsNavigation extends BaseTwillNavigation
{
    public function buildNavigationTree(): array
    {
        $tree = parent::buildNavigationTree();

        $link = static::pluginsLink();

        if ($link->shouldShow()) {
            $tree['right'][] = $link;
        }

        return $tree;
    }

    public static function pluginsLink(): NavigationLink
    {
        return NavigationLink::make()
            ->title(__('Addons'))
            ->forRoute(static::routeName())
            ->onlyWhen(fn () => Auth::user() !== null && Route::has(static::routeName()));
    }

    public static function routeName(): string
    {
        // Mirrors TwillRoutes::getRouteGroupOptions() so the name always
        // matches the group prefix the route was registered under.
        return config('twill.admin_route_name_prefix', 'twill.').'plugins.index';
    }
}
