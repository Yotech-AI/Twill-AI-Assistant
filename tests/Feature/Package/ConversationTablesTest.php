<?php

use Illuminate\Support\Facades\File;
use Laravel\Ai\AiServiceProvider as AiSdkServiceProvider;
use TwillAi\TwillAiServiceProvider;

/**
 * laravel/ai only PUBLISHES its conversation-store migrations — it never
 * loads them — and twill-ai:install has no publish step for them either, so
 * a host following the documented install (`twill-ai:install && php artisan
 * migrate`) had no agent_conversations table and the first chat reply died
 * inserting into it. The package now registers the SDK's migration path
 * itself. These tests pin the wiring rather than table existence: the test
 * harness migrates the SDK path explicitly, so tables would exist here even
 * without the fix.
 */
it('registers the laravel/ai migrations with the migrator', function () {
    $sdkMigrations = dirname((new ReflectionClass(AiSdkServiceProvider::class))->getFileName(), 2).'/database/migrations';

    expect(app('migrator')->paths())->toContain($sdkMigrations);
});

it('leaves the laravel/ai migrations to the host once a copy is published', function () {
    $dir = $this->app->databasePath('migrations');
    File::ensureDirectoryExists($dir);
    $published = $dir.'/2099_01_01_000000_create_agent_conversations_table.php';
    File::put($published, '<?php // stand-in for a vendor:publish copy');

    try {
        $provider = new TwillAiServiceProvider($this->app);
        $path = (new ReflectionMethod($provider, 'sdkConversationMigrationsPath'))->invoke($provider);

        expect($path)->toBeNull();
    } finally {
        File::delete($published);
    }
});

it('doctor reports the conversation tables', function () {
    $this->artisan('twill-ai:doctor')
        ->expectsOutputToContain('laravel/ai conversation tables exist');
});
