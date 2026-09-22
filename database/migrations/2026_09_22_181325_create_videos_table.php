<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('youtube_video_id', 16)->unique();

            // Archived at first ingest and never updated, so a retitled or
            // re-thumbnailed video on YouTube cannot change what is stored here.
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('thumbnail_path')->nullable();

            $table->timestamp('published_at');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->boolean('is_short')->nullable();
            $table->string('live_status', 16)->default('none');
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('watched_at')->nullable();
            $table->timestamp('hidden_at')->nullable();
            $table->timestamp('unavailable_at')->nullable();
            $table->timestamp('enriched_at')->nullable();
            $table->timestamps();

            $table->index('published_at');
            $table->index(['channel_id', 'published_at']);
            $table->index(['is_short', 'live_status', 'hidden_at', 'published_at']);
            $table->index('watched_at');
            $table->index('enriched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
