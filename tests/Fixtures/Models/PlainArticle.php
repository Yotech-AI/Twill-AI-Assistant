<?php

namespace TwillAi\Tests\Fixtures\Models;

use A17\Twill\Models\Behaviors\HasBlocks;
use A17\Twill\Models\Behaviors\HasTranslation;
use A17\Twill\Models\Model;

/**
 * An article with blocks and translations but NO media roles, so the prompt
 * composer has a module whose worked example must omit the "medias" line
 * altogether. Shares the articles tables — nothing here writes through it.
 */
class PlainArticle extends Model
{
    use HasBlocks;
    use HasTranslation;

    protected $table = 'articles';

    protected $fillable = [
        'published',
        'position',
    ];

    public $translatedAttributes = [
        'title',
        'description',
    ];
}
