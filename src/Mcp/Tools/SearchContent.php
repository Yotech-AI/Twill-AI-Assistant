<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\SearchContent as SearchContentTool;

#[IsReadOnly]
class SearchContent extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return SearchContentTool::class;
    }
}
