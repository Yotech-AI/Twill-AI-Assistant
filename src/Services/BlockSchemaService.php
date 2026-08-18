<?php

namespace TwillAi\Services;

use A17\Twill\Facades\TwillBlocks;
use A17\Twill\Services\Blocks\Block as BlockDefinition;
use A17\Twill\Services\Forms\InlineRepeater;
use A17\Twill\TwillBlocks as TwillBlocksManager;
use ReflectionObject;
use Throwable;
use TwillAi\Exceptions\TwillAiException;

/**
 * Reflects Twill component blocks (TwillBlockComponent::getForm()) into a
 * machine-readable schema the AI agent can reason about: field names, types,
 * translatability, media roles, browser targets and inline repeaters.
 */
class BlockSchemaService
{
    /** @var array<string, array<string, mixed>> */
    protected array $cache = [];

    /**
     * Boot-time copy of Twill's block-directory registration, kept so queued
     * agent runs can re-seed it in a long-running worker.
     *
     * @var array<string, array<mixed>>|null
     */
    protected static ?array $registrationSnapshot = null;

    /**
     * Capture Twill's block-directory registration while it is still intact.
     * Twill fills these static lists in its provider register() and consumes
     * them when the block collection is first built; in a long-running queue
     * worker that happens once, leaving later runs with an empty registry.
     */
    public function captureRegistration(): void
    {
        if (self::$registrationSnapshot !== null) {
            return;
        }

        self::$registrationSnapshot = [
            'blocks' => TwillBlocksManager::$blockDirectories,
            'repeaters' => TwillBlocksManager::$repeatersDirectories,
            'components' => TwillBlocksManager::$componentBlockNamespaces,
            'manual' => TwillBlocksManager::$manualBlocks,
        ];
    }

    /**
     * Re-seed Twill's block registry (from the boot-time snapshot, falling back
     * to the configured directories) so a block collection rebuilt between
     * queue jobs is complete again. Safe — and intended — to call every run.
     */
    public function ensureRegistered(): void
    {
        if (self::$registrationSnapshot !== null) {
            TwillBlocksManager::$blockDirectories += self::$registrationSnapshot['blocks'];
            TwillBlocksManager::$repeatersDirectories += self::$registrationSnapshot['repeaters'];
            TwillBlocksManager::$componentBlockNamespaces += self::$registrationSnapshot['components'];
            TwillBlocksManager::$manualBlocks += self::$registrationSnapshot['manual'];
        }

        foreach ((array) config('twill.block_editor.directories.source.blocks', []) as $value) {
            if (isset($value['path'])) {
                TwillBlocksManager::$blockDirectories[$value['path']] ??= [
                    'source' => $value['source'] ?? 'app',
                    'renderNamespace' => null,
                ];
            }
        }

        foreach ((array) config('twill.block_editor.directories.source.repeaters', []) as $value) {
            if (isset($value['path'])) {
                TwillBlocksManager::$repeatersDirectories[$value['path']] ??= [
                    'source' => $value['source'] ?? 'app',
                    'renderNamespace' => null,
                ];
            }
        }

        // Inline repeaters re-register when block forms are parsed again.
        TwillBlocksManager::$loadedDynamicRepeaters = [];

        // Drop any schema cached against a previously-empty collection.
        $this->cache = [];
    }

