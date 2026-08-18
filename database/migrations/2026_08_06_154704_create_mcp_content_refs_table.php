<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps a caller-supplied idempotency key onto the entry it created, so a
 * retried create_content call cannot produce a duplicate draft.
 *
 * One shared table rather than an external_ref column per module: seven
 * modules are registered today and the agent tooling is deliberately
 * registry-driven, so a per-module column would mean seven migrations and
 * would tie this to the current module list.
 *
 * "record_id" is nullable because the ref is claimed BEFORE the content is
 * created — the unique index on external_ref is what makes two concurrent
 * retries safe, and the id is filled in once the write succeeds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_content_refs', function (Blueprint $table) {
            $table->id();
            $table->string('external_ref')->unique();
            $table->string('module');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_content_refs');
    }
};
