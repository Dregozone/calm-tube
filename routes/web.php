<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/feed')->name('home');

Route::middleware('auth')->group(function (): void {
    Route::livewire('feed', 'pages::feed')->name('feed');
    Route::livewire('channels', 'pages::channels.index')->name('channels.index');
});

require __DIR__.'/settings.php';
