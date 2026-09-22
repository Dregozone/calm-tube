<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table): void {
            $table->id();
            $table->string('youtube_channel_id', 32)->unique();
            $table->string('title');
            $table->string('custom_name')->nullable();
            $table->string('handle', 64)->nullable();
            $table->string('avatar_url')->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('uploads_playlist_id', 32)->nullable();
            $table->boolean('is_enabled')->default(true)->index();
            $table->string('feed_etag')->nullable();
            $table->string('feed_last_modified')->nullable();
            $table->timestamp('last_refreshed_at')->nullable();
            $table->text('last_refresh_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
