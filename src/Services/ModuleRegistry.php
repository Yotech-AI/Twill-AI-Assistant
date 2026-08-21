<?php

namespace TwillAi\Services;

use A17\Twill\Models\Contracts\TwillModelContract;
use A17\Twill\Repositories\ModuleRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Seo\SeoBridgeContract;

/**
 * The single source of truth for which Twill modules the AI agent can see
 * and what it may do with them. Backed by config('twill-ai.modules') plus
 * runtime introspection of the module's model (translated attributes,
 * media roles, slugs), so the agent always works against reality.
 */
class ModuleRegistry
{
    /** @var array<string, TwillModelContract>|null */
    protected ?array $modelInstances = null;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return config('twill-ai.modules', []);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        if (! $this->has($key)) {
            throw new TwillAiException(
                "Unknown module \"{$key}\". Available modules: ".implode(', ', array_keys($this->all())).'.'
            );
        }

        return $this->all()[$key];
    }

    public function allows(string $key, string $operation): bool
    {
        return in_array($operation, $this->get($key)['operations'] ?? [], true);
    }

    public function assertAllows(string $key, string $operation): void
    {
        if (! $this->allows($key, $operation)) {
            throw new TwillAiException(
                "Operation \"{$operation}\" is not allowed for module \"{$key}\"."
            );
        }
    }

    public function isSingleton(string $key): bool
    {
        return (bool) ($this->get($key)['singleton'] ?? false);
    }

    public function modelInstance(string $key): TwillModelContract
    {
        $modelClass = $this->get($key)['model'];

        return $this->modelInstances[$key] ??= new $modelClass;
    }

    public function repository(string $key): ModuleRepository
    {
        return app($this->get($key)['repository']);
    }

    /**
     * Locales the agent may write content in, as a flat list of locale codes.
     *
     * @return array<int, string>
     */
    public function locales(): array
    {
        if ($configured = config('twill-ai.locales')) {
            return $configured;
        }

        // Twill's helper returns the canonical, flat CMS locale list — it
        // normalizes astrotomic's nested country-variant config
        // (e.g. ['en', 'es' => ['MX', 'CO']] => ['en', 'es-MX', 'es-CO']).
        if (function_exists('getLocales')) {
            return getLocales();
        }

        return $this->flattenLocales(config('translatable.locales', [config('app.locale')]));
    }

    /**
     * Flatten an astrotomic-style locales config into plain locale codes.
     *
     * @param  array<int|string, mixed>  $locales
     * @return array<int, string>
     */
    protected function flattenLocales(array $locales): array
    {
        $flat = [];

        foreach ($locales as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $country) {
                    $flat[] = $key.'-'.$country;
                }
            } else {
                $flat[] = $value;
            }
        }

        return $flat;
    }

    /**
     * Compact module catalog for the agent.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalog(): array
    {
        return collect($this->all())->map(function (array $config, string $key) {
            $model = $this->modelInstance($key);

            return [
                'module' => $key,
                'label' => $config['label'] ?? $key,
                'description' => $config['description'] ?? null,
                'operations' => $config['operations'] ?? ['read'],
                'singleton' => (bool) ($config['singleton'] ?? false),
                'has_blocks' => ! empty($config['block_editors']),
                'has_slugs' => ! empty($model->slugAttributes ?? []),
            ];
        })->values()->all();
    }

    /**
     * Full schema of a module: every field, role and relation the agent can
     * fill, introspected from the model + the config registry.
     *
     * @return array<string, mixed>
     */
    public function describe(string $key): array
    {
        $config = $this->get($key);
        $model = $this->modelInstance($key);

        $mediaRoles = collect($model->mediasParams ?? [])->map(function ($crops) {
            return collect($crops)->map(
                fn ($definitions) => collect($definitions)->map(fn ($definition) => [
                    'crop_name' => $definition['name'] ?? 'default',
                    'ratio' => $definition['ratio'] ?? null,
                ])->values()->all()
            )->all();
        })->all();

        return [
            'module' => $key,
            'label' => $config['label'] ?? $key,
            'description' => $config['description'] ?? null,
            'operations' => $config['operations'] ?? ['read'],
            'singleton' => (bool) ($config['singleton'] ?? false),
            'locales' => $this->locales(),
            'translated_fields' => array_values($model->translatedAttributes ?? []),
            'extra_fields' => $config['extra_fields'] ?? [],
            'slugs' => ! empty($model->slugAttributes ?? [])
                ? 'Generated automatically per locale from: '.implode(', ', $model->slugAttributes)
                : 'This module has no slugs.',
            'media_roles' => $mediaRoles,
            'browsers' => collect($config['browsers'] ?? [])->map(fn ($browser, $name) => [
                'name' => $name,
                'related_module' => $browser['module'] ?? null,
                'max' => $browser['max'] ?? null,
            ])->values()->all(),
            'sync_fields' => collect($config['sync_fields'] ?? [])->map(fn ($modelClass, $name) => [
                'name' => $name,
                'related_model' => class_basename($modelClass),
            ])->values()->all(),
            'block_editors' => collect($config['block_editors'] ?? [])->map(fn ($blocks, $editor) => [
                'editor' => $editor,
                'allowed_blocks' => $blocks,
            ])->values()->all(),
            // Present only when the SEO Suite is installed. A module whose model
            // lacks HasSeo has no SEO surface at all, and the tools must be able
            // to say so rather than failing obscurely inside the Suite.
            ...(app(SeoBridgeContract::class)->available()
                ? ['seo' => ['available' => method_exists($model, 'seoEntry')]]
                : []),
            'notes' => 'Content is always saved as a DRAFT. Publishing and deleting are human-only actions.',
        ];
    }

    /**
     * Admin edit URL for an entry of this module.
     */
    public function editUrl(string $key, int|string|null $id = null): string
    {
        $config = $this->get($key);
        $route = $config['route'] ?? $key;
        $namePrefix = config('twill.admin_route_name_prefix', 'twill.');

        try {
            if ($config['singleton'] ?? false) {
                return route($namePrefix.$route);
            }

            return moduleRoute($route, '', 'edit', [$id]);
        } catch (\Throwable) {
            $adminPath = rtrim(ltrim(config('twill.admin_app_path', 'admin'), '/'), '/');

            return url($adminPath.'/'.$route.($id ? '/'.$id.'/edit' : ''));
        }
    }

    /**
     * Lightweight listing of entries (for relations / linking).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function searchEntries(string $key, ?string $query = null, ?bool $published = null, int $limit = 20): Collection
    {
        $model = $this->modelInstance($key);
        $table = $model->getTable();

        $builder = $model->newQuery()->orderByDesc($model->getQualifiedKeyName());

        if ($published !== null) {
            $builder->where($table.'.published', $published);
        }

        if ($query !== null && $query !== '') {
            if (method_exists($model, 'translations')) {
                $translationTable = $model->translations()->getRelated()->getTable();
                $foreignKey = $model->translations()->getForeignKeyName();

                $builder->whereExists(function ($sub) use ($translationTable, $foreignKey, $table, $query) {
                    $sub->selectRaw('1')
                        ->from($translationTable)
                        ->whereColumn($translationTable.'.'.$foreignKey, $table.'.id')
                        ->where($translationTable.'.title', 'like', '%'.$query.'%');
                });
            } else {
                // Single-language module: title/name live as plain columns.
                $builder->where(function ($sub) use ($table, $query) {
                    foreach (['title', 'name'] as $column) {
                        if (Schema::hasColumn($table, $column)) {
                            $sub->orWhere($table.'.'.$column, 'like', '%'.$query.'%');
                        }
                    }
                });
            }
        }

        return $builder->limit($limit)->get()->map(function ($entry) use ($key) {
            $titles = method_exists($entry, 'translations')
                ? $entry->translations->pluck('title', 'locale')->all()
                : [];

            return [
                'id' => $entry->id,
                'titles' => $titles,
                'title' => $this->entryDisplayTitle($entry),
                'published' => (bool) ($entry->published ?? false),
                'edit_url' => $this->editUrl($key, $entry->id),
            ];
        });
    }

    /**
     * Flat list of mentionable entries across every read-allowed module, for
     * the chat's "@" drawer. Each row is shaped for display as
     * "{module_label} : {title}".
     *
     * @return array<int, array{module: string, module_label: string, id: int, title: string, edit_url: string}>
     */
    public function mentionables(?string $query = null, int $perModule = 8, int $total = 50): array
    {
        $results = [];

        foreach ($this->all() as $key => $config) {
            if (! in_array('read', $config['operations'] ?? [], true)) {
                continue;
            }

            foreach ($this->searchEntries($key, $query, null, $perModule) as $entry) {
                $results[] = [
                    'module' => $key,
                    'module_label' => $config['label'] ?? $key,
                    'id' => $entry['id'],
                    'title' => $entry['title'] ?? ('#'.$entry['id']),
                    'edit_url' => $entry['edit_url'],
                ];

                if (count($results) >= $total) {
                    return $results;
                }
            }
        }

        return $results;
    }

    /**
     * Resolve a single "@" mention to a reference the agent can act on, or null
     * if the module is unknown / not readable / the entry no longer exists.
     *
     * @return array{module: string, label: string, id: int, title: string, edit_url: string}|null
     */
    public function entryReference(string $key, int|string $id): ?array
    {
        if (! $this->has($key) || ! $this->allows($key, 'read')) {
            return null;
        }

        $entry = $this->modelInstance($key)->newQuery()->find($id);

        if (! $entry) {
            return null;
        }

        return [
            'module' => $key,
            'label' => $this->get($key)['label'] ?? $key,
            'id' => $entry->id,
            'title' => $this->entryDisplayTitle($entry) ?? ('#'.$entry->id),
            'edit_url' => $this->editUrl($key, $entry->id),
        ];
    }

    /**
     * Best human-readable title for an entry: a non-empty translated title,
     * else a plain title/name column, else null.
     */
    protected function entryDisplayTitle(object $entry): ?string
    {
        if (method_exists($entry, 'translations')) {
            foreach ($entry->translations->pluck('title', 'locale')->all() as $title) {
                if (filled($title)) {
                    return $title;
                }
            }
        }

        foreach (['title', 'name'] as $attribute) {
            if (filled($entry->{$attribute} ?? null)) {
                return (string) $entry->{$attribute};
            }
        }

        return null;
    }
}
