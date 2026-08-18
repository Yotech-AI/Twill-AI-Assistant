<?php

namespace TwillAi\Services;

use A17\Twill\Models\User as TwillUser;
use TwillAi\Exceptions\TwillAiException;
use TwillAi\Jobs\RunTwillAiChat;
use TwillAi\Models\Chat;
use TwillAi\Models\ChatEvent;
use TwillAi\Models\ChatFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatService
{
    /** @var array<int, string|null> In-request cache of uploader id => name. */
    protected array $uploaderNames = [];

    /**
     * Models offered in the picker (also the server-side whitelist).
     *
     * @return array<int, array<string, mixed>>
     */
    public function models(): array
    {
        return collect($this->modelCatalog())
            ->map(fn (array $model) => [
                'id' => $model['id'],
                'label' => $model['label'],
                'description' => $model['description'] ?? null,
            ])->values()->all();
    }

    public function defaultModelId(): string
    {
        return app(SettingsService::class)->defaultModelId() ?? config('twill-ai.default_model');
    }

    /**
     * @return array{id: string, provider: string, model: string}
     */
    public function resolveModel(?string $modelId): array
    {
        $modelId ??= $this->defaultModelId();

        $entry = collect($this->modelCatalog())->firstWhere('id', $modelId);

        if (! $entry) {
            throw new TwillAiException("Model \"{$modelId}\" is not available.");
        }

        return ['id' => $entry['id'], 'provider' => $entry['provider'], 'model' => $entry['model']];
    }

    /**
     * The active model catalog: the configured provider's fetched models when a
     * key is set on the Settings page, otherwise the static config whitelist.
     *
     * @return array<int, array{id: string, provider: string, model: string, label: string, description: string|null}>
     */
    protected function modelCatalog(): array
    {
        $settings = app(SettingsService::class);

        if ($settings->isConfigured()) {
            return $settings->modelCatalog();
        }

        return collect(config('twill-ai.models', []))
            ->map(fn (array $model) => [
                'id' => $model['id'],
                'provider' => $model['provider'],
                'model' => $model['model'],
                'label' => $model['label'] ?? $model['model'],
                'description' => $model['description'] ?? null,
            ])->values()->all();
    }

    public function createChat(int $userId, ?string $modelId = null): Chat
    {
        $model = $this->resolveModel($modelId);

        return Chat::create([
            'user_id' => $userId,
            'provider' => $model['provider'],
            'model' => $model['model'],
            'last_activity_at' => now(),
        ]);
    }

    public function findForUser(int $chatId, int $userId): Chat
    {
        return Chat::query()->forUser($userId)->findOrFail($chatId);
    }

    /**
     * Sidebar history (titles joined from the SDK's conversations table).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit = 50): Collection
    {
        return Chat::query()
            ->forUser($userId)
            ->leftJoin('agent_conversations', 'agent_conversations.id', '=', 'twill_ai_chats.conversation_id')
            ->orderByDesc('twill_ai_chats.last_activity_at')
            ->limit($limit)
            ->get([
                'twill_ai_chats.*',
                'agent_conversations.title as conversation_title',
            ])
            ->map(fn (Chat $chat) => [
                'id' => $chat->id,
                'title' => $chat->conversation_title ?? 'New chat',
                'model_id' => $chat->provider.':'.$chat->model,
                'last_activity_at' => $chat->last_activity_at?->toIso8601String(),
            ]);
    }

    public function updateChat(Chat $chat, ?string $title = null, ?string $modelId = null): Chat
    {
        if ($modelId !== null) {
            $model = $this->resolveModel($modelId);
            $chat->fill(['provider' => $model['provider'], 'model' => $model['model']]);
        }

        $chat->save();

        if ($title !== null && $chat->conversation_id) {
            DB::table('agent_conversations')
                ->where('id', $chat->conversation_id)
                ->update(['title' => $title, 'updated_at' => now()]);
        }

        return $chat;
    }

    /**
     * Queue an agent run for this chat. The HTTP request returns immediately;
     * the queued job streams events into the chat's buffer for polling.
     */
    /**
     * @param  array<int, int|string>  $attachmentIds
     * @param  array<int, array{module?: string, id?: int}>  $mentions
     */
    public function queueMessage(Chat $chat, int $userId, string $message, array $attachmentIds = [], array $mentions = []): Chat
    {
        if ($chat->isBusy()) {
            throw new TwillAiException('The assistant is still working on the previous message.');
        }

        // Keep only attachments that still exist in the shared library, then
        // resolve mentions to full references (also used to render chips).
        $attachmentIds = $this->existingFileIds($attachmentIds);
        $references = $this->resolveMentions($mentions);

        // Fresh buffer per turn. History lives in the SDK's conversation
        // tables; the buffer only holds the in-flight turn (for live polling
        // and for resuming after the editor navigates away).
        ChatEvent::query()->where('chat_id', $chat->id)->delete();
        Cache::forget(Chat::cancelCacheKey($chat->id));

        ChatEvent::record($chat->id, [
            'type' => 'twill_ai.user_message',
            'content' => $message,
            'attachments' => $this->fileMetaForIds($attachmentIds),
            'mentions' => $references,
        ]);

        $chat->forceFill(['status' => 'queued', 'last_activity_at' => now()])->save();

        RunTwillAiChat::dispatch(
            $chat->id,
            $userId,
            $message,
            $attachmentIds,
            array_map(fn (array $reference) => ['module' => $reference['module'], 'id' => $reference['id']], $references),
        );

        return $chat;
    }

    /**
     * Store uploaded files in the shared library on the private disk.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function storeLibraryUploads(int $userId, array $files): array
    {
        $disk = config('twill-ai.uploads.disk', 'twill-ai');

        $stored = [];

        foreach ($files as $upload) {
            $extension = strtolower($upload->getClientOriginalExtension() ?: (string) $upload->guessExtension());
            $name = (string) Str::uuid().($extension !== '' ? '.'.$extension : '');
            $path = $upload->storeAs('lib', $name, ['disk' => $disk]);

            $file = ChatFile::create([
                'chat_id' => null,
                'user_id' => $userId,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $upload->getClientOriginalName(),
                'mime' => $upload->getMimeType() ?: $upload->getClientMimeType(),
                'size' => $upload->getSize() ?: 0,
            ]);

            $stored[] = $this->fileMeta($file);
        }

        return $stored;
    }

    /**
     * Look up a library file by id. The library is shared, so any authenticated
     * admin may read it (it is still never web-public).
     */
    public function findFile(int $fileId): ChatFile
    {
        return ChatFile::query()->findOrFail($fileId);
    }

    public function deleteChatFile(ChatFile $file): void
    {
        $file->delete();
    }

    /**
     * The whole shared library, newest first, for the Uploads page and the
     * composer "Use files" picker.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFiles(int $limit = 200): array
    {
        return ChatFile::query()
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (ChatFile $file) => $this->fileMeta($file))
            ->all();
    }

    /**
     * Mentionable entries for the "@" drawer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mentionables(?string $query = null): array
    {
        return app(ModuleRegistry::class)->mentionables($query);
    }

    /**
     * Display metadata for a stored file (upload response, chips, library grid).
     *
     * @return array<string, mixed>
     */
    public function fileMeta(ChatFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => $file->original_name,
            'mime' => $file->mime,
            'size' => $file->size,
            'is_image' => $file->isImage(),
            'media_id' => $file->media_id,
            'uploaded_by' => $this->uploaderName($file->user_id),
            'created_at' => $file->created_at?->toIso8601String(),
            'preview_url' => $this->filePreviewUrl($file),
        ];
    }

    protected function filePreviewUrl(ChatFile $file): string
    {
        $prefix = config('twill.admin_route_name_prefix', 'twill.').'ai.';

        return route($prefix.'files.show', ['file' => $file->id]);
    }

    protected function uploaderName(int $userId): ?string
    {
        return $this->uploaderNames[$userId] ??= TwillUser::query()->whereKey($userId)->value('name');
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, array<string, mixed>>
     */
    protected function fileMetaForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return ChatFile::query()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn (ChatFile $file) => $this->fileMeta($file))
            ->all();
    }

    /**
     * Keep only ids that exist in the shared library, preserving input order.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, int>
     */
    protected function existingFileIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        $present = array_flip(ChatFile::query()->whereIn('id', $ids)->pluck('id')->all());

        return array_values(array_filter($ids, fn (int $id) => isset($present[$id])));
    }

    /**
     * @param  array<int, array{module?: string, id?: int}>  $mentions
     * @return array<int, array<string, mixed>>
     */
    protected function resolveMentions(array $mentions): array
    {
        $registry = app(ModuleRegistry::class);
        $resolved = [];

        foreach ($mentions as $mention) {
            $module = $mention['module'] ?? null;
            $id = $mention['id'] ?? null;

            if ($module === null || $id === null) {
                continue;
            }

            if ($reference = $registry->entryReference((string) $module, (int) $id)) {
                $resolved[] = $reference;
            }
        }

        return $resolved;
    }

    /**
     * Buffered events after the given id, for the polling UI.
     *
     * @return array{status: string, events: array<int, array{id: int, data: mixed}>}
     */
    public function eventsAfter(Chat $chat, int $afterId): array
    {
        $events = ChatEvent::query()
            ->where('chat_id', $chat->id)
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (ChatEvent $event) => [
                'id' => $event->id,
                'data' => json_decode($event->event, true),
            ])
            ->values()
            ->all();

        return [
            'status' => $chat->effectiveStatus(),
            'events' => $events,
        ];
    }

    /**
     * Ask the in-flight job to stop after the next event. The job writes the
     * terminal turn_complete event itself.
     */
    public function requestCancel(Chat $chat): void
    {
        if ($chat->isBusy()) {
            Cache::put(Chat::cancelCacheKey($chat->id), true, 3600);
        }
    }

    /**
     * Deleting a chat is a human UI action (chats are not CMS content).
     */
    public function deleteChat(Chat $chat): void
    {
        // Library files are shared and survive chat deletion (they are not CMS
        // content and may be reused by other chats).
        if ($chat->conversation_id) {
            DB::table('agent_conversation_messages')->where('conversation_id', $chat->conversation_id)->delete();
            DB::table('agent_conversations')->where('id', $chat->conversation_id)->delete();
        }

        ChatEvent::query()->where('chat_id', $chat->id)->delete();

        $chat->delete();
    }

    /**
     * Message history shaped for the chat UI.
     *
     * @return array<int, array<string, mixed>>
     */
    public function uiMessages(Chat $chat): array
    {
        return $chat->conversationMessages()->map(function ($message) {
            $toolCalls = collect(json_decode($message->tool_calls ?: '[]', true))
                ->map(fn (array $call) => [
                    'name' => $call['name'] ?? 'tool',
                    'arguments' => $call['arguments'] ?? [],
                ])->values()->all();

            return [
                'role' => $message->role,
                'content' => $message->content,
                'tool_calls' => $toolCalls,
                'created_at' => $message->created_at,
            ];
        })->values()->all();
    }
}
