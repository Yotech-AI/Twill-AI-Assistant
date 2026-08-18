<?php

namespace TwillAi\Services;

use A17\Twill\Models\Contracts\TwillModelContract;
use A17\Twill\Models\Media;
use TwillAi\Exceptions\TwillAiException;
use Illuminate\Support\Arr;

/**
 * Converts an agent-facing payload into the exact $fields array Twill's
 * ModuleRepository::create()/update() expects (a CMS form submit).
 *
 * SAFETY INVARIANTS (hard-coded, not prompt-based):
 *  - created entries are ALWAYS drafts (published = false);
 *  - updates NEVER change publish state (the key is stripped);
 *  - publish dates, deletion flags and unknown keys are rejected/stripped;
 *  - only registry-whitelisted modules/fields/editors/blocks are accepted.
 */
class PayloadBuilder
{
    /** Fake frontend ids for new blocks; far above any real auto-increment. */
    protected const NEW_BLOCK_ID_BASE = 900000000;

    /** @var array<int, string> */
    protected array $errors = [];

    protected int $blockIdSequence = 0;

    protected int $blockCount = 0;

    /**
     * When true, every block is given a fresh synthetic id so the repository
     * replaces the whole block tree (delete old, create new) instead of
     * diffing. Updates use this: Twill 3.5.x infinite-loops when an update
     * payload mixes kept (real-id) and new (synthetic-id) sibling blocks, so
     * we never hand it that mix.
     */
    protected bool $useFreshBlockIds = false;

