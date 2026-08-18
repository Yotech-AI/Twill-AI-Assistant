<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Tools\GetModuleSchema as GetModuleSchemaTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetModuleSchema extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return GetModuleSchemaTool::class;
    }
}
