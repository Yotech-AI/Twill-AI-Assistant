<?php

namespace TwillAi\Tools;

use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\Concerns\HandlesToolErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListModules implements Tool
{
    use HandlesToolErrors;

    public function __construct(protected ModuleRegistry $registry) {}

    public function name(): string
    {
        return 'list_modules';
    }

    public function description(): Stringable|string
    {
        return 'List the CMS modules you can work with, the operations allowed per module (read/create/update — deleting and publishing do not exist), and the site locales. Call this first when you need an overview.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(fn () => [
            'locales' => $this->registry->locales(),
            'modules' => $this->registry->catalog(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
