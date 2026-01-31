<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Financial Reporting Module - Scheduled Jobs
Schedule::command('reports:process-schedules')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('reports:clear-cache')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground();
