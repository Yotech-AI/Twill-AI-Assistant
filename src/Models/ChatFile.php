<?php

namespace TwillAi\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A file in the shared Twill AI library. Stored on a private disk that is
 * never web-accessible; the agent reads it server-side and admins preview it
 * only through the authenticated files.show route. chat_id is optional — files
 * uploaded on the Uploads page (or the composer) belong to the library, not a
 * single chat.
 *
 * @property int $id
 * @property int|null $chat_id
 * @property int $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string|null $mime
 * @property int $size
 * @property int|null $media_id
 */
class ChatFile extends Model
{
    protected $table = 'twill_ai_chat_files';

    protected $fillable = [
        'chat_id',
        'user_id',
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'media_id',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'media_id' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Deleting the record removes the underlying file. Chat deletion goes
        // through the model (not a raw cascade) so this always fires.
        static::deleting(function (ChatFile $file) {
            $file->deleteFromDisk();
        });
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where($this->qualifyColumn('user_id'), $userId);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));
    }

    public function deleteFromDisk(): void
    {
        try {
            Storage::disk($this->disk)->delete($this->path);
        } catch (Throwable) {
            // A missing file must never block deleting the record.
        }
    }
}
