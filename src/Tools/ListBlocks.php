<?php

namespace TwillAi\Tools;

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Services\BlockSchemaService;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PromptComposer;
use TwillAi\Tools\Concerns\HandlesToolErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListBlocks implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected BlockSchemaService $blockSchema,
        protected PromptComposer $prompts,
    ) {}

    public function name(): string
    {
        return 'list_blocks';
    }

    public function description(): Stringable|string
    {
        return 'List the blocks available for a module (optionally one editor), including each block\'s fields (name, type, translatable), media roles, browsers and inline repeaters (their "key" is what you use under "children"). Use the exact block names and field names returned here.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $config = $this->registry->get((string) $request['module']);
            $editors = $config['block_editors'] ?? [];

            if ($editors === []) {
                return ['message' => 'This module has no block editors.'];
            }

            if ($editor = $request->offsetExists('editor') ? $request['editor'] : null) {
                if (! array_key_exists($editor, $editors)) {
                    throw new TwillAiException("Unknown editor \"{$editor}\". Editors: ".implode(', ', array_keys($editors)).'.');
                }

                $editors = [$editor => $editors[$editor]];
            }

            return collect($editors)->map(fn (array $blocks, string $name) => [
                'editor' => $name,
                'blocks' => $this->blockSchema->describeBlocks($blocks),
            ])->values()->all();
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('Module key from list_modules.')->required(),
            'editor' => $schema->string()->description('Optional: limit to one block editor by name. ' . $this->prompts->editorGuidance()),
        ];
    }
}
