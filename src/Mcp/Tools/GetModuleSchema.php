<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\GetModuleSchema as GetModuleSchemaTool;

#[IsReadOnly]
class GetModuleSchema extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return GetModuleSchemaTool::class;
    }
}
