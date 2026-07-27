<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Keep the dashboard "newspaper" section fresh: twice a day at 08:00 and 12:00
// (Skopje time). Idempotent (upsert by URL), so overlaps are harmless. Runs on
// the scheduler in the worker container.
Schedule::command('news:scrape')
    ->twiceDailyAt(8, 12, 0)
    ->timezone('Europe/Skopje')
    ->withoutOverlapping();
