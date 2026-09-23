<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Long music videos kept for listening to, apart from the channels you
     * follow: a mix is added by hand, one link at a time, and never reaches
     * the feed.
     */
    public function up(): void
    {
        Schema::create('mixes', function (Blueprint $table): void {
            $table->id();
            $table->string('youtube_video_id', 11)->unique();

            // Archived once, when the mix is added, like a video's title.
            $table->string('title');
            $table->string('author_name')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('thumbnail_path')->nullable();

            // From the Data API when there is a key, otherwise from the
            // player the first time the mix is played.
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('resume_seconds')->nullable();
            $table->timestamp('last_played_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mixes');
    }
};
