<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\SearchMedia as SearchMediaTool;

#[IsReadOnly]
class SearchMedia extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return SearchMediaTool::class;
    }
}
