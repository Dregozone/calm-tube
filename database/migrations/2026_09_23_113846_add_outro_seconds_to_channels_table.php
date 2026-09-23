<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // Null means the video ends where YouTube says it does. Some
            // channels close every upload with the same sponsor plug, and a
            // video you have finished watching is finished before that.
            $table->unsignedSmallInteger('outro_seconds')->nullable()->after('playback_rate');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('outro_seconds');
        });
    }
};
