<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Tools\ListModules as ListModulesTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListModules extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return ListModulesTool::class;
    }
}
