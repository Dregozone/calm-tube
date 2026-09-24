<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per closed week of a weekly channel: the decision about
        // which of that week's uploads reached the feed. Written once, so a
        // week is never picked over twice.
        Schema::create('channel_digests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->date('week_starts_on');
            $table->string('method');
            $table->string('model')->nullable();
            $table->json('picked_video_ids');
            $table->timestamps();

            $table->unique(['channel_id', 'week_starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_digests');
    }
};
