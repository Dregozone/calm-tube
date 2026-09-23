<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            // How far into the video you had got. The embedded player forgets
            // between visits, so a forty minute talk is otherwise all or
            // nothing. Null means you have not started, or you finished.
            $table->unsignedInteger('resume_seconds')->nullable()->after('duration_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('resume_seconds');
        });
    }
};
