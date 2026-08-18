<?php

use TwillAi\Tests\Fixtures\Models\Article;
use TwillAi\Tests\Fixtures\Models\Singleton;
use TwillAi\Tests\Fixtures\Repositories\ArticleRepository;
use TwillAi\Tests\Fixtures\Repositories\SingletonRepository;

/**
 * The fixture module registry, shaped to cover every branch the real registry
 * has: translatable vs not, singleton vs not, a named block editor vs the
 * "default" one, a browsers relation, and an extra_fields whitelist.
 */
return [
    'articles' => [
        'label' => 'Articles',
        'description' => 'Fixture articles. Translatable, block-driven, with slugs and a cover image.',
        'model' => Article::class,
        'repository' => ArticleRepository::class,
        'route' => 'articles',
        'operations' => ['read', 'create', 'update'],
        // The unnamed editor — the only one HasBlocks::renderBlocks() reads.
        'block_editors' => [
            'default' => ['fixture-text', 'fixture-faq', 'fixture-section'],
        ],
        'browsers' => [
            'related_articles' => [
                'module' => 'articles',
                'max' => 3,
            ],
        ],
        'sync_fields' => [],
        'extra_fields' => [],
    ],

    'singleton' => [
        'label' => 'Singleton',
        'description' => 'Fixture singleton. NOT translatable — title/description are plain columns.',
        'model' => Singleton::class,
        'repository' => SingletonRepository::class,
        'route' => 'singleton',
        'singleton' => true,
        // No create: a singleton can only ever be updated.
        'operations' => ['read', 'update'],
        // A NAMED editor, to prove the registry does not assume "default".
        'block_editors' => [
            'singleton_content' => ['fixture-text', 'fixture-section', 'fixture-banner'],
        ],
        'browsers' => [],
        'sync_fields' => [],
        // A MAP of column => type, per the shipped config's worked example.
        // internal_note is deliberately absent though the model marks it
        // fillable, so tests can prove the whitelist rejects it.
        'extra_fields' => [
            'title' => 'string',
            'description' => 'string',
            'seo_title' => 'string',
        ],
    ],
];
