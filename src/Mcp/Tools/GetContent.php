<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\GetContent as GetContentTool;

#[IsReadOnly]
class GetContent extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return GetContentTool::class;
    }
}
