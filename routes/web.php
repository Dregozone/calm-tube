<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\MixThumbnailController;
use App\Http\Controllers\ThumbnailController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/feed')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::livewire('feed', 'pages::feed')->name('feed');
    Route::livewire('watch/{video}', 'pages::watch')->name('videos.watch');
    Route::livewire('channels', 'pages::channels.index')->name('channels.index');
    Route::livewire('channels/{channel}', 'pages::channels.show')->name('channels.show');
    Route::livewire('music', 'pages::music')->name('music');

    Route::get('thumbnails/{video}', ThumbnailController::class)->name('thumbnails.show');
    Route::get('avatars/{channel}', AvatarController::class)->name('avatars.show');
    Route::get('music/{mix}/thumbnail', MixThumbnailController::class)->name('mixes.thumbnail');
});

require __DIR__.'/settings.php';
