<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // Whether sample_limit counts a day's uploads or a week's.
            $table->string('sample_period')->default('day')->after('sample_limit');

            // What you want from this channel, in your words, for the weekly
            // pick to judge a week's uploads against.
            $table->text('sample_note')->nullable()->after('sample_period');
        });

        Schema::table('videos', function (Blueprint $table): void {
            // Why the weekly pick chose this video, in a sentence.
            $table->text('pick_reason')->nullable()->after('sampled_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn(['sample_period', 'sample_note']);
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn('pick_reason');
        });
    }
};
