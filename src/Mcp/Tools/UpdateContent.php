<?php

namespace TwillAi\Mcp\Tools;

use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use TwillAi\Tools\UpdateContent as UpdateContentTool;

/**
 * Updates an existing entry. Idempotent: re-sending the same payload converges
 * on the same result, so a retry is safe. A retry that carries block ids the
 * first call already replaced is refused rather than applied, so the entry
 * stays as the first call left it.
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
