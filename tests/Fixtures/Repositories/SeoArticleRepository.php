<?php

namespace TwillAi\Tests\Fixtures\Repositories;

use A17\Twill\Repositories\Behaviors\HandleBlocks;
use A17\Twill\Repositories\Behaviors\HandleMedias;
use A17\Twill\Repositories\Behaviors\HandleTranslations;
use A17\Twill\Repositories\ModuleRepository;
use TwillAi\Tests\Fixtures\Models\SeoArticle;

class SeoArticleRepository extends ModuleRepository
{
    use HandleBlocks;
    use HandleMedias;
    use HandleTranslations;

    public function __construct(SeoArticle $model)
    {
        $this->model = $model;
    }
}
