<?php

namespace TwillAi\Tests\Fixtures\Models;

use A17\Twill\Models\Behaviors\HasBlocks;
use A17\Twill\Models\Behaviors\HasMedias;
use A17\Twill\Models\Behaviors\HasTranslation;
use A17\Twill\Models\Model;
use TwillSeo\Models\Behaviors\HasSeo;

/**
 * A translatable module WITH the SEO Suite's behaviour, on its own tables.
 *
 * Standalone rather than a subclass of Article on purpose. Twill derives a
 * model's translation class, slug class and foreign key from its own class
 * name, so a subclass needs an override for each one and still trips over the
 * next convention — a fight with the framework rather than a fixture.
 *
 * And HasSeo is not on Article, because a `use` inside a class body resolves
 * the trait when the class loads: putting it there would make the whole fixture
 * CMS unloadable on a site without the Suite, which is a configuration CI runs
 * deliberately. Nothing loads this class unless a SEO test asks for it.
 */
class SeoArticle extends Model
{
    use HasBlocks;
    use HasMedias;
    use HasSeo;
    use HasTranslation;

    protected $table = 'seo_articles';

    protected $fillable = [
        'published',
    ];

    public $translatedAttributes = [
        'title',
        'description',
    ];
}
