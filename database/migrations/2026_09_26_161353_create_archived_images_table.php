<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The archived thumbnails and avatars, kept in the database because the
        // production filesystem does not survive a deploy. A table of its own so
        // that loading a video never loads its picture.
        Schema::create('archived_images', function (Blueprint $table): void {
            $table->id();
            $table->string('path')->unique();
            $table->longText('contents');
            $table->string('mime_type');
            $table->unsignedInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_images');
    }
};
