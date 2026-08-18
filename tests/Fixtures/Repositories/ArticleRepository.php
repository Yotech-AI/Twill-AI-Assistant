<?php

namespace TwillAi\Tests\Fixtures\Repositories;

use A17\Twill\Repositories\Behaviors\HandleBlocks;
use A17\Twill\Repositories\Behaviors\HandleMedias;
use A17\Twill\Repositories\Behaviors\HandleRelatedBrowsers;
use A17\Twill\Repositories\Behaviors\HandleSlugs;
use A17\Twill\Repositories\Behaviors\HandleTranslations;
use A17\Twill\Repositories\ModuleRepository;
use TwillAi\Tests\Fixtures\Models\Article;

class ArticleRepository extends ModuleRepository
{
    use HandleBlocks;
    use HandleMedias;
    use HandleRelatedBrowsers;
    use HandleSlugs;
    use HandleTranslations;

    public function __construct(Article $model)
    {
        $this->model = $model;
    }
}
