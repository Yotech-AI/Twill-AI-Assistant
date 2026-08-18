<?php

namespace TwillAi\Http\Controllers;

use Composer\InstalledVersions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the package's built frontend assets.
 *
 * Shipping them through a route rather than a publish step means an adopter can
 * never end up running a stale copy of the JS after a package upgrade. The URL
 * carries the installed package version, so the far-future cache header is safe.
 * A host that prefers real files can still run
 * `vendor:publish --tag=twill-ai-assets`; the Blade views prefer a published
 * copy when one exists.
 */
class AssetController extends Controller
{
    private const TYPES = [
        'twill-ai.iife.js' => 'text/javascript; charset=utf-8',
        'twill-ai.css' => 'text/css; charset=utf-8',
    ];

    public function __invoke(Request $request, string $file): Response
    {
        if (! isset(self::TYPES[$file])) {
            abort(404);
        }

        $path = __DIR__ . '/../../../resources/dist/' . $file;

        if (! is_file($path)) {
            abort(404);
        }

        $response = new BinaryFileResponse($path, headers: [
            'Content-Type' => self::TYPES[$file],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);

        $response->setAutoEtag();
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Version string used to bust the cache when the package is upgraded.
     */
    public static function version(): string
    {
        if (InstalledVersions::isInstalled('yotech-ai/twill-cms-ai-assistant')) {
            return (string) InstalledVersions::getPrettyVersion('yotech-ai/twill-cms-ai-assistant');
        }

        return 'dev';
    }

    /**
     * URL for one built asset, preferring a published copy in public/ when the
     * host ran vendor:publish --tag=twill-ai-assets.
     */
    public static function url(string $file): string
    {
        $published = public_path('vendor/twill-ai/' . $file);

        if (is_file($published)) {
            return asset('vendor/twill-ai/' . $file) . '?v=' . (@filemtime($published) ?: '1');
        }

        return route(
            config('twill.admin_route_name_prefix', 'twill.') . 'ai.asset',
            ['file' => $file, 'v' => self::version()]
        );
    }
}
