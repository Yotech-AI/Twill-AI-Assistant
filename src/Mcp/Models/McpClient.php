<?php

namespace TwillAi\Mcp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
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
 * refused by ActAsTwillUser — until a Twill editor approves it with
 * self-service on, which is what creates its row.
 */
class McpClient extends Model
{
    protected $fillable = [
        'name',
        'oauth_client_id',
        'twill_user_id',
        'approved_by',
        'approved_at',
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

    /**
     * The Twill user who approved this connector in the CMS. Null for one made
     * with `php artisan mcp:client-create`.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(
            config('twill.models.user', TwillUser::class),
            'approved_by'
        );
    }

    /**
     * Access tokens that would still be accepted: neither revoked nor expired.
     */
    public function liveTokenCount(): int
    {
        if ($this->oauth_client_id === null) {
            return 0;
        }

        return Token::query()
            ->where('client_id', $this->oauth_client_id)
            ->where('revoked', false)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->count();
    }

    protected function casts(): array
    {
        return [
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
