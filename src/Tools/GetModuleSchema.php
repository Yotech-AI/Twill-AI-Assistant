<?php

namespace TwillAi\Tools;

use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\Concerns\HandlesToolErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetModuleSchema implements Tool
{
    use HandlesToolErrors;

    public function __construct(protected ModuleRegistry $registry) {}

    public function name(): string
    {
        return 'get_module_schema';
    }

    public function description(): Stringable|string
    {
        return 'Get the full schema of one module: translated fields, extra fields, media roles (with crops), browsers/relations, sync fields and block editors with their allowed blocks. Call this before creating or updating content in a module.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(fn () => $this->registry->describe((string) $request['module']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('Module key from list_modules, e.g. "pages".')->required(),
        ];
    }
}
