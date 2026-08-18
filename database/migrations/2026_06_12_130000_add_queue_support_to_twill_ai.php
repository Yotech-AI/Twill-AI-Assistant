<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('twill_ai_chats', function (Blueprint $table) {
            // idle | queued | streaming — agent runs happen in a queued job.
            $table->string('status', 20)->default('idle')->after('model');
        });

        // Per-turn stream buffer: the queued job writes every agent stream
        // event here; the chat UI polls it (and can resume after navigating
        // away mid-generation). Cleared when the next message is sent.
        Schema::create('twill_ai_chat_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('chat_id')->index();
            $table->text('event');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twill_ai_chat_events');

        Schema::table('twill_ai_chats', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
