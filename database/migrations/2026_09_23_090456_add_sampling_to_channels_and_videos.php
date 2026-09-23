<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // How many of a day's uploads from this channel reach the feed.
            // Null means all of them, which is how every channel behaves
            // until you say otherwise.
            $table->unsignedSmallInteger('sample_limit')->nullable()->after('playback_rate');
        });

        Schema::table('videos', function (Blueprint $table): void {
            // Set aside by that limit: still archived, still on the channel
            // page, just not queueing up in the feed.
            $table->timestamp('sampled_out_at')->nullable()->after('hidden_at');
            $table->index('sampled_out_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('sample_limit');
        });

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropIndex(['sampled_out_at']);
            $table->dropColumn('sampled_out_at');
        });
    }
};
