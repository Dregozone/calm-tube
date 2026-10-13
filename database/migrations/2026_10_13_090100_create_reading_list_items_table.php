<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The YouTube videos on Personal Assistant's reading list, as it last
     * answered, so the videos page still works when it does not.
     *
     * A copy, not a record: every refresh replaces it with whatever the list
     * holds now. The videos themselves are archived in the videos table like
     * any other.
     */
    public function up(): void
    {
        Schema::create('reading_list_items', function (Blueprint $table): void {
            $table->id();

            // Personal Assistant's id, which progress is written back to.
            $table->unsignedBigInteger('reading_list_id')->unique();
            $table->string('youtube_video_id', 16)->index();
            $table->string('title');
            $table->text('url');
            $table->string('category')->nullable();
            $table->unsignedTinyInteger('percent_complete')->default(0);

            // The list's own order, which is its priority.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_list_items');
    }
};
