<?php

namespace TwillAi\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;
use TwillAi\Models\TwillUser;

/**
 * The registry of connectors permitted to reach the MCP endpoint.
 *
 * Under OAuth this model is no longer an authenticatable identity: the access
 * token belongs to the Twill user who approved the connector, and the token's
 * client_id identifies the connector itself. This row joins the two, so a
 * draft can be attributed to a dedicated "Claude Cowork" user rather than to
 * whichever admin happened to click Approve.
 *
 * It doubles as an allow-list. Passport's dynamic client registration endpoint
 * is public by design, so a self-registered client has no row here and is
 * refused by ActAsTwillUser even if a user approved it.
 */
class McpClient extends Model
{
    protected $fillable = [
        'name',
        'oauth_client_id',
        'twill_user_id',
        'last_used_at',
    ];

    /**
     * The Passport OAuth client this registry row represents.
     */
    public function oauthClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'oauth_client_id');
    }

    /**
     * The Twill user this client acts as, so its drafts are attributable in
     * the CMS revision history.
     */
    public function twillUser(): BelongsTo
    {
        return $this->belongsTo(
            config('twill.models.user', TwillUser::class),
            'twill_user_id'
        );
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }
}
