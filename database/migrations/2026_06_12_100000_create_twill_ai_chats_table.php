<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twill_ai_chats', function (Blueprint $table) {
            $table->id();
            // agent_conversations.id (laravel/ai) — filled after the first reply.
            $table->string('conversation_id', 36)->nullable()->unique();
            // Twill admin user (twill_users.id). Kept separate from the SDK's
            // user_id so CMS chats never mix with front-end users.
            $table->unsignedBigInteger('user_id')->index();
            $table->string('provider');
            $table->string('model');
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twill_ai_chats');
    }
};
