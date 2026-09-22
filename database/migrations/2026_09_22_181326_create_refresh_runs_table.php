<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refresh_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('trigger', 16);
            $table->string('status', 16);
            $table->unsignedInteger('new_videos_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['channel_id', 'started_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_runs');
    }
};
