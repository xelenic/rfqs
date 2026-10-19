<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\GmAssistantIdle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('rfq:alert-idle-gm-assistant')]
#[Description('Alert Senior Operations when someone in GM Assistant hasn\'t started anything for a while, with parts waiting')]
class AlertIdleGmAssistant extends Command
{
    /**
     * Run every minute (routes/console.php), as AlertIdleDataEntry is for Data
     * Entry. In their working time, each GM Assistant person who's been idle
     * — nothing running, with parts waiting — for as many working minutes as
     * Setting::gmAssistantIdleAlert() allows (User::gmAssistantIdleness())
     * gets Senior Operations and Admin alerted (GmAssistantIdle), once for
     * each stretch they're free. Not in the time they're off on a half day.
     * Their Review page counts down to it, and then says it's been sent.
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now();

        if (! Setting::gmAssistantIdleAlert()['enabled']) {
            return self::SUCCESS;
        }

        $recipients = User::query()->holdingAnyRole(GmAssistantIdle::RECIPIENT_ROLES)->get();
        $gmAssistants = User::holdingRole('GM Assistant')
            ->whereDoesntHave('roles', fn ($roles) => $roles->where('name', 'Admin'))
            ->get();

        foreach ($gmAssistants as $person) {
            if (! Setting::isWorkingTime($now, $person->id)) {
                continue;
            }

            $idle = $person->gmAssistantIdleness($now);

            if ($idle === null || $idle['alerted_at'] !== null || $idle['idle_seconds'] < $idle['alert_after']
                || ($idle['time_off'] !== null && Setting::isInTimeOff($idle['time_off'], $now))) {
                continue;
            }

            Notification::send(
                $recipients->reject(fn (User $recipient) => $recipient->is($person)),
                new GmAssistantIdle($person, intdiv($idle['idle_seconds'], 60), $idle['waiting']),
            );

            $this->components->info("Alerted about {$person->name}: idle ".Setting::hoursLabel(intdiv($idle['idle_seconds'], 60)).'.');
        }

        return self::SUCCESS;
    }
}
