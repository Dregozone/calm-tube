<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // Null means normal speed. Some channels are worth watching at
            // 1.5x or 2x every time, and choosing that once per channel beats
            // reaching for the player's menu on every video.
            $table->float('playback_rate')->nullable()->after('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('playback_rate');
        });
    }
};
