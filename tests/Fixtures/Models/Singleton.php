<?php

namespace TwillAi\Tests\Fixtures\Models;

use A17\Twill\Models\Behaviors\HasBlocks;
use A17\Twill\Models\Behaviors\HasMedias;
use A17\Twill\Models\Model;

/**
 * The NON-translatable, singleton half of the fixture CMS, mirroring pomofit's
 * Homepage. It has no translations table, so title/description/seo live as
 * plain columns — which is the only thing that exercises the registry's
 * `extra_fields` whitelist path.
 */
class Singleton extends Model
{
    use HasBlocks;
    use HasMedias;

    protected $table = 'singletons';

    protected $fillable = [
        'published',
        'title',
        'description',
        'seo_title',
        // Deliberately fillable but NOT listed in the registry's extra_fields,
        // so tests can prove the whitelist actually strips it.
        'internal_note',
    ];

    public $mediasParams = [
        'hero' => [
            'default' => [
                [
                    'name' => 'default',
                    'ratio' => 1,
                ],
            ],
        ],
    ];
}
