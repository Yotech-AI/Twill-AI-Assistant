<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Tools\SearchMedia as SearchMediaTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchMedia extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return SearchMediaTool::class;
    }
}
