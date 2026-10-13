<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // False for a channel archived only because a video on your
            // reading list came from it: kept so the video has an owner, but
            // never refreshed, never in the feed and not on the channels page.
            $table->boolean('is_followed')->default(true)->after('is_enabled')->index();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropIndex(['is_followed']);
            $table->dropColumn('is_followed');
        });
    }
};
