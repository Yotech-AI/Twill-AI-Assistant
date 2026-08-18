<?php

namespace TwillAi\Mcp\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A claim on a caller-supplied idempotency key, and the entry it produced.
 *
 * A row with a null record_id is an in-flight claim: another call is creating
 * content for this ref right now.
 */
class ContentRef extends Model
{
    protected $table = 'mcp_content_refs';

    protected $fillable = [
        'external_ref',
        'module',
        'record_id',
    ];

    protected function casts(): array
    {
        return [
            'record_id' => 'integer',
        ];
    }
}
