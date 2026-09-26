<?php

use App\Http\Controllers\ImportController;
use App\Http\Middleware\EnsureImportIsOpen;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| One-time import
|--------------------------------------------------------------------------
|
| Loaded outside the web group: `calm:push` authenticates with a bearer token,
| not a session, so there is no cookie and no CSRF token to carry. Every route
| is a 404 unless CALM_IMPORT_TOKEN is set and no import has finished.
|
*/

Route::middleware(['throttle:600,1', EnsureImportIsOpen::class])
    ->prefix('import')
    ->name('import.')
    ->group(function (): void {
        Route::post('start', [ImportController::class, 'start'])->name('start');
        Route::post('rows', [ImportController::class, 'rows'])->name('rows');
        Route::post('finish', [ImportController::class, 'finish'])->name('finish');
    });
