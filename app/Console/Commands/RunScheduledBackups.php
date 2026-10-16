<?php

namespace App\Console\Commands;

use App\BackupManager;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('backup:scheduled')]
#[Description('Make the daily and weekly backups when they are due, and delete backups older than a week')]
class RunScheduledBackups extends Command
{
    /**
     * Run every minute (routes/console.php). Makes the day's backup once its
     * time has come (Setting::backupSchedule(), in the working hours' time
     * zone) and there isn't one of today's yet — so a minute missed, the
     * server down at the time, is caught up — and the week's on its day the
     * same way. Then prunes whatever's past BackupManager::RETENTION_DAYS.
     */
    public function handle(): int
    {
        $schedule = Setting::backupSchedule();
        $now = CarbonImmutable::now(Setting::timezone());
        $madeToday = BackupManager::all()
            ->filter(fn (array $backup) => $backup['created_at']->setTimezone(Setting::timezone())->isSameDay($now))
            ->pluck('type');

        $due = [
            'daily' => $schedule['daily']['enabled'] && $now->format('H:i') >= $schedule['daily']['time'],
            'weekly' => $schedule['weekly']['enabled']
                && strtolower($now->englishDayOfWeek) === $schedule['weekly']['day']
                && $now->format('H:i') >= $schedule['weekly']['time'],
        ];

        foreach ($due as $type => $isDue) {
            if (! $isDue || $madeToday->contains($type)) {
                continue;
            }

            try {
                $backup = BackupManager::create($type, $schedule['include_files']);
                $this->components->info("Made the {$type} backup: {$backup['name']}.");
            } catch (Throwable $exception) {
                Log::error("The {$type} backup failed.", ['error' => $exception->getMessage()]);
                report($exception);
                $this->components->error("The {$type} backup failed: {$exception->getMessage()}");
            }
        }

        $pruned = BackupManager::prune($now);

        if ($pruned > 0) {
            $this->components->info("Deleted {$pruned} backups older than ".BackupManager::RETENTION_DAYS.' days.');
        }

        return self::SUCCESS;
    }
}
