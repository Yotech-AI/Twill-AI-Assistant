<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\GetSeo as GetSeoTool;

#[IsReadOnly]
class GetSeo extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return GetSeoTool::class;
    }
}
