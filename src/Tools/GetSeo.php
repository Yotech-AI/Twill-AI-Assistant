<?php

namespace TwillAi\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\SeoBridgeContract;
use TwillAi\Services\ModuleRegistry;
use TwillAi\Tools\Concerns\HandlesToolErrors;

class GetSeo implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected SeoBridgeContract $seo,
    ) {}

    public function name(): string
    {
        return 'get_seo';
    }

    public function description(): Stringable|string
    {
        return 'Read an entry\'s SEO metadata and a freshly computed analysis: overall SEO and readability scores, plus each assessment with the guidance explaining why it passed or failed. Call this before rewriting existing copy — the assessment text tells you what to actually change.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $module = (string) $request['module'];
            $this->registry->assertAllows($module, 'read');

            $locale = $this->locale($request);
            $model = $this->registry->modelInstance($module);

            // Singletons have no id, exactly as get_content treats them.
            if ($this->registry->isSingleton($module)) {
                $entry = $model->newQuery()->firstOrFail();
            } else {
                if (! $request->offsetExists('id')) {
                    throw new TwillAiException('The "id" argument is required for this module.');
                }

                $entry = $model->newQuery()->findOrFail((int) $request['id']);
            }

            return $this->seo->describe($entry, $locale);
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'module' => $schema->string()->description('Module key from list_modules.')->required(),
            'id' => $schema->integer()->description('Entry id (omit for singleton modules like the homepage).'),
            'locale' => $schema->string()->description('Locale to analyse. Defaults to the site\'s first locale.'),
        ];
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
