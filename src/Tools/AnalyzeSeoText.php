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

class AnalyzeSeoText implements Tool
{
    use HandlesToolErrors;

    public function __construct(
        protected ModuleRegistry $registry,
        protected SeoBridgeContract $seo,
    ) {}

    public function name(): string
    {
        return 'analyze_seo_text';
    }

    public function description(): Stringable|string
    {
        return 'Score proposed copy against a focus keyphrase WITHOUT saving anything. Use this to check a rewrite before calling update_content — especially on a published page, so you iterate on the wording instead of repeatedly saving a live entry to watch its score move.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->guard(function () use ($request) {
            $text = trim((string) ($request->offsetExists('text') ? $request['text'] : ''));
            $keyphrase = trim((string) ($request->offsetExists('keyphrase') ? $request['keyphrase'] : ''));

            if ($text === '' || $keyphrase === '') {
                throw new TwillAiException('Both "text" and "keyphrase" are required.');
            }

            return $this->seo->analyzeText([
                'text' => $text,
                'keyphrase' => $keyphrase,
                'title' => (string) ($request->offsetExists('title') ? $request['title'] : ''),
                'description' => (string) ($request->offsetExists('description') ? $request['description'] : ''),
                'slug' => (string) ($request->offsetExists('slug') ? $request['slug'] : ''),
                'locale' => (string) ($request->offsetExists('locale') ? $request['locale'] : $this->registry->locales()[0]),
            ]);
        });
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->description('The proposed body copy, HTML or plain text.')->required(),
            'keyphrase' => $schema->string()->description('The focus keyphrase to score against. One phrase, not a comma-separated list.')->required(),
            'title' => $schema->string()->description('Proposed SEO title, if you are changing it.'),
            'description' => $schema->string()->description('Proposed SEO description, if you are changing it.'),
            'slug' => $schema->string()->description('Proposed slug, if you are changing it.'),
            'locale' => $schema->string()->description('Locale to analyse in. Defaults to the site\'s first locale.'),
        ];
    }
}
