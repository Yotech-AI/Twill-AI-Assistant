<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links each MCP client registry row to the Passport OAuth client it represents.
 *
 * Under OAuth the access token belongs to the Twill user who approved the
 * connector, so the token alone cannot say which connector wrote a draft. The
 * token's client_id can, and this column maps it back to the dedicated
 * attribution user in twill_user_id.
 *
 * Nullable because oauth_clients.id is a uuid assigned by Passport when the
 * client is created, which happens after the row is inserted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table) {
            $table->uuid('oauth_client_id')->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table) {
            $table->dropUnique(['oauth_client_id']);
            $table->dropColumn('oauth_client_id');
        });
    }
};
