<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refreshes the voice list and sample tracks every day at 3:00 AM
Schedule::command('voices:refresh')->dailyAt('03:00');

// Deletes audio files and DB records older than 30 days every day at 4:00 AM
Schedule::command('audio:cleanup')->dailyAt('04:00');
