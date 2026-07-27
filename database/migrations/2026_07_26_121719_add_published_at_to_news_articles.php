<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_articles', function (Blueprint $table): void {
            // Set to when the scraper first sees the article (MON exposes no
            // real publication date). Nullable so existing rows backfill lazily.
            $table->timestamp('published_at')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('news_articles', function (Blueprint $table): void {
            $table->dropColumn('published_at');
        });
    }
};
