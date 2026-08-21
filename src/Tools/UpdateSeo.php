<?php

namespace TwillAi\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Seo\SeoFields;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class UpdateSeo implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected SeoBridgeContract $seo,
    ) {}

    public function name(): string
    {
        return 'update_seo';
    }

    public function description(): Stringable|string
    {
        return 'Set an entry\'s SEO metadata: seo_title, seo_description, focus_keyphrase, og_title, og_description, twitter_title, twitter_description. Body content is changed with update_content instead. Indexing controls and canonical URLs cannot be set here — those are human decisions.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];
            $this->registry->assertAllows($module, 'update');

            $fields = $this->decodeJsonArgument(
                $request->offsetExists('fields') ? $request['fields'] : null,
                'fields'
            );

            if ($fields === []) {
                throw new TwillAiException('The "fields" argument must name at least one field to set.');
            }

            $this->assertWritable($fields);

            $locale = $this->locale($request);
            $model = $this->registry->modelInstance($module);

            if ($this->registry->isSingleton($module)) {
                $entry = $model->newQuery()->firstOrFail();
            } else {
                if (! $request->offsetExists('id')) {
                    throw new TwillAiException('The "id" argument is required for this module.');
                }

                $entry = $model->newQuery()->findOrFail((int) $request['id']);
            }

            // Captured BEFORE the write: the agent must be told it changed
            // something the public can already see, and the fact has to survive
            // as data rather than as a sentence the model might omit.
            $wasPublished = (bool) $entry->published;

            $changed = $this->seo->updateMeta($entry, $locale, $fields);

            return [
                'updated' => true,
                'locale' => $locale,
                'fields' => $changed,
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
            'locale' => $schema->string()->description('Locale to write. Defaults to the site\'s first locale.'),
            'fields' => $schema->object()->description(
                'Object of field => value. Allowed: '.implode(', ', SeoFields::WRITABLE).'.'
            )->required(),
        ];
    }

    /**
     * Unknown and off-limits fields are reported BY NAME rather than dropped,
     * matching PayloadBuilder's "Unknown field" behaviour, so the agent can
     * correct itself instead of believing a write succeeded.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function assertWritable(array $fields): void
    {
        $errors = [];

        foreach (array_keys($fields) as $field) {
            if (in_array($field, SeoFields::OFF_LIMITS, true)) {
                $errors[] = sprintf(
                    'Field "%s" cannot be set by the assistant. Indexing controls, canonical URLs and schema overrides change how search engines treat the page, so they are human decisions.',
                    $field
                );

                continue;
            }

            if (! in_array($field, SeoFields::WRITABLE, true)) {
                $errors[] = sprintf(
                    'Unknown SEO field "%s". Allowed: %s.',
                    $field,
                    implode(', ', SeoFields::WRITABLE)
                );
            }
        }

        if ($errors !== []) {
            throw TwillAiException::withErrors($errors);
        }
    }

    protected function locale(Request $request): string
    {
        $locale = $request->offsetExists('locale') ? (string) $request['locale'] : '';

        if ($locale === '') {
            return $this->registry->locales()[0];
        }

        if (! in_array($locale, $this->registry->locales(), true)) {
            throw new TwillAiException(sprintf(
                'Unknown locale "%s". This site has: %s.',
                $locale,
                implode(', ', $this->registry->locales())
            ));
        }

        return $locale;
    }
}
