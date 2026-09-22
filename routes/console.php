<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Refreshing
|--------------------------------------------------------------------------
|
| Only runs while `php artisan schedule:work` is running, which on a local
| machine is usually not. The manual refresh buttons in the UI are the path
| that always works.
|
*/

Schedule::command('calm:refresh', ['--scheduled'])
    ->hourly()
    ->withoutOverlapping();
