<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // The last snooze, kept after it ends so a video published during
            // it but only discovered later is still recognised.
            $table->timestamp('snoozed_from')->nullable()->after('sample_note');
            $table->timestamp('snoozed_until')->nullable()->after('snoozed_from');
        });

        Schema::table('videos', function (Blueprint $table): void {
            // Published while its channel was snoozed, so it never reaches the
            // feed. Still archived, like everything else.
            $table->timestamp('snoozed_at')->nullable()->after('pick_reason');
            $table->index('snoozed_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn(['snoozed_from', 'snoozed_until']);
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(['snoozed_at']);
            $table->dropColumn('snoozed_at');
        });
    }
};
