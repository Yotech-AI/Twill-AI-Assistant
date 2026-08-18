<?php

namespace TwillAi\Services;

use Throwable;

/**
 * Builds the prompt fragments that would otherwise hard-code one site's content
 * model.
 *
 * The tool descriptions and system prompts need worked examples — a JSON
 * payload naming real editors and blocks, an editor name, a relation. Shipping
 * one site's names in a reusable package teaches every other adopter the wrong
 * vocabulary on day one, so each fragment is generated from the module registry
 * instead and self-corrects as the registry changes.
 *
 * Every fragment can be replaced outright via config('twill-ai.prompts.*') for
 * a site that wants to hand-write one.
 *
 * With an empty registry (a fresh install), the fragments fall back to generic
 * placeholder names and say so, rather than inventing plausible-looking ones.
 */
class PromptComposer
{
    public function __construct(protected ModuleRegistry $registry) {}

    /**
     * Apply a config override for a prompt fragment, when one is set.
     */
    public function resolve(string $key, string $generated): string
    {
        $override = config('twill-ai.prompts.' . $key);

        return is_string($override) && trim($override) !== '' ? $override : $generated;
    }

    /**
     * @return array<int, string>
     */
    public function locales(): array
    {
        $locales = $this->registry->locales();

        return $locales !== [] ? $locales : [config('app.locale', 'en')];
    }

    public function primaryLocale(): string
    {
        return $this->locales()[0];
    }

    public function isMultilingual(): bool
    {
        return count($this->locales()) > 1;
    }

    /**
     * The module used for worked examples: the first one the agent may write
     * to and that actually has blocks, else the first registered module.
     *
     * @return array{key: string, config: array<string, mixed>}|null
     */
    public function exampleModule(): ?array
    {
        $modules = $this->registry->all();

        if ($modules === []) {
            return null;
        }

        foreach ($modules as $key => $config) {
            $writable = array_intersect(['create', 'update'], $config['operations'] ?? []) !== [];

            if ($writable && ! empty($config['block_editors'])) {
                return ['key' => $key, 'config' => $config];
            }
        }

        $key = array_key_first($modules);

        return ['key' => $key, 'config' => $modules[$key]];
    }

