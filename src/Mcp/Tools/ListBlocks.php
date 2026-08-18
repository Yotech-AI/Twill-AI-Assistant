<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\ListBlocks as ListBlocksTool;

#[IsReadOnly]
class ListBlocks extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return ListBlocksTool::class;
    }
}
