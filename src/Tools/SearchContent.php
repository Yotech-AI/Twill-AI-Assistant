<?php

namespace TwillAi\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PromptComposer;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class SearchContent implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected PromptComposer $prompts,
    ) {}

    public function name(): string
    {
        return 'search_content';
    }

    public function description(): Stringable|string
    {
        return 'Find existing entries of a module by title (per locale titles, id, published state, edit link). '
            .$this->prompts->relationExample()
            .' Also use it to locate an entry the user wants updated.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];
            $this->registry->assertAllows($module, 'read');

            return [
                'module' => $module,
                'results' => $this->registry->searchEntries(
                    $module,
                    $request->offsetExists('query') ? (string) $request['query'] : null,
                    $request->offsetExists('published') ? (bool) $request['published'] : null,
                )->all(),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('Module key from list_modules.')->required(),
            'query' => $schema->string()->description('Optional title search (any locale).'),
            'published' => $schema->boolean()->description('Optional filter: true = published only, false = drafts only.'),
        ];
    }
}
