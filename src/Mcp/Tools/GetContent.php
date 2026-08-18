<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Tools\GetContent as GetContentTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetContent extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return GetContentTool::class;
    }
}
