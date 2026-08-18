<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Non-human clients allowed to reach the MCP endpoint (Claude Cowork).
 *
 * A dedicated table rather than reusing App\Models\User: that model is the
 * Cashier-billable customer, and a service account there would surface in
 * customer queries and billing. Sanctum tokens hang off this model instead.
 *
 * twill_user_id links the client to a real Twill user so content it writes is
 * attributable — Twill stamps revisions with the twill_users guard's id, which
 * would otherwise be null for everything an MCP client creates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('twill_user_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_clients');
    }
};
