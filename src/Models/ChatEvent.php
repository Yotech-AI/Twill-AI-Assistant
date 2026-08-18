<?php

namespace TwillAi\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One buffered agent stream event for a chat's in-flight turn. The `event`
 * column stores the laravel/ai stream event's JSON string verbatim (plus a
 * few `twill_ai.*` synthetic events such as the user message and the
 * turn-complete marker).
 */
class ChatEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'twill_ai_chat_events';

    protected $fillable = ['chat_id', 'event'];

    public static function record(int $chatId, array|string $event): void
    {
        static::create([
            'chat_id' => $chatId,
            'event' => is_string($event) ? $event : json_encode($event, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
