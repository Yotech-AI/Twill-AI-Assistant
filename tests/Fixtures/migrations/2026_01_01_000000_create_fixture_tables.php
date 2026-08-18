<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            createDefaultTableFields($table);
            $table->integer('position')->unsigned()->nullable();
        });

        Schema::create('article_translations', function (Blueprint $table) {
            createDefaultTranslationsTableFields($table, 'article');
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('article_slugs', function (Blueprint $table) {
            createDefaultSlugsTableFields($table, 'article');
        });

        Schema::create('singletons', function (Blueprint $table) {
            createDefaultTableFields($table);
            $table->string('title', 200)->nullable();
            $table->text('description')->nullable();
            $table->string('seo_title', 200)->nullable();
            $table->text('internal_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('singletons');
        Schema::dropIfExists('article_slugs');
        Schema::dropIfExists('article_translations');
        Schema::dropIfExists('articles');
    }
};
