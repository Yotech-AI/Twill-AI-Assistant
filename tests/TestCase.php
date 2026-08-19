<?php

namespace TwillAi\Tests;

use A17\Twill\Facades\TwillBlocks;
use A17\Twill\TwillServiceProvider;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\AiServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Passport\PassportServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use TwillAi\Tests\Fixtures\FixtureServiceProvider;
use TwillAi\TwillAiServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->resetTwillBlockStatics();
        $this->stubTwillAssetManifest();
    }

    /**
     * Twill's compiled front-end assets are NOT shipped through Composer — they
     * are published by `php artisan twill:update` from a dist/ directory that
     * does not exist in a vendor install. Any test that renders an admin view
     * therefore dies on "Twill assets manifest is missing".
     *
     * Twill reads that manifest through Cache::rememberForever('twill-manifest'),
     * and twillAsset() falls back to a plain public path for any key the
     * manifest lacks. Seeding the cache with an empty manifest is enough to make
     * every admin view render; the asset URLs it produces are never asserted on.
     */
    protected function stubTwillAssetManifest(): void
    {
        Cache::forever('twill-manifest', []);
    }

    /**
     * Provider order mirrors a real host: Twill first (our provider reads its
     * config), Passport before ours so `passport.guard` is already populated,
     * and ours last.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter([
            TwillServiceProvider::class,
            // Optional: laravel/mcp and laravel/passport are require-dev here and
            // merely suggested for a host, so a site can run the admin assistant
            // without the connector. Testbench REGISTERS whatever this returns,
            // which loads the class — listing them unconditionally makes the
            // whole suite unbootable when they are absent, which is precisely
            // the shape CI's no-connector job runs.
            PassportServiceProvider::class,
            AiServiceProvider::class,
            McpServiceProvider::class,
            FixtureServiceProvider::class,
            TwillAiServiceProvider::class,
        ], static fn (string $provider): bool => class_exists($provider)));
    }

    /**
     * Twill, Passport and laravel/ai ship migrations a host runs through their
     * own providers. Testbench needs them pointed at explicitly.
     */
    protected function defineDatabaseMigrations(): void
    {
        // __DIR__-relative, not base_path(): under Testbench base_path() is the
        // skeleton app, not this package.
        $vendor = __DIR__.'/../vendor';

        // Deliberately `artisan migrate` rather than loadMigrationsFrom(): the
        // latter also registers a migrate:rollback on teardown, and Twill's
        // 2023_03_24_125122_add_id_to_related::down() unconditionally drops the
        // `id` column that 2020_02_09_000010 created as the PRIMARY KEY, which
        // SQLite cannot do. The rollback is redundant here anyway — the database
        // is :memory:, so every test already starts from an empty schema.
        foreach ([
            $vendor.'/area17/twill/migrations/default',
            $vendor.'/laravel/passport/database/migrations',
            $vendor.'/laravel/ai/database/migrations',
            __DIR__.'/../database/migrations',
            __DIR__.'/Fixtures/migrations',
        ] as $path) {
            // Passport's directory is absent when the connector is not
            // installed, and migrate --path errors on a path that is not there.
            if (! is_dir($path)) {
                continue;
            }

            $this->artisan('migrate', [
                '--path' => $path,
                '--realpath' => true,
            ]);
        }
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // Name the connection "sqlite", not "testing". Several Twill migrations
        // branch on `config('database.default') !== 'sqlite'` — they compare the
        // connection NAME, not the driver — and take a MySQL-only path under any
        // other name.
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // The package is inert unless explicitly enabled; tests that need the
        // MCP connector opt in per-test via defineEnvironment overrides.
        $config->set('twill-ai.enabled', true);
        $config->set('twill-ai.mcp.enabled', false);

        $config->set('twill-ai.modules', require __DIR__.'/Fixtures/registry.php');

        // Two locales, so translated payloads are never accidentally correct
        // just because there is only one to write.
        $config->set('translatable.locales', ['en', 'nl']);
        $config->set('twill.locale', 'en');
    }

    /**
     * TwillBlocks keeps its discovered repeaters in class-level statics that
     * survive between test files in the same process, so a file that registers
     * fixture blocks leaks them into the next one. Clearing them per test keeps
     * block-dependent assertions honest.
     */
    protected function resetTwillBlockStatics(): void
    {
        if (! class_exists(TwillBlocks::class)) {
            return;
        }

        $instance = TwillBlocks::getFacadeRoot();

        if ($instance === null) {
            return;
        }

        foreach (['dynamicRepeaters', 'loadedDynamicRepeaters'] as $property) {
            $this->resetStaticProperty($instance, $property);
        }
    }

    protected function resetStaticProperty(object $instance, string $property): void
    {
        $reflection = new \ReflectionClass($instance);

        if (! $reflection->hasProperty($property)) {
            return;
        }

        $reflected = $reflection->getProperty($property);

        if (! $reflected->isStatic()) {
            return;
        }

        $current = $reflected->getValue();
        $reflected->setValue(null, is_array($current) ? [] : null);
    }
}
