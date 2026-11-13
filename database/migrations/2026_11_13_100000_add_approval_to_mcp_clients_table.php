<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who approved a connector in the CMS, and when.
 *
 * Set when a Twill user approves an app that registered itself, or adds one
 * by hand on the Connectors page. Null for a connector made with
 * `php artisan mcp:client-create`, which the page shows as "artisan".
 *
 * approved_by is a twill_users id, deliberately without a foreign key: the
 * approver may be deleted later, and the connector must keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by')->nullable()->after('twill_user_id');
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_clients', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at']);
        });
    }
};