    /**
     * Describe a list of blocks by name.
     *
     * @param  array<int, string>  $names
     * @return array<int, array<string, mixed>>
     */
    public function describeBlocks(array $names): array
    {
        return collect($names)
            ->map(fn (string $name) => $this->describeBlock($name))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function describeBlock(string $name): ?array
    {
        if (isset($this->cache[$name])) {
            return $this->cache[$name];
        }

        $definition = $this->findDefinition($name);

        if (! $definition) {
            return null;
        }

        $schema = [
            'block' => $definition->name,
            'title' => (string) $definition->title,
            'fields' => [],
            'media_roles' => [],
            'browsers' => [],
            'repeaters' => [],
            // Nested block editors (a BlockEditor field inside this block's
            // form): each holds child blocks chosen from an allowed list, sent
            // under the matching key in a block's "children".
            'nested_editors' => [],
        ];

        foreach ($this->formFieldsFor($definition) as $field) {
            $this->applyFieldToSchema($field, $schema);
        }

        return $this->cache[$name] = $schema;
    }

    public function blockExists(string $name): bool
    {
        return $this->findDefinition($name) !== null;
    }

    /**
     * Resolve the Vue component string Twill's repositories expect as the
     * incoming block "type" (e.g. app-text -> a17-block-app-text).
     */
    public function componentFor(string $name): string
    {
        $definition = $this->findDefinition($name);

        if (! $definition) {
            throw new TwillAiException("Unknown block \"{$name}\".");
        }

        return $definition->component;
    }

    /**
     * Resolve the repeater component for an inline repeater child key
     * (e.g. faq_items -> dynamic-repeater-faq_items).
     */
    public function repeaterComponentFor(string $childKey): string
    {
        $this->syncDynamicRepeaters();

        $candidates = [$childKey, 'dynamic-repeater-'.$childKey];

        foreach ($candidates as $candidate) {
            $repeater = TwillBlocks::getRepeaters()->first(
                fn (BlockDefinition $definition) => $definition->name === $candidate
            );

            if ($repeater) {
                return $repeater->component;
            }
        }

        throw new TwillAiException(
            "Unknown repeater \"{$childKey}\". Use the repeater keys returned by the list_blocks tool."
        );
    }

    /**
     * Browser field targets for a block: field name => possible model classes.
     *
     * @return array<string, array<int, string>>
     */
    public function browserTargetsFor(string $blockName): array
    {
        $schema = $this->describeBlock($blockName) ?? [];

        return collect($schema['browsers'] ?? [])
            ->mapWithKeys(fn (array $browser) => [$browser['name'] => $browser['models']])
            ->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function mediaRolesFor(string $blockName): array
    {
        $schema = $this->describeBlock($blockName) ?? [];

        return collect($schema['media_roles'] ?? [])
            ->mapWithKeys(fn (array $role) => [$role['name'] => $role])
            ->all();
    }

    protected function findDefinition(string $name): ?BlockDefinition
    {
        $this->syncDynamicRepeaters();

        return TwillBlocks::getBlocks()->first(
            fn (BlockDefinition $definition) => $definition->name === $name
        ) ?? TwillBlocks::getRepeaters()->first(
            fn (BlockDefinition $definition) => $definition->name === $name
        );
    }

    /**
     * Twill tracks dynamic (inline) repeaters in static properties that
     * outlive the app instance (e.g. across tests). When a fresh block
     * collection is built, already-"loaded" repeaters are skipped and go
     * missing — re-add any registered dynamic repeater the current
     * collection doesn't contain.
     */
    protected function syncDynamicRepeaters(): void
    {
        try {
            $collection = TwillBlocks::getBlockCollection();

            foreach (TwillBlocksManager::$dynamicRepeaters as $name => $repeater) {
                $blockName = 'dynamic-repeater-'.$name;

                if (! $collection->contains(fn (BlockDefinition $block) => $block->name === $blockName)) {
                    $collection->add($repeater->asBlock());
                    TwillBlocksManager::$loadedDynamicRepeaters[$name] = true;
                }
            }
        } catch (Throwable) {
            // Never let bookkeeping break a lookup; the lookup itself will report.
        }
    }

    /**
     * @return array<int, object>
     */
    protected function formFieldsFor(BlockDefinition $definition): array
    {
        $componentClass = $definition->componentClass ?? null;

        if (! $componentClass || ! class_exists($componentClass)) {
            return [];
        }

        try {
            $component = app($componentClass);
            $form = $component->getForm();
        } catch (Throwable) {
            return [];
        }

        return $this->flattenFormItems($form->all());
    }

    /**
     * Flatten nested form containers (fieldsets, columns) into a field list.
     *
     * @return array<int, object>
     */
    protected function flattenFormItems(iterable $items): array
    {
        $fields = [];

        foreach ($items as $item) {
            if (! is_object($item)) {
                continue;
            }

            if ($item instanceof InlineRepeater) {
                $fields[] = $item;

                continue;
            }

            $properties = $this->extractProperties($item);
            $nested = $properties['fields'] ?? null;

            // Containers (fieldsets, columns) expose nested fields; flatten them.
            if (is_iterable($nested) && ! isset($properties['name'])) {
                $fields = array_merge($fields, $this->flattenFormItems($nested));

                continue;
            }

            if (isset($properties['name'])) {
                $fields[] = $item;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    protected function applyFieldToSchema(object $field, array &$schema): void
    {
        $properties = $this->extractProperties($field);
        $type = class_basename($field);
        $name = $properties['name'] ?? null;

        if ($field instanceof InlineRepeater) {
            $childFields = [];
            $repeaterFields = $properties['fields'] ?? [];

            foreach ($this->flattenFormItems(is_iterable($repeaterFields) ? $repeaterFields : []) as $childField) {
                $childProperties = $this->extractProperties($childField);

                if (isset($childProperties['name'])) {
                    $childFields[] = $this->describeSimpleField($childField, $childProperties);
                }
            }

            $schema['repeaters'][] = [
                'key' => $name,
                'label' => $this->stringOrNull($properties['label'] ?? null),
                'fields' => $childFields,
            ];

            return;
        }

        if ($name === null) {
            return;
        }

        switch ($type) {
            case 'Medias':
                $schema['media_roles'][] = [
                    'name' => $name,
                    'max' => $properties['max'] ?? 1,
                    'crops' => $this->cropsForRole($name),
                ];

                return;

            case 'Browser':
                $schema['browsers'][] = [
                    'name' => $name,
                    'max' => $properties['max'] ?? null,
                    'models' => $this->browserModels($properties),
                ];

                return;

            case 'Files':
                $schema['fields'][] = [
                    'name' => $name,
                    'type' => 'file',
                    'translatable' => false,
                    'note' => 'File attachments cannot be set by the AI assistant — leave to a human editor.',
                ];

                return;

            case 'BlockEditor':
                $schema['nested_editors'][] = [
                    'name' => $name,
                    'allowed_blocks' => array_values($properties['blocks'] ?? []),
                ];

                return;

            default:
                $schema['fields'][] = $this->describeSimpleField($field, $properties);
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    protected function describeSimpleField(object $field, array $properties): array
    {
        $described = [
            'name' => $properties['name'],
            'type' => strtolower(class_basename($field)),
            // Twill's IsTranslatable trait stores the flag as $translated.
            'translatable' => (bool) ($properties['translated'] ?? $properties['translatable'] ?? false),
        ];

        if (! empty($properties['options'])) {
            $described['options'] = collect($properties['options'])
                ->map(fn ($option) => is_array($option) ? ($option['value'] ?? $option) : $option)
                ->values()
                ->all();
        }

        if (isset($properties['default']) && (is_scalar($properties['default']) || is_array($properties['default']))) {
            $described['default'] = $properties['default'];
        }

        if (! empty($properties['note'])) {
            $described['note'] = $this->stringOrNull($properties['note']);
        }

        return $described;
    }

    /**
     * Resolve a Browser field's target model classes. Twill stores a single
     * target as $moduleName and multiple targets as $modules entries.
     *
     * @param  array<string, mixed>  $properties
     * @return array<int, string>
     */
    protected function browserModels(array $properties): array
    {
        $models = [];

        if (! empty($properties['moduleName'])) {
            $models[] = $this->modelFromModuleName((string) $properties['moduleName']);
        }

        foreach ($properties['modules'] ?? [] as $module) {
            $moduleName = is_array($module) ? ($module['name'] ?? null) : $module;

            if (is_string($moduleName) && $moduleName !== '') {
                $models[] = class_exists($moduleName) ? $moduleName : $this->modelFromModuleName($moduleName);
            }
        }

        return array_values(array_unique(array_filter($models)));
    }

    protected function modelFromModuleName(string $moduleName): ?string
    {
        try {
            return getModelByModuleName($moduleName);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function cropsForRole(string $role): array
    {
        try {
            $crops = TwillBlocks::getAllCropConfigs()[$role] ?? [];
        } catch (Throwable) {
            $crops = [];
        }

        return collect($crops)->map(fn ($definitions, $crop) => [
            'crop' => $crop,
            'ratios' => collect($definitions)->map(fn ($definition) => $definition['name'] ?? 'default')->all(),
        ])->values()->all();
    }

    /**
     * Read all object properties (including protected) defensively.
     *
     * @return array<string, mixed>
     */
    protected function extractProperties(object $object): array
    {
        $properties = [];
        $reflection = new ReflectionObject($object);

        do {
            foreach ($reflection->getProperties() as $property) {
                if (array_key_exists($property->getName(), $properties)) {
                    continue;
                }

                $property->setAccessible(true);

                if ($property->isInitialized($object)) {
                    $properties[$property->getName()] = $property->getValue($object);
                }
            }
        } while ($reflection = $reflection->getParentClass());

        return $properties;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
