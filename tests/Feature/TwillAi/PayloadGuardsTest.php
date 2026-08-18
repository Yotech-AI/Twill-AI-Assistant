<?php

use Laravel\Ai\Tools\Request;
use TwillAi\Agents\TwillAssistant;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Models\Chat;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tools\CreateContent;

/**
 * The non-negotiable safety guarantees of Twill AI. If one of these fails,
 * do not ship.
 */
it('forces created entries to be drafts even when the payload says published', function () {
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'published' => true,
        'fields' => ['title' => ['en' => 'Test']],
    ]);

    expect($fields['published'])->toBeFalse();
});

it('exposes no delete-capable tool on the agent', function () {
    $chat = new Chat(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6']);
    $agent = new TwillAssistant($chat);

    $toolClasses = collect($agent->tools())->map(fn ($tool) => class_basename($tool::class));

    expect($toolClasses)->not->toBeEmpty();

    foreach ($toolClasses as $toolClass) {
        expect(preg_match('/delete|destroy|remove|publish|restore/i', $toolClass))->toBe(
            0,
            "Tool {$toolClass} looks like it can delete or publish — that is forbidden."
        );
    }
});

it('rejects modules that are not in the registry', function () {
    app(ModuleRegistry::class)->get('supportTickets');
})->throws(TwillAiException::class, 'Unknown module');

/**
 * A host's non-content modules are application data, not CMS copy. They must not
 * exist from the agent's viewpoint at all — the registry is an allow-list, so
 * anything absent from it is invisible rather than merely read-only.
 */
it('registers only the modules the registry lists', function () {
    $registry = app(ModuleRegistry::class);

    expect(array_keys($registry->all()))->toEqualCanonicalizing(['articles', 'singleton']);

    foreach (['supportTickets', 'users', 'orders', 'twill_users'] as $offLimits) {
        expect($registry->has($offLimits))->toBeFalse();
    }
});

it('rejects unknown fields with a helpful message', function () {
    app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Test'], 'price' => 10],
    ]);
})->throws(TwillAiException::class, 'Unknown field "price"');

it('rejects unknown locales', function () {
    app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Test', 'fr' => 'Bonjour']],
    ]);
})->throws(TwillAiException::class, 'unknown locale "fr"');

it('rejects unknown payload sections', function () {
    app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Test']],
        'delete' => true,
    ]);
})->throws(TwillAiException::class, 'Unknown payload sections: delete');

/**
 * `fixture-banner` is registered with Twill but allowed in the singleton's
 * editor only. Offering it to an article must fail even though the block really
 * exists and the editor name is right — the per-editor allow-list is the guard,
 * not mere block resolution.
 */
it('rejects blocks that are not allowed in the editor', function () {
    app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Test']],
        'blocks' => [
            'default' => [
                ['type' => 'fixture-banner', 'content' => []],
            ],
        ],
    ]);
})->throws(TwillAiException::class, 'not allowed in the "default" editor');

it('rejects unknown block editors', function () {
    app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Test']],
        'blocks' => [
            'sidebar' => [
                ['type' => 'fixture-text', 'content' => []],
            ],
        ],
    ]);
})->throws(TwillAiException::class, 'Unknown block editor "sidebar"');

/**
 * The languages array always covers EVERY configured locale, not just the ones
 * the payload asked for — the unrequested ones are simply marked unpublished.
 * pomofit could not show this: it is single-locale, so the two cases coincided.
 */
it('builds the languages array for all configured locales', function () {
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Test']],
        'locales' => ['en'],
    ]);

    expect(collect($fields['languages'])->pluck('published', 'value')->all())
        ->toEqualCanonicalizing(['en' => true, 'nl' => false]);
});

it('marks every requested locale published', function () {
    $fields = app(PayloadBuilder::class)->buildForCreate('articles', [
        'fields' => ['title' => ['en' => 'Test', 'nl' => 'Toets']],
        'locales' => ['en', 'nl'],
    ]);

    expect(collect($fields['languages'])->pluck('published', 'value')->all())
        ->toEqualCanonicalizing(['en' => true, 'nl' => true]);
});

it('refuses creating singleton modules', function () {
    $tool = app(CreateContent::class);

    $result = $tool->handle(new Request([
        'module' => 'singleton',
        'payload' => json_encode(['fields' => ['title' => 'X']]),
    ]));

    // The registry refuses first (the singleton only allows read/update).
    expect((string) $result)->toContain('not allowed');
});

/**
 * The registry, not the tool, is what grants an operation. Asserted directly so
 * the guarantee survives a tool being rewritten: a module whose "operations"
 * omit an operation must refuse it, and there is no delete operation to grant
 * at all.
 */
it('refuses operations a module\'s registry entry does not grant', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->allows('singleton', 'create'))->toBeFalse()
        ->and($registry->allows('singleton', 'update'))->toBeTrue()
        ->and($registry->allows('articles', 'create'))->toBeTrue();

    foreach (['articles', 'singleton'] as $module) {
        expect($registry->allows($module, 'delete'))->toBeFalse();
    }

    $registry->assertAllows('singleton', 'create');
})->throws(TwillAiException::class, 'is not allowed for module "singleton"');
