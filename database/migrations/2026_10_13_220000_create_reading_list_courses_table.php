<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The courses on Personal Assistant's reading list, as it last answered,
     * so the courses page still works when it does not.
     *
     * A copy, not a record: every refresh of the reading list replaces it,
     * alongside reading_list_items.
     */
    public function up(): void
    {
        Schema::create('reading_list_courses', function (Blueprint $table): void {
            $table->id();

            // Personal Assistant's id.
            $table->unsignedBigInteger('reading_list_id')->unique();
            $table->string('title');
            $table->text('url')->nullable();
            $table->text('notes')->nullable();

            // Personal Assistant's priority is important × urgent.
            $table->unsignedTinyInteger('important')->default(0);
            $table->unsignedTinyInteger('urgent')->default(0);

            // Minutes, as Personal Assistant keeps it.
            $table->unsignedInteger('estimated_minutes')->nullable();
            $table->unsignedTinyInteger('percent_complete')->default(0);

            // The list's own order.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_list_courses');
    }
};
