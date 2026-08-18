<?php

namespace TwillAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Companion record for a laravel/ai agent conversation, scoped to a Twill
 * admin user and carrying the per-chat provider/model selection.
 *
 * @property int $id
 * @property string|null $conversation_id
 * @property int $user_id
 * @property string $provider
 * @property string $model
 * @property Carbon|null $last_activity_at
 */
class Chat extends Model
{
    protected $table = 'twill_ai_chats';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'provider',
        'model',
        'status',
        'last_activity_at',
    ];

    protected $attributes = [
        'status' => 'idle',
    ];

    protected function casts(): array
    {
        return [
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * A chat is busy while a queued/streaming agent run is in flight. A busy
     * flag older than the job timeout (+ grace) is treated as stale — e.g. a
     * killed worker — so the chat never locks up permanently.
     */
    public function isBusy(): bool
    {
        if (! in_array($this->status, ['queued', 'streaming'], true)) {
            return false;
        }

        $staleAfter = (int) config('twill-ai.timeout', 600) + 120;

        return $this->updated_at === null || $this->updated_at->gt(now()->subSeconds($staleAfter));
    }

    public function effectiveStatus(): string
    {
        return $this->isBusy() ? $this->status : 'idle';
    }

    public static function cancelCacheKey(int $chatId): string
    {
        return "twill-ai:cancel:{$chatId}";
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $userId);
    }

    public function title(): string
    {
        if (! $this->conversation_id) {
            return 'New chat';
        }

        return DB::table('agent_conversations')
            ->where('id', $this->conversation_id)
            ->value('title') ?? 'New chat';
    }

    /**
     * Raw conversation messages (laravel/ai storage) for this chat.
     */
    public function conversationMessages(): Collection
    {
        if (! $this->conversation_id) {
            return collect();
        }

        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversation_id)
            ->orderBy('id')
            ->get();
    }
}
