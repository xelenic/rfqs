<?php

use App\Console\Commands\AlertIdleDataEntry;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Senior Operations' alert that someone in Data Entry hasn't started anything
// for a while (Settings → Idle Alert). Needs the scheduler running:
// `php artisan schedule:work` locally, a cron entry for `schedule:run` live.
Schedule::command(AlertIdleDataEntry::class)->everyMinute()->withoutOverlapping();
