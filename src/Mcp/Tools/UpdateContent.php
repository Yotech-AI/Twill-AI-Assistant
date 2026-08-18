<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use TwillAi\Tools\UpdateContent as UpdateContentTool;

/**
 * Updates an existing entry. Idempotent: re-sending the same payload converges
 * on the same result, so a retry is safe.
 */
#[IsIdempotent]
class UpdateContent extends WrappedTwillAiTool
{
    protected bool $auditable = true;

    protected function delegateClass(): string
    {
        return UpdateContentTool::class;
    }
}
