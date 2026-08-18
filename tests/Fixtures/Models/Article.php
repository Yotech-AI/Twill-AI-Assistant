<?php

namespace TwillAi\Tests\Fixtures\Models;

use A17\Twill\Models\Behaviors\HasBlocks;
use A17\Twill\Models\Behaviors\HasMedias;
use A17\Twill\Models\Behaviors\HasRelated;
use A17\Twill\Models\Behaviors\HasSlug;
use A17\Twill\Models\Behaviors\HasTranslation;
use A17\Twill\Models\Model;

/**
 * The translatable, non-singleton half of the fixture CMS: blocks, slugs,
 * media roles, translations and a related browser. Mirrors the shape of a
 * typical host's "pages" or "articles" module.
 */
class Article extends Model
{
    use HasBlocks;
    use HasMedias;
    use HasRelated;
    use HasSlug;
    use HasTranslation;

    protected $table = 'articles';

    protected $fillable = [
        'published',
        'position',
    ];

    // Twill's own `active` translation column is managed by HandleTranslations
    // through the `languages` key, so it is deliberately not a content field.
    public $translatedAttributes = [
        'title',
        'description',
    ];

    public $slugAttributes = [
        'title',
    ];

    public $mediasParams = [
        'cover' => [
            'default' => [
                [
                    'name' => 'default',
                    'ratio' => 16 / 9,
                ],
            ],
        ],
    ];
}
