<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            // Set once a video has been on the reading list, and never
            // cleared: such a video belongs to the videos page, not the feed.
            $table->timestamp('on_reading_list_at')->nullable()->after('snoozed_at')->index();
        });

        DB::table('videos')
            ->whereIn('youtube_video_id', DB::table('reading_list_items')->select('youtube_video_id'))
            ->update(['on_reading_list_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(['on_reading_list_at']);
            $table->dropColumn('on_reading_list_at');
        });
    }
};
