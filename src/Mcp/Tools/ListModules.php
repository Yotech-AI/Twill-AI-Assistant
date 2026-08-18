<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use TwillAi\Tools\ListModules as ListModulesTool;

#[IsReadOnly]
class ListModules extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return ListModulesTool::class;
    }
}
