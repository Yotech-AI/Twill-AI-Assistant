<?php

namespace TwillAi\PluginPage\Http\Controllers;

use ArrayObject;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use TwillAi\PluginPage\TwillPluginServiceProvider;

class PluginsController extends Controller
{
    public function index(): View
    {
        $registry = app()->bound(TwillPluginServiceProvider::REGISTRY_BINDING)
            ? app(TwillPluginServiceProvider::REGISTRY_BINDING)
            : new ArrayObject;

        $plugins = collect($registry->getArrayCopy())
            ->sortBy(fn (array $plugin) => $plugin['name'] ?? '')
            ->values();

        return view('twill-plugins::index', [
            'plugins' => $plugins,
        ]);
    }
}
