<?php

use App\Console\Commands\AlertIdleDataEntry;
use App\Console\Commands\AlertIdleGmAssistant;
use App\Console\Commands\RunScheduledBackups;
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

// The same for GM Assistant (Settings → Idle Alert).
Schedule::command(AlertIdleGmAssistant::class)->everyMinute()->withoutOverlapping();

// The daily and weekly backups, each once its time has come (Admin →
// Backups), and pruning those older than a week — see App\BackupManager.
Schedule::command(RunScheduledBackups::class)->everyMinute()->withoutOverlapping();
