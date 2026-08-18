<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Tools\ListBlocks as ListBlocksTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListBlocks extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return ListBlocksTool::class;
    }
}
