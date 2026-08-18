<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent payloads (a big block tree as a tool_call, or a get_content result)
 * can exceed TEXT's 64KB limit, which truncates/crashes the queued run. Widen
 * the buffer + conversation message columns to LONGTEXT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('twill_ai_chat_events', function (Blueprint $table) {
            $table->longText('event')->change();
        });

        if (Schema::hasTable('agent_conversation_messages')) {
            Schema::table('agent_conversation_messages', function (Blueprint $table) {
                $table->longText('content')->change();
                $table->longText('attachments')->change();
                $table->longText('tool_calls')->change();
                $table->longText('tool_results')->change();
                $table->longText('usage')->change();
                $table->longText('meta')->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('twill_ai_chat_events', function (Blueprint $table) {
            $table->text('event')->change();
        });

        if (Schema::hasTable('agent_conversation_messages')) {
            Schema::table('agent_conversation_messages', function (Blueprint $table) {
                $table->text('content')->change();
                $table->text('attachments')->change();
                $table->text('tool_calls')->change();
                $table->text('tool_results')->change();
                $table->text('usage')->change();
                $table->text('meta')->change();
            });
        }
    }
};
