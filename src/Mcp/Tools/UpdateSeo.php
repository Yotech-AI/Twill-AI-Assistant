<?php

namespace TwillAi\Mcp\Tools;

use TwillAi\Tools\UpdateSeo as UpdateSeoTool;

class UpdateSeo extends WrappedTwillAiTool
{
    protected function delegateClass(): string
    {
        return UpdateSeoTool::class;
    }
}