    /**
     * Every distinct block-editor name across the registry.
     *
     * @return array<int, string>
     */
    public function editorNames(): array
    {
        $names = [];

        foreach ($this->registry->all() as $config) {
            foreach (array_keys($config['block_editors'] ?? []) as $editor) {
                $names[(string) $editor] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * Describes the "editor" argument of list_blocks in the site's own terms.
     */
    public function editorGuidance(): string
    {
        $editors = $this->editorNames();

        $generated = match (true) {
            $editors === [] => 'The block editor name, as registered for the module in get_module_schema.',
            $editors === ['default'] => 'The block editor name. Every module here uses a single editor called "default".',
            default => 'The block editor name, e.g. ' . $this->quotedList(array_slice($editors, 0, 3)) . '. Take it from get_module_schema.',
        };

        return $this->resolve('list_blocks_editor', $generated);
    }

    /**
     * A "editor: block → block → block" ordering example for the system prompt.
     */
    public function blockOrderExample(): string
    {
        $module = $this->exampleModule();
        $editors = $module['config']['block_editors'] ?? [];

        if ($editors === []) {
            return 'default: hero → text → call-to-action';
        }

        $editor = (string) array_key_first($editors);
        $blocks = array_slice(array_values($editors[$editor]), 0, 4);

        if ($blocks === []) {
            return $editor . ': hero → text → call-to-action';
        }

        return $editor . ': ' . implode(' → ', $blocks);
    }

    /**
     * The worked JSON payload shown in create_content's description.
     */
    public function createPayloadExample(): string
    {
        $locale = $this->primaryLocale();
        $locales = json_encode($this->locales());
        $module = $this->exampleModule();

        $editor = 'default';
        $blocks = ['<block-name>', '<block-name>', '<block-name>'];

        if ($module !== null && ! empty($module['config']['block_editors'])) {
            $editors = $module['config']['block_editors'];
            $editor = (string) array_key_first($editors);
            $registered = array_values($editors[$editor]);

            if ($registered !== []) {
                // Repeat the last known block if the module registers fewer
                // than three, rather than inventing names that do not exist.
                $blocks = array_slice(array_pad($registered, 3, end($registered)), 0, 3);
            }
        }

        $generated = <<<DESC
        {
          "fields": {"title": {"{$locale}": "..."}, "seo_title": {...}, "seo_description": {...}},
          "locales": {$locales},
          "medias": {"seo_image": [123]},
          "blocks": {
            "{$editor}": [
              {"type": "{$blocks[0]}", "content": {"title": {"{$locale}": "..."}}, "medias": {"image": [55]}},
              {"type": "{$blocks[1]}", "content": {"body": {"{$locale}": "<p>...</p>"}}},
              {"type": "{$blocks[2]}", "content": {"title": {"{$locale}": "..."}}, "children": {"<repeater name>": [{"content": {"question": {"{$locale}": "..."}}}]}}
            ]
          }
        }
        DESC;

        return $this->resolve('create_content', $generated);
    }

    /**
     * A relation example for search_content, drawn from a registered browser.
     */
    public function relationExample(): string
    {
        $generated = 'Use it to find the id of an entry you need to link to from another entry.';

        foreach ($this->registry->all() as $key => $config) {
            foreach ($config['browsers'] ?? [] as $name => $browser) {
                $related = $browser['module'] ?? null;

                if ($related === null) {
                    continue;
                }

                $generated = sprintf(
                    'Use it to resolve a relation id — e.g. the "%s" browser on %s takes ids of %s entries.',
                    $name,
                    $key,
                    $related
                );

                return $this->resolve('search_content_relations', $generated);
            }
        }

        return $this->resolve('search_content_relations', $generated);
    }

    /**
     * Opening context for external MCP clients, which have none of the in-admin
     * chat's surrounding UI to tell them what site they are working on.
     */
    public function mcpInstructions(): string
    {
        $siteName = config('app.name', 'this site');
        $description = trim((string) config('twill-ai.site_description', ''));
        $site = $description !== '' ? "{$siteName}, {$description}" : $siteName;

        $localeNote = $this->isMultilingual()
            ? 'This site is multilingual. Translated fields are objects keyed by locale, e.g. ' . $this->localeExample() . '. Write idiomatic copy per locale rather than word-for-word translations.'
            : 'Translated fields are objects keyed by locale; this site has one, so they look like {"' . $this->primaryLocale() . '": "..."}.';

        $plainFieldNote = $this->plainFieldModuleNote();

        $generated = <<<MARKDOWN
        You are connected to the Twill CMS of {$site}. These tools let you read the
        CMS structure and create or update content in it.

        # Hard rules
        - Everything you create or update is a DRAFT. You cannot publish, and you must
          never tell the user something is live.
        - You cannot delete anything — no such tool exists. Deletion is human-only.
        - Never invent module, field, block or media names, or media ids. Every name and
          id must come from a tool result.

        # Workflow
        1. Call list_modules for the available modules and site locales.
        2. Call get_module_schema for the module you are writing to, and list_blocks for
           its blocks. Use the names they return EXACTLY — never add or strip a prefix.
        3. Call get_content before update_content and build your change on the structure
           it returns.
        4. Finish by giving the user the returned edit_url so they can review the draft.

        # Content shape
        - {$localeNote}{$plainFieldNote}
        - A block's child content goes under "children", keyed by name: a REPEATER key
          takes untyped items ({"content": {...}}), a NESTED EDITOR key takes full typed
          blocks ({"type": "...", "content": {...}}).
        - A block may only appear where the schema's allowed list permits it.
        - Do not place the same block type twice in a row; merge that content into one
          block and structure it with headings and paragraphs inside.
        - Set the module's plain fields too — get_module_schema lists them as extra_fields.

        # Errors
        A tool error means your payload is wrong or there is a real server fault. It is
        never a "block registration", "environment" or "prefix" problem. Read the error,
        change something, and retry at most twice. If it still fails, quote the exact
        error text to the user and stop — never claim a draft was created when it was not.
        MARKDOWN;

        return $this->resolve('mcp_instructions', $generated);
    }

    protected function localeExample(): string
    {
        $pairs = array_map(
            fn (string $locale) => '"' . $locale . '": "..."',
            array_slice($this->locales(), 0, 3)
        );

        return '{' . implode(', ', $pairs) . '}';
    }

    /**
     * Names the registered modules that store text as plain columns instead of
     * translations, which is the single most common cause of a rejected payload
     * on a mixed site.
     */
    protected function plainFieldModuleNote(): string
    {
        $plain = [];

        foreach ($this->registry->all() as $key => $config) {
            try {
                $translated = $this->registry->modelInstance($key)->translatedAttributes ?? [];
            } catch (Throwable) {
                continue;
            }

            if ($translated === [] && ! empty($config['extra_fields'])) {
                $plain[] = $key;
            }
        }

        if ($plain === []) {
            return '';
        }

        return ' The ' . $this->quotedList($plain) . ' ' . (count($plain) === 1 ? 'module is an exception — it has' : 'modules are exceptions — they have')
            . ' no translations table, so those fields are plain strings. get_module_schema tells you which is which.';
    }

    /**
     * @param  array<int, string>  $items
     */
    protected function quotedList(array $items): string
    {
        $quoted = array_map(fn (string $item) => '"' . $item . '"', $items);

        if (count($quoted) <= 1) {
            return (string) ($quoted[0] ?? '');
        }

        $last = array_pop($quoted);

        return implode(', ', $quoted) . ' or ' . $last;
    }
}
