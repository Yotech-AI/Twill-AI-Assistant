<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Tools\SearchContent as SearchContentTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class SearchContent extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return SearchContentTool::class;
    }
}
