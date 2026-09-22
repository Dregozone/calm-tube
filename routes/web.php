<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\ThumbnailController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/feed')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::livewire('feed', 'pages::feed')->name('feed');
    Route::livewire('channels', 'pages::channels.index')->name('channels.index');

    Route::get('thumbnails/{video}', ThumbnailController::class)->name('thumbnails.show');
    Route::get('avatars/{channel}', AvatarController::class)->name('avatars.show');
});

require __DIR__.'/settings.php';
