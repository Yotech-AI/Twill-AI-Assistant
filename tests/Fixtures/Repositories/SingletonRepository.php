<?php

namespace TwillAi\Tests\Fixtures\Repositories;

use A17\Twill\Repositories\Behaviors\HandleBlocks;
use A17\Twill\Repositories\Behaviors\HandleMedias;
use A17\Twill\Repositories\ModuleRepository;
use TwillAi\Tests\Fixtures\Models\Singleton;

class SingletonRepository extends ModuleRepository
{
    use HandleBlocks;
    use HandleMedias;

    public function __construct(Singleton $model)
    {
        $this->model = $model;
    }
}
