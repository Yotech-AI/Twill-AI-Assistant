<?php

namespace TwillAi\Tests\Fixtures\Models;

use A17\Twill\Models\Model;

class SeoArticleTranslation extends Model
{
    protected $table = 'seo_article_translations';

    protected $baseModuleModel = SeoArticle::class;

    protected $fillable = [
        'active',
        'locale',
        'title',
        'description',
    ];
}
