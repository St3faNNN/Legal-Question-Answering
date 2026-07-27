<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table): void {
            $table->id();
            $table->string('source')->default('mon');
            $table->string('title');
            $table->string('url')->unique();
            // Position on the source page (0 = latest); the dashboard orders by
            // this since the listing has no reliable per-item publish date.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['source', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_articles');
    }
};
