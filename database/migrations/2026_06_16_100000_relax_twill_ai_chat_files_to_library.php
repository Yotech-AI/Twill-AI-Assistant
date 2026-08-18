<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('twill_ai_chat_files', function (Blueprint $table) {
            // Uploads now live in a shared, team-wide library that is not bound
            // to a chat (the Uploads page + the composer "Use files" picker). A
            // chat message merely references file ids, so chat_id is optional.
            $table->unsignedBigInteger('chat_id')->nullable()->change();

            // The Twill media-library record this file was ingested into, set on
            // first use so re-using the same image never creates a duplicate.
            $table->unsignedBigInteger('media_id')->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('twill_ai_chat_files', function (Blueprint $table) {
            $table->dropColumn('media_id');
            $table->unsignedBigInteger('chat_id')->nullable(false)->change();
        });
    }
};
