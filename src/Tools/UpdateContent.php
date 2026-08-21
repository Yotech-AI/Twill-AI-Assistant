<?php

namespace TwillAi\Tools;

use A17\Twill\Repositories\BlockRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\PublishedEditPolicy;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Services\PayloadBuilder;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class UpdateContent implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected PayloadBuilder $builder,
    ) {}

    public function name(): string
    {
        return 'update_content';
    }

    public function description(): Stringable|string
    {
        return <<<'DESC'
Update an existing entry. Same payload shape as create_content, but PARTIAL: only the sections you include change.
- "fields": merged per field (send only the fields you change).
- "medias" / "browsers" / "sync": merged per key (sending a key replaces that key's list; an empty list clears it).
- "blocks": merged per EDITOR — when you include an editor, send the FULL list of blocks it should end up with (the whole tree for that editor is rebuilt). Always call get_content first and start from its blocks: keep the blocks you want, edit their content, drop the ones you want removed, and add new ones. Block "id" values are optional and ignored on update, so you don't need to track them.
You can never change the publish state. Updating entries that are already published is refused unless the site enabled it — propose changes to the user instead.
Returns the entry id and edit_url.
DESC;
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];

            $this->registry->assertAllows($module, 'update');

            $model = $this->registry->modelInstance($module);

            if ($this->registry->isSingleton($module)) {
                $entry = $model->newQuery()->firstOrFail();
            } else {
                if (! $request->offsetExists('id')) {
                    throw new TwillAiException('The "id" argument is required for this module.');
                }

                $entry = $model->newQuery()->findOrFail((int) $request['id']);
            }

            // Captured before anything is written: the agent has to be told it
            // changed something the public can already see, and that fact must
            // travel as data rather than as a sentence the model might drop.
            $wasPublished = (bool) $entry->published;

            if ($wasPublished && ! app(PublishedEditPolicy::class)->allows()) {
                throw new TwillAiException(
                    'This entry is PUBLISHED and live. You may only edit drafts. Ask the editor to make the change themselves, or to enable twill-ai.allow_updating_published.'
                );
            }

            $payload = $this->decodeJsonArgument($request->offsetExists('payload') ? $request['payload'] : null, 'payload');

            $fields = $this->builder->buildForUpdate($module, $entry, $payload);

            $repository = $this->registry->repository($module);

            // Heavy sections the agent didn't send are left untouched — the
            // repository skips them (no re-validation of existing blocks), so
            // e.g. a fields-only colour change never touches the block tree.
            $ignore = $this->builder->untouchedSections($payload);

            $sendsBlocks = array_key_exists('blocks', $payload);

            DB::transaction(function () use ($repository, $entry, $fields, $ignore, $sendsBlocks) {
                if ($ignore !== []) {
                    $repository->addIgnoreFieldsBeforeSave($ignore);
                }

                // When blocks ARE sent, replace the tree wholesale: clear the
                // existing blocks first so the repository recreates from
                // scratch (the proven create path). Twill 3.5.x mis-parents/
                // loops when asked to diff an update mixing kept and new nested
                // blocks, so we never hand it existing blocks to diff against.
                if ($sendsBlocks && method_exists($entry, 'blocks')) {
                    app(BlockRepository::class)
                        ->bulkDelete($entry->blocks()->pluck('id')->all());
                }

                $repository->update($entry->id, $fields);
            });

            return [
                'updated' => true,
                'status' => $entry->published ? 'published (content updated)' : 'draft',
                'module' => $module,
                'id' => $entry->id,
                'was_published' => $wasPublished,
                'warning' => $wasPublished
                    ? 'This entry is PUBLISHED and live. The change is visible to visitors now. Tell the editor you changed live content.'
                    : null,
                'edit_url' => $this->registry->editUrl($module, $entry->id),
            ];
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('Module key from list_modules.')->required(),
            'id' => $schema->integer()->description('Entry id (omit for singleton modules like the homepage).'),
            'payload' => $schema->string()->description('Partial content payload as a JSON object string (see tool description).')->required(),
        ];
    }
}