    public function __construct(
        protected ModuleRegistry $registry,
        protected BlockSchemaService $blockSchema,
        protected ContentSerializer $serializer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function buildForCreate(string $moduleKey, array $payload): array
    {
        $fields = $this->build($moduleKey, $payload, existing: null, freshBlockIds: true);

        // The one rule that can never be broken: the agent only creates drafts.
        $fields['published'] = false;

        return $fields;
    }

    /**
     * Merges the partial agent payload on top of the entry's current state so
     * Twill's full-form semantics never delete untouched content.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function buildForUpdate(string $moduleKey, TwillModelContract $entry, array $payload): array
    {
        $this->assertOnlyKnownSections($payload);

        $current = $this->serializer->toPayload($moduleKey, $entry);

        // Always re-send fields/locales plus the cheap id-array relations
        // (browsers, sync): Twill's handlers wipe these when absent, and
        // re-sending current state merged with the agent's changes keeps the
        // untouched ones intact (idempotent — no churn).
        $merged = [
            'fields' => array_merge($current['fields'], Arr::get($payload, 'fields', [])),
            'locales' => Arr::get($payload, 'locales', $current['locales']),
            'browsers' => array_merge($current['browsers'], Arr::get($payload, 'browsers', [])),
            'sync' => array_merge($current['sync'], Arr::get($payload, 'sync', [])),
        ];

        // Heavy sections (blocks, medias) are rebuilt ONLY when the agent
        // actually sends them; otherwise the repository leaves them untouched
        // (see UpdateContent's ignore list) — so a fields-only update never
        // re-validates or churns the block tree. When blocks ARE sent, send
        // the full set per editor (merged with current).
        if (array_key_exists('blocks', $payload)) {
            $merged['blocks'] = array_merge($current['blocks'], $payload['blocks']);
        }

        if (array_key_exists('medias', $payload)) {
            $merged['medias'] = array_merge($current['medias'], $payload['medias']);
        }

        $fields = $this->build($moduleKey, $merged, existing: $entry, freshBlockIds: true);

        // Updates never touch the publish state.
        unset($fields['published']);

        return $fields;
    }

    /**
     * Heavy sections (blocks, medias) the agent did NOT send on an update; the
     * repository should leave these untouched instead of wiping them.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, string>
     */
    public function untouchedSections(array $payload): array
    {
        return array_values(array_filter(
            ['blocks', 'medias'],
            fn (string $section) => ! array_key_exists($section, $payload),
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function build(string $moduleKey, array $payload, ?TwillModelContract $existing, bool $freshBlockIds = false): array
    {
        $this->errors = [];
        $this->blockIdSequence = 0;
        $this->blockCount = 0;
        $this->useFreshBlockIds = $freshBlockIds;

        $this->assertOnlyKnownSections($payload);

        $config = $this->registry->get($moduleKey);
        $model = $this->registry->modelInstance($moduleKey);
        $locales = $this->registry->locales();

        $fields = [];

        $activeLocales = $this->resolveActiveLocales($payload, $locales);

        $this->applyFields($fields, $payload, $model, $config, $locales);

        // Only translatable modules consume the per-locale "languages" key.
        // Single-language projects (no $translatedAttributes) must not receive
        // it, or it would be passed to a model that has no translations.
        if (! empty($model->translatedAttributes ?? [])) {
            $this->applyLanguages($fields, $activeLocales, $locales);
        }

        // Only emit a section when it is present in the payload, so the
        // repository (told to ignore the rest) leaves untouched sections alone
        // rather than receiving an empty array and wiping them.
        if (array_key_exists('medias', $payload)) {
            $this->applyMedias($fields, $payload, $model);
        }

        if (array_key_exists('browsers', $payload)) {
            $this->applyBrowsers($fields, $payload, $config);
        }

        if (array_key_exists('sync', $payload)) {
            $this->applySyncFields($fields, $payload, $config);
        }

        if (array_key_exists('blocks', $payload)) {
            $this->applyBlocks($fields, $payload, $config, $locales, $existing);
        }

        if ($this->errors !== []) {
            throw TwillAiException::withErrors($this->errors);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function assertOnlyKnownSections(array $payload): void
    {
        $known = ['fields', 'locales', 'medias', 'browsers', 'sync', 'blocks'];
        // Read-only keys from get_content output that may be echoed back harmlessly.
        $ignored = ['id', 'published', 'edit_url', 'slugs'];

        $unknown = array_diff(array_keys($payload), $known, $ignored);

        if ($unknown !== []) {
            throw TwillAiException::withErrors([
                'Unknown payload sections: '.implode(', ', $unknown).'. Allowed sections: '.implode(', ', $known).'.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $locales
     * @return array<int, string>
     */
    protected function resolveActiveLocales(array $payload, array $locales): array
    {
        $requested = $payload['locales'] ?? null;

        if (is_array($requested) && $requested !== []) {
            foreach ($requested as $locale) {
                if (! in_array($locale, $locales, true)) {
                    $this->errors[] = "Locale \"{$locale}\" is not enabled. Available locales: ".implode(', ', $locales).'.';
                }
            }

            return array_values(array_intersect($locales, $requested));
        }

        // Fall back to the locales used in translated field values.
        $used = [];

        foreach ($payload['fields'] ?? [] as $value) {
            if (is_array($value)) {
                $used = array_merge($used, array_keys($value));
            }
        }

        $used = array_values(array_intersect($locales, array_unique($used)));

        return $used !== [] ? $used : $locales;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $locales
     */
    protected function applyFields(array &$fields, array $payload, TwillModelContract $model, array $config, array $locales): void
    {
        $translated = $model->translatedAttributes ?? [];
        $extras = $config['extra_fields'] ?? [];

        foreach ($payload['fields'] ?? [] as $name => $value) {
            if (in_array($name, $translated, true)) {
                if (! is_array($value)) {
                    $this->errors[] = "Field \"{$name}\" is translated and must be an object keyed by locale, e.g. {\"en\": \"...\"}.";

                    continue;
                }

                foreach (array_keys($value) as $locale) {
                    if (! in_array($locale, $locales, true)) {
                        $this->errors[] = "Field \"{$name}\" uses unknown locale \"{$locale}\".";
                    }
                }

                $fields[$name] = $value;

                continue;
            }

            if (array_key_exists($name, $extras)) {
                $fields[$name] = $this->castExtraField($value, $extras[$name]);

                continue;
            }

            $this->errors[] = "Unknown field \"{$name}\" for this module. Allowed: "
                .implode(', ', array_merge($translated, array_keys($extras))).'.';
        }
    }

    protected function castExtraField(mixed $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'number' => (float) $value,
            'array' => is_array($value) ? $value : [$value],
            default => is_scalar($value) ? (string) $value : $value,
        };
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<int, string>  $activeLocales
     * @param  array<int, string>  $locales
     */
    protected function applyLanguages(array &$fields, array $activeLocales, array $locales): void
    {
        $fields['languages'] = collect($locales)->map(fn (string $locale) => [
            'value' => $locale,
            'published' => in_array($locale, $activeLocales, true),
        ])->values()->all();
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     */
    protected function applyMedias(array &$fields, array $payload, TwillModelContract $model): void
    {
        $medias = $payload['medias'] ?? [];

        if (! is_array($medias)) {
            $this->errors[] = 'The "medias" section must be an object of role => [media ids].';

            return;
        }

        $allowedRoles = array_keys($model->mediasParams ?? []);
        $fields['medias'] = [];

        foreach ($medias as $role => $items) {
            if (! in_array($role, $allowedRoles, true)) {
                $this->errors[] = "Unknown media role \"{$role}\". Allowed roles: ".(implode(', ', $allowedRoles) ?: '(none)').'.';

                continue;
            }

            $fields['medias'][$role] = $this->normalizeMediaItems($items, "medias.{$role}");
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeMediaItems(mixed $items, string $path): array
    {
        if (! is_array($items)) {
            $this->errors[] = "\"{$path}\" must be an array of media ids.";

            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $normalized[] = ['id' => (int) $item];
            } elseif (is_array($item) && isset($item['id'])) {
                $entry = ['id' => (int) $item['id']];

                if (! empty($item['crops']) && is_array($item['crops'])) {
                    $entry['crops'] = $item['crops'];
                }

                $normalized[] = $entry;
            } else {
                $this->errors[] = "\"{$path}\" contains an invalid media reference; use plain media ids from the search_media tool.";
            }
        }

        $ids = array_column($normalized, 'id');
        $existing = Media::query()->whereIn('id', $ids)->pluck('id')->all();

        foreach (array_diff($ids, $existing) as $missing) {
            $this->errors[] = "Media id {$missing} in \"{$path}\" does not exist in the media library.";
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    protected function applyBrowsers(array &$fields, array $payload, array $config): void
    {
        $registryBrowsers = $config['browsers'] ?? [];
        $browsers = $payload['browsers'] ?? [];

        if (! is_array($browsers)) {
            $this->errors[] = 'The "browsers" section must be an object of browser name => [ids].';

            return;
        }

        $fields['browsers'] = [];

        foreach ($browsers as $name => $items) {
            if (! array_key_exists($name, $registryBrowsers)) {
                $this->errors[] = "Unknown browser \"{$name}\". Allowed browsers: ".(implode(', ', array_keys($registryBrowsers)) ?: '(none)').'.';

                continue;
            }

            $browserConfig = $registryBrowsers[$name];
            $normalized = $this->normalizeBrowserItems($items, [$browserConfig['model']], "browsers.{$name}");

            if (($browserConfig['max'] ?? null) && count($normalized) > $browserConfig['max']) {
                $this->errors[] = "Browser \"{$name}\" accepts at most {$browserConfig['max']} item(s).";

                continue;
            }

            $fields['browsers'][$name] = $normalized;
        }
    }

    /**
     * @param  array<int, string>  $targetModels
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeBrowserItems(mixed $items, array $targetModels, string $path): array
    {
        if (! is_array($items)) {
            $this->errors[] = "\"{$path}\" must be an array of ids.";

            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                if (count($targetModels) !== 1) {
                    $this->errors[] = "\"{$path}\" links to multiple content types; provide objects with id + endpointType.";

                    continue;
                }

                $normalized[] = ['id' => (int) $item, 'endpointType' => $targetModels[0]];
            } elseif (is_array($item) && isset($item['id'])) {
                $endpointType = $item['endpointType'] ?? (count($targetModels) === 1 ? $targetModels[0] : null);

                if (! $endpointType || ! in_array($endpointType, $targetModels, true)) {
                    $this->errors[] = "\"{$path}\" item ".$item['id'].' has an invalid endpointType.';

                    continue;
                }

                $normalized[] = ['id' => (int) $item['id'], 'endpointType' => $endpointType];
            } else {
                $this->errors[] = "\"{$path}\" contains an invalid reference; use ids from the search_content tool.";
            }
        }

        // Validate the referenced entries exist.
        foreach (collect($normalized)->groupBy('endpointType') as $modelClass => $group) {
            $ids = $group->pluck('id')->all();
            $existing = $modelClass::query()->whereIn('id', $ids)->pluck('id')->all();

            foreach (array_diff($ids, $existing) as $missing) {
                $this->errors[] = "\"{$path}\" references ".class_basename($modelClass)." id {$missing}, which does not exist.";
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     */
    protected function applySyncFields(array &$fields, array $payload, array $config): void
    {
        $syncConfig = $config['sync_fields'] ?? [];

        foreach ($payload['sync'] ?? [] as $name => $ids) {
            if (! array_key_exists($name, $syncConfig)) {
                $this->errors[] = "Unknown sync field \"{$name}\". Allowed: ".(implode(', ', array_keys($syncConfig)) ?: '(none)').'.';

                continue;
            }

            if (! is_array($ids)) {
                $this->errors[] = "\"sync.{$name}\" must be an array of ids.";

                continue;
            }

            $ids = array_map('intval', $ids);
            $modelClass = $syncConfig[$name];
            $existing = $modelClass::query()->whereIn('id', $ids)->pluck('id')->all();

            foreach (array_diff($ids, $existing) as $missing) {
                $this->errors[] = "\"sync.{$name}\" references ".class_basename($modelClass)." id {$missing}, which does not exist.";
            }

            $fields[$name] = $ids;
        }

        // Always submit every configured sync field so updates never wipe
        // relations that were merged in from current state by buildForUpdate().
        foreach (array_keys($syncConfig) as $name) {
            $fields[$name] ??= [];
        }
    }

    /**
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $config
     * @param  array<int, string>  $locales
     */
    protected function applyBlocks(array &$fields, array $payload, array $config, array $locales, ?TwillModelContract $existing): void
    {
        $editors = $config['block_editors'] ?? [];
        $blocksByEditor = $payload['blocks'] ?? [];

        if (! is_array($blocksByEditor)) {
            $this->errors[] = 'The "blocks" section must be an object of editor name => [blocks].';

            return;
        }

        $existingBlockIds = $existing && method_exists($existing, 'blocks')
            ? $existing->blocks()->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $fields['blocks'] = [];

        foreach ($blocksByEditor as $editor => $blocks) {
            if (! array_key_exists($editor, $editors)) {
                $this->errors[] = "Unknown block editor \"{$editor}\". Available editors: ".(implode(', ', array_keys($editors)) ?: '(none)').'.';

                continue;
            }

            if (! is_array($blocks)) {
                $this->errors[] = "\"blocks.{$editor}\" must be an array of block objects.";

                continue;
            }

            foreach (array_values($blocks) as $index => $block) {
                $built = $this->buildBlock($block, $editor, $editors[$editor], $locales, $existingBlockIds, "blocks.{$editor}[{$index}]");

                if ($built !== null) {
                    $fields['blocks'][] = $built;
                }
            }
        }

        $max = (int) config('twill-ai.max_blocks_per_request', 30);

        if ($this->blockCount > $max) {
            $this->errors[] = "Too many blocks in one request ({$this->blockCount}); the maximum is {$max}.";
        }
    }

    /**
     * @param  array<int, string>  $allowedBlocks
     * @param  array<int, string>  $locales
     * @param  array<int, int>  $existingBlockIds
     * @return array<string, mixed>|null
     */
    protected function buildBlock(mixed $block, string $editor, array $allowedBlocks, array $locales, array $existingBlockIds, string $path): ?array
    {
        if (! is_array($block)) {
            $this->errors[] = "\"{$path}\" must be a block object.";

            return null;
        }

        $type = $block['type'] ?? null;

        if (! is_string($type) || $type === '') {
            $this->errors[] = "\"{$path}\" is missing its \"type\".";

            return null;
        }

        if (! in_array($type, $allowedBlocks, true)) {
            $this->errors[] = "Block \"{$type}\" is not allowed in the \"{$editor}\" editor. Allowed: ".implode(', ', $allowedBlocks).'.';

            return null;
        }

        try {
            $component = $this->blockSchema->componentFor($type);
        } catch (TwillAiException $e) {
            $this->errors[] = "\"{$path}\": ".$e->getMessage();

            return null;
        }

        $this->blockCount++;

        $content = $block['content'] ?? [];

        if (! is_array($content)) {
            $this->errors[] = "\"{$path}\" content must be an object of field => value.";
            $content = [];
        }

        unset($content['browsers']);
        $this->validateBlockContentLocales($content, $locales, $path);

        $built = [
            'id' => $this->resolveBlockId($block['id'] ?? null, $existingBlockIds, $path),
            'type' => $component,
            'editor_name' => $editor,
            'content' => $content,
            'medias' => $this->buildBlockMedias($block['medias'] ?? [], $type, $path),
            'browsers' => $this->buildBlockBrowsers($block['browsers'] ?? [], $type, $path),
            'blocks' => [],
        ];

        foreach ($block['children'] ?? [] as $childKey => $children) {
            $built['blocks'][$childKey] = $this->buildChildren($children, (string) $childKey, $type, $locales, $existingBlockIds, "{$path}.children.{$childKey}");
        }

        return $built;
    }

    /**
     * @param  array<int, int>  $existingBlockIds
     */
    protected function resolveBlockId(mixed $id, array $existingBlockIds, string $path): int
    {
        if (! $this->useFreshBlockIds && $id !== null && (is_int($id) || ctype_digit((string) $id))) {
            $id = (int) $id;

            if (in_array($id, $existingBlockIds, true)) {
                return $id;
            }

            if ($id < self::NEW_BLOCK_ID_BASE) {
                $this->errors[] = "\"{$path}\" references block id {$id}, which does not belong to this entry.";
            }
        }

        return self::NEW_BLOCK_ID_BASE + (++$this->blockIdSequence);
    }

    /**
     * @param  array<int, string>  $locales
     * @param  array<string, mixed>  $content
     */
    protected function validateBlockContentLocales(array $content, array $locales, string $path): void
    {
        foreach ($content as $field => $value) {
            if (! is_array($value) || array_is_list($value)) {
                continue;
            }

            $keys = array_keys($value);
            $localeLike = array_intersect($keys, $locales);

            if ($localeLike === []) {
                continue;
            }

            foreach (array_diff($keys, $locales) as $unknown) {
                $this->errors[] = "\"{$path}\" field \"{$field}\" uses unknown locale \"{$unknown}\".";
            }
        }
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function buildBlockMedias(mixed $medias, string $blockType, string $path): array
    {
        if (! is_array($medias)) {
            $this->errors[] = "\"{$path}\" medias must be an object of role => [media ids].";

            return [];
        }

        $roles = $this->blockSchema->mediaRolesFor($blockType);
        $built = [];

        foreach ($medias as $role => $items) {
            if (! array_key_exists($role, $roles)) {
                $this->errors[] = "\"{$path}\": block \"{$blockType}\" has no media role \"{$role}\". Allowed: ".(implode(', ', array_keys($roles)) ?: '(none)').'.';

                continue;
            }

            $built[$role] = $this->normalizeMediaItems($items, "{$path}.medias.{$role}");
        }

        return $built;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function buildBlockBrowsers(mixed $browsers, string $blockType, string $path): array
    {
        if (! is_array($browsers)) {
            $this->errors[] = "\"{$path}\" browsers must be an object of name => [ids].";

            return [];
        }

        $targets = $this->blockSchema->browserTargetsFor($blockType);
        $built = [];

        foreach ($browsers as $name => $items) {
            if (! array_key_exists($name, $targets)) {
                $this->errors[] = "\"{$path}\": block \"{$blockType}\" has no browser \"{$name}\". Allowed: ".(implode(', ', array_keys($targets)) ?: '(none)').'.';

                continue;
            }

            $built[$name] = $this->normalizeBrowserItems($items, $targets[$name], "{$path}.browsers.{$name}");
        }

        return $built;
    }

    /**
     * Build the children of a block under one child key. Twill stores two
     * kinds of children identically (child row with parent_id + child_key):
     *  - nested block-editor children: real, typed blocks chosen from the
     *    block's BlockEditor field (e.g. a case slide's "content");
     *  - inline-repeater items: homogeneous rows (e.g. "answers", "cards").
     * The key is classified against the parent block's reflected schema, with
     * a "does each item carry a type?" fallback for depths the schema can't
     * describe (e.g. a repeater nested inside a repeater). Recurses to any depth.
     *
     * @param  array<int, string>  $locales
     * @param  array<int, int>  $existingBlockIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildChildren(mixed $children, string $childKey, string $parentSchemaName, array $locales, array $existingBlockIds, string $path): array
    {
        if (! is_array($children)) {
            $this->errors[] = "\"{$path}\" must be an array of child blocks.";

            return [];
        }

        $schema = $this->blockSchema->describeBlock($parentSchemaName) ?? [];

        $nestedEditors = [];
        foreach ($schema['nested_editors'] ?? [] as $editor) {
            $nestedEditors[$editor['name']] = $editor['allowed_blocks'];
        }

        $repeaterKeys = array_map(fn ($repeater) => $repeater['key'], $schema['repeaters'] ?? []);

        if (array_key_exists($childKey, $nestedEditors)) {
            return $this->buildNestedEditorChildren($children, $childKey, $nestedEditors[$childKey], $locales, $existingBlockIds, $path);
        }

        if (in_array($childKey, $repeaterKeys, true)) {
            return $this->buildRepeaterChildren($children, $childKey, $locales, $existingBlockIds, $path);
        }

        // Fallback for depths reflection can't classify: typed items are nested
        // blocks, untyped items are repeater rows.
        $first = $children[array_key_first($children)] ?? null;

        return is_array($first) && isset($first['type'])
            ? $this->buildNestedEditorChildren($children, $childKey, null, $locales, $existingBlockIds, $path)
            : $this->buildRepeaterChildren($children, $childKey, $locales, $existingBlockIds, $path);
    }

    /**
     * Build typed child blocks for a nested block editor.
     *
     * @param  array<int, mixed>  $children
     * @param  array<int, string>|null  $allowed  null = any registered block
     * @param  array<int, string>  $locales
     * @param  array<int, int>  $existingBlockIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildNestedEditorChildren(array $children, string $childKey, ?array $allowed, array $locales, array $existingBlockIds, string $path): array
    {
        $built = [];

        foreach (array_values($children) as $index => $child) {
            if (! is_array($child)) {
                $this->errors[] = "\"{$path}[{$index}]\" must be a block object with a \"type\".";

                continue;
            }

            $type = $child['type'] ?? null;

            if (! is_string($type) || $type === '') {
                $this->errors[] = "\"{$path}[{$index}]\" is missing its \"type\".";

                continue;
            }

            if ($allowed !== null && ! in_array($type, $allowed, true)) {
                $this->errors[] = "Block \"{$type}\" is not allowed in the \"{$childKey}\" editor. Allowed: ".implode(', ', $allowed).'.';

                continue;
            }

            try {
                $component = $this->blockSchema->componentFor($type);
            } catch (TwillAiException $e) {
                $this->errors[] = "\"{$path}[{$index}]\": ".$e->getMessage();

                continue;
            }

            $this->blockCount++;

            $content = is_array($child['content'] ?? null) ? $child['content'] : [];
            unset($content['browsers']);
            $this->validateBlockContentLocales($content, $locales, "{$path}[{$index}]");

            $node = [
                'id' => $this->resolveBlockId($child['id'] ?? null, $existingBlockIds, "{$path}[{$index}]"),
                'type' => $component,
                // Real block (not a repeater) so Twill resolves it from the
                // block list rather than the repeater list.
                'is_repeater' => false,
                'content' => $content,
                'medias' => $this->buildBlockMedias($child['medias'] ?? [], $type, "{$path}[{$index}]"),
                'browsers' => $this->buildBlockBrowsers($child['browsers'] ?? [], $type, "{$path}[{$index}]"),
                'blocks' => [],
            ];

            foreach ($child['children'] ?? [] as $grandKey => $grandChildren) {
                $node['blocks'][$grandKey] = $this->buildChildren($grandChildren, (string) $grandKey, $type, $locales, $existingBlockIds, "{$path}[{$index}].children.{$grandKey}");
            }

            $built[] = $node;
        }

        return $built;
    }

    /**
     * Build inline-repeater item rows under one repeater key.
     *
     * @param  array<int, mixed>  $children
     * @param  array<int, string>  $locales
     * @param  array<int, int>  $existingBlockIds
     * @return array<int, array<string, mixed>>
     */
    protected function buildRepeaterChildren(array $children, string $childKey, array $locales, array $existingBlockIds, string $path): array
    {
        try {
            $component = $this->blockSchema->repeaterComponentFor($childKey);
        } catch (TwillAiException $e) {
            $this->errors[] = "\"{$path}\": ".$e->getMessage();

            return [];
        }

        $built = [];

        foreach (array_values($children) as $index => $child) {
            if (! is_array($child)) {
                $this->errors[] = "\"{$path}[{$index}]\" must be an object with a \"content\" key.";

                continue;
            }

            $this->blockCount++;

            $content = is_array($child['content'] ?? null) ? $child['content'] : [];
            unset($content['browsers']);
            $this->validateBlockContentLocales($content, $locales, "{$path}[{$index}]");

            $node = [
                'id' => $this->resolveBlockId($child['id'] ?? null, $existingBlockIds, "{$path}[{$index}]"),
                'type' => $component,
                'content' => $content,
                'medias' => $this->buildBlockMedias($child['medias'] ?? [], $component, "{$path}[{$index}]"),
                'browsers' => [],
                'blocks' => [],
            ];

            // Repeater items may themselves contain nested repeaters
            // (e.g. table rows -> cells).
            foreach ($child['children'] ?? [] as $grandKey => $grandChildren) {
                $node['blocks'][$grandKey] = $this->buildChildren($grandChildren, (string) $grandKey, $childKey, $locales, $existingBlockIds, "{$path}[{$index}].children.{$grandKey}");
            }

            $built[] = $node;
        }

        return $built;
    }
}
