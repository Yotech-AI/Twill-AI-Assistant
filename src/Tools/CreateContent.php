<?php

namespace TwillAi\Tools;

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Services\PromptComposer;
use TwillAi\Tools\Concerns\HandlesToolErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateContent implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected PayloadBuilder $builder,
        protected PromptComposer $prompts,
    ) {}

    public function name(): string
    {
        return 'create_content';
    }

    public function description(): Stringable|string
    {
        $payloadExample = $this->prompts->createPayloadExample();

        return <<<DESC
Create a new CMS entry as a DRAFT (publishing is impossible for you and stays human-only). Slugs are generated automatically from the title per locale.

The "payload" argument is a JSON object string with these sections (all optional except fields):
{$payloadExample}
The editor key and every block name above are examples taken from this site's registry — always confirm them with get_module_schema and list_blocks. Never guess one, and never add or strip a prefix.

A block's "children" attaches child content, keyed by name (see the block in list_blocks):
- INLINE REPEATERS (a "repeaters" key on the block, e.g. a list of questions on an FAQ block): each item is {"content": {...}} with NO "type".
- NESTED BLOCK EDITORS (a "nested_editors" key): each item is a FULL block {"type": "...", "content": {...}, "children": {...}}, where "type" must be one of that editor's allowed_blocks. These nest as deep as the blocks allow.
Putting a block in the wrong editor (top-level or nested) is rejected — a block can only go where its editor's allowed list permits.

Nested-editor shape, using the names list_blocks returns for the block you are filling:
"children": {"<nested_editor name>": [{"type": "<one of its allowed_blocks>", "content": {...}, "children": {...}}]}

A block may also carry its own "browsers" for internal links, e.g. a hero's "primary_page": [12], where the id comes from search_content.

Translated fields and translated block fields are objects keyed by locale; in a single-language project send plain values (the schema shows translatable: false). Media ids come from search_media; relation ids from search_content. Use get_module_schema and list_blocks first so every name is exact.
Returns the new entry's id and edit_url — share the edit_url with the user.
DESC;
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];

            $this->registry->assertAllows($module, 'create');

            if ($this->registry->isSingleton($module)) {
                throw new TwillAiException("\"{$module}\" is a singleton module — it can only be updated.");
            }

            $payload = $this->decodeJsonArgument($request->offsetExists('payload') ? $request['payload'] : null, 'payload');

            $fields = $this->builder->buildForCreate($module, $payload);

            $entry = $this->registry->repository($module)->create($fields);

            return [
                'created' => true,
                'status' => 'draft',
                'module' => $module,
                'id' => $entry->id,
                'edit_url' => $this->registry->editUrl($module, $entry->id),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('Module key from list_modules.')->required(),
            'payload' => $schema->string()->description('The content payload as a JSON object string (see tool description for the exact shape).')->required(),
        ];
    }
}
