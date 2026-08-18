<?php

namespace TwillAi\Services;

use A17\Twill\Models\Contracts\TwillModelContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Serializes a Twill module entry into the agent-facing payload shape — the
 * same shape PayloadBuilder accepts. This both powers the get_content tool
 * and guarantees lossless updates: update payloads are merged ON TOP of the
 * serialized current state, so Twill's "absent means delete" form semantics
 * (blocks, medias, sync relations) can never wipe content the agent did not
 * explicitly touch.
 */
class ContentSerializer
{
    public function __construct(
        protected ModuleRegistry $registry,
        protected BlockSchemaService $blockSchema,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(string $moduleKey, TwillModelContract $entry): array
    {
        $config = $this->registry->get($moduleKey);

        if (method_exists($entry, 'translations')) {
            $entry->loadMissing('translations');
        }

        return [
            'id' => $entry->id,
            'published' => (bool) $entry->published,
            'edit_url' => $this->registry->editUrl($moduleKey, $entry->id),
            'locales' => $this->activeLocales($entry),
            'fields' => $this->serializeFields($entry, $config),
            'slugs' => $this->serializeSlugs($entry),
            'medias' => $this->serializeMedias($entry),
            'browsers' => $this->serializeBrowsers($entry, $config),
            'sync' => $this->serializeSyncFields($entry, $config),
            'blocks' => $this->serializeBlocks($entry, $config),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function activeLocales(TwillModelContract $entry): array
    {
        if (! method_exists($entry, 'translations')) {
            return [];
        }

        return $entry->translations->where('active', true)->pluck('locale')->values()->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function serializeFields(TwillModelContract $entry, array $config): array
    {
        $fields = [];

        foreach ($entry->translatedAttributes ?? [] as $attribute) {
            $fields[$attribute] = $entry->translations
                ->mapWithKeys(fn ($translation) => [$translation->locale => $translation->{$attribute}])
                ->all();
        }

        foreach (array_keys($config['extra_fields'] ?? []) as $attribute) {
            $fields[$attribute] = $entry->{$attribute};
        }

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    protected function serializeSlugs(TwillModelContract $entry): array
    {
        if (! method_exists($entry, 'slugs')) {
            return [];
        }

        return $entry->slugs()
            ->where('active', true)
            ->pluck('slug', 'locale')
            ->all();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function serializeMedias(TwillModelContract $entry): array
    {
        if (! method_exists($entry, 'medias')) {
            return [];
        }

        return $this->mediasFromRelation($entry->medias);
    }

    /**
     * Shared pivot-to-payload mapping for entry and block medias. Crops are
     * preserved so updates never lose manual crops made by editors.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function mediasFromRelation(Collection $medias): array
    {
        $result = [];

        foreach ($medias->groupBy('pivot.role') as $role => $mediasByRole) {
            foreach ($mediasByRole->groupBy('id') as $mediaId => $pivotRows) {
                $crops = [];

                foreach ($pivotRows as $row) {
                    $crops[$row->pivot->crop] = [
                        'name' => $row->pivot->ratio,
                        'x' => $row->pivot->crop_x,
                        'y' => $row->pivot->crop_y,
                        'width' => $row->pivot->crop_w,
                        'height' => $row->pivot->crop_h,
                    ];
                }

                $result[$role][] = [
                    'id' => (int) $mediaId,
                    'alt_text' => $pivotRows->first()->alt_text,
                    'crops' => $crops,
                ];
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function serializeBrowsers(TwillModelContract $entry, array $config): array
    {
        $browsers = [];

        foreach (array_keys($config['browsers'] ?? []) as $name) {
            $browsers[$name] = [];
        }

        $related = DB::table(config('twill.related_table', 'twill_related'))
            ->where('subject_type', $entry->getMorphClass())
            ->where('subject_id', $entry->getKey())
            ->orderBy('position')
            ->get();

        foreach ($related as $item) {
            $browsers[$item->browser_name][] = [
                'id' => (int) $item->related_id,
                'endpointType' => $item->related_type,
            ];
        }

        return $browsers;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array<int, int>>
     */
    protected function serializeSyncFields(TwillModelContract $entry, array $config): array
    {
        $sync = [];

        foreach (array_keys($config['sync_fields'] ?? []) as $field) {
            $relation = Str::camel($field);

            $sync[$field] = method_exists($entry, $relation)
                ? $entry->{$relation}()->pluck($entry->{$relation}()->getRelated()->getQualifiedKeyName())->map(fn ($id) => (int) $id)->all()
                : [];
        }

        return $sync;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function serializeBlocks(TwillModelContract $entry, array $config): array
    {
        if (! method_exists($entry, 'blocks')) {
            return [];
        }

        $allBlocks = $entry->blocks()->with('medias')->orderBy('position')->get();
        $childrenByParent = $allBlocks->whereNotNull('parent_id')->groupBy('parent_id');

        $result = [];

        foreach (array_keys($config['block_editors'] ?? []) as $editor) {
            $result[$editor] = [];
        }

        foreach ($allBlocks->whereNull('parent_id') as $block) {
            $editor = $block->editor_name ?? 'default';
            $result[$editor] ??= [];
            $result[$editor][] = $this->serializeBlockNode($block, $childrenByParent);
        }

        return $result;
    }

    /**
     * Recursively serialize a block and its descendant tree. Every node keeps
     * its "type" so updates can rebuild it; PayloadBuilder classifies each
     * child key (nested block editor vs inline repeater) when rebuilding.
     *
     * @param  Collection<int, Collection>  $childrenByParent
     * @return array<string, mixed>
     */
    protected function serializeBlockNode(object $block, Collection $childrenByParent): array
    {
        $content = (array) $block->content;
        $browsers = $this->serializeBlockBrowsers($block, $content);
        unset($content['browsers']);

        $node = [
            'id' => $block->id,
            'type' => $block->type,
            'content' => $content,
            'medias' => $this->mediasFromRelation($block->medias),
            'browsers' => $browsers,
            'children' => [],
        ];

        foreach (($childrenByParent[$block->id] ?? collect())->sortBy('position')->groupBy('child_key') as $childKey => $kids) {
            $node['children'][(string) $childKey] = $kids
                ->map(fn ($child) => $this->serializeBlockNode($child, $childrenByParent))
                ->values()
                ->all();
        }

        return $node;
    }

    /**
     * Block browsers live as id arrays in the content JSON; the rich
     * endpointType comes from twill_related rows saved by BlockRepository.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function serializeBlockBrowsers(object $block, array $content): array
    {
        $fromContent = collect($content['browsers'] ?? []);

        if ($fromContent->isEmpty()) {
            return [];
        }

        $related = DB::table(config('twill.related_table', 'twill_related'))
            ->where('subject_type', 'blocks')
            ->where('subject_id', $block->id)
            ->orderBy('position')
            ->get()
            ->groupBy('browser_name');

        return $fromContent->map(function ($ids, $browserName) use ($related, $block) {
            $rows = $related[$browserName] ?? collect();

            if ($rows->isNotEmpty()) {
                return $rows->map(fn ($row) => [
                    'id' => (int) $row->related_id,
                    'endpointType' => $row->related_type,
                ])->values()->all();
            }

            // Fallback: ids only — resolve the target from the block schema.
            $targets = $this->blockSchema->browserTargetsFor($block->type)[$browserName] ?? [];

            return collect($ids)->map(fn ($id) => array_filter([
                'id' => (int) $id,
                'endpointType' => count($targets) === 1 ? $targets[0] : null,
            ]))->values()->all();
        })->all();
    }
}
