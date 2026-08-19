<?php

use A17\Twill\TwillServiceProvider;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;
use TwillAi\Services\BlockSchemaService;
use TwillAi\Services\ModuleRegistry;
use TwillAi\TwillAiServiceProvider;

it('boots Twill and the package under Testbench', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(TwillServiceProvider::class)
        ->toHaveKey(TwillAiServiceProvider::class);
});

it('resolves the package singletons', function () {
    expect(app(ModuleRegistry::class))->toBeInstanceOf(ModuleRegistry::class)
        ->and(app(BlockSchemaService::class))->toBeInstanceOf(BlockSchemaService::class);
});

it('merges the package config', function () {
    expect(config('twill-ai.enabled'))->toBeTrue();
});

it('applies Twill, Passport and package migrations on sqlite', function () {
    // Twill's own schema — proves the CMS migrations apply, not merely that its
    // provider loads. Table names come from config: Twill lets a host rename
    // them, and hardcoding would assert something the package never relies on.
    expect(Schema::hasTable(config('twill.users_table', 'twill_users')))->toBeTrue()
        ->and(Schema::hasTable(config('twill.blocks_table', 'twill_blocks')))->toBeTrue()
        ->and(Schema::hasTable(config('twill.medias_table', 'twill_medias')))->toBeTrue()
        ->and(Schema::hasTable(config('twill.related_table', 'twill_related')))->toBeTrue();

    // Passport backs the MCP connector's OAuth path, but it is an optional
    // dependency — a host can run the admin assistant without it, and CI has a
    // job that removes it entirely. Assert its schema only when it is installed.
    if (class_exists(Passport::class)) {
        expect(Schema::hasTable('oauth_clients'))->toBeTrue();
    }

    // Ours. These ship unconditionally: the mcp_* tables are created either way
    // so that enabling the connector later never needs a migration.
    expect(Schema::hasTable('twill_ai_chats'))->toBeTrue()
        ->and(Schema::hasTable('twill_ai_settings'))->toBeTrue()
        ->and(Schema::hasTable('twill_ai_chat_files'))->toBeTrue()
        ->and(Schema::hasTable('mcp_clients'))->toBeTrue()
        ->and(Schema::hasTable('mcp_content_refs'))->toBeTrue();
});
