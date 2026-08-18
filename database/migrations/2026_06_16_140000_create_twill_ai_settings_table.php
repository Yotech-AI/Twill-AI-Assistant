<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('twill_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64)->default('anthropic');
            // Encrypted at rest (APP_KEY). Never returned to the browser.
            $table->text('api_key')->nullable();
            $table->string('key_last_four', 8)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('default_model', 191)->nullable();
            $table->text('system_prompt')->nullable();
            $table->json('available_models')->nullable();
            $table->timestamp('models_fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('twill_ai_settings');
    }
};
