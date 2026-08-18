<?php

namespace TwillAi\Tools;

use TwillAi\Exceptions\TwillAiException;
use TwillAi\Services\ContentSerializer;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\Concerns\HandlesToolErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetContent implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected ContentSerializer $serializer,
    ) {}

    public function name(): string
    {
        return 'get_content';
    }

    public function description(): Stringable|string
    {
        return 'Read one entry in full: fields per locale, slugs, medias, browsers, sync relations and the complete blocks tree (with block ids). Always call this before update_content and reuse the returned structure (keep block "id" values) as the base for your changes.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];
            $this->registry->assertAllows($module, 'read');

            $model = $this->registry->modelInstance($module);

            if ($this->registry->isSingleton($module)) {
                $entry = $model->newQuery()->firstOrFail();
            } else {
                if (! $request->offsetExists('id')) {
                    throw new TwillAiException('The "id" argument is required for this module.');
                }

                $entry = $model->newQuery()->findOrFail((int) $request['id']);
            }

            return $this->serializer->toPayload($module, $entry);
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('Module key from list_modules.')->required(),
            'id' => $schema->integer()->description('Entry id (omit for singleton modules like the homepage).'),
        ];
    }
}
