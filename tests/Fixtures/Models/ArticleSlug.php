<?php

namespace TwillAi\Tests\Fixtures\Models;

use A17\Twill\Models\Model;

class ArticleSlug extends Model
{
    protected $table = 'article_slugs';

    public $timestamps = true;

    protected $fillable = [
        'slug',
        'locale',
        'active',
        'article_id',
    ];
}
