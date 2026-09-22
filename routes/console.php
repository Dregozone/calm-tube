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

/*
 * Picks up anything the API could not answer for earlier, and re-checks
 * streams that may since have finished, so a missing key or an exhausted
 * quota heals itself.
 */
Schedule::command('calm:enrich')
    ->dailyAt('04:00')
    ->withoutOverlapping();
