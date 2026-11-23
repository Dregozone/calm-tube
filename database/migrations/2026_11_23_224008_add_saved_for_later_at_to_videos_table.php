<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            // Set from the watch page to move a video out of the main feed and
            // onto the Saved tab. Cleared once it is watched or hidden.
            $table->timestamp('saved_for_later_at')->nullable()->after('on_reading_list_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(['saved_for_later_at']);
            $table->dropColumn('saved_for_later_at');
        });
    }
};
