<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twill_ai_chat_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('twill_ai_chats')->cascadeOnDelete();
            // Twill admin user (twill_users.id) — files are scoped to their owner.
            $table->unsignedBigInteger('user_id')->index();
            $table->string('disk', 64);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 191)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twill_ai_chat_files');
    }
};
