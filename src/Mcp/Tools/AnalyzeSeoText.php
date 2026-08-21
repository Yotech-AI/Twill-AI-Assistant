<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\AnalyzeSeoText as AnalyzeSeoTextTool;

#[IsReadOnly]
class AnalyzeSeoText extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return AnalyzeSeoTextTool::class;
    }
}
