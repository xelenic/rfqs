<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Notifications\DataEntryIdle;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

#[Signature('rfq:alert-idle-data-entry')]
#[Description('Alert Senior Operations when someone in Data Entry hasn\'t started anything for a while, with parts waiting')]
class AlertIdleDataEntry extends Command
{
    /**
     * Run every minute (routes/console.php). In working time, each Data
     * Entry person who's been idle — nothing running, with parts waiting —
     * for as many working minutes as Setting::dataEntryIdleAlert() allows
     * (User::dataEntryIdleness()) gets Senior Operations and Admin alerted
     * (DataEntryIdle), once for each stretch they're free. Not in the half of
     * a half day they're off. Their Ready for Data Entry page counts down to
     * it, and then says it's been sent.
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now();

        if (! Setting::dataEntryIdleAlert()['enabled'] || ! Setting::isWorkingTime($now)) {
            return self::SUCCESS;
        }

        $recipients = User::query()->whereHas('roles', fn ($roles) => $roles->whereIn('name', DataEntryIdle::RECIPIENT_ROLES))->get();
        $dataEntry = User::holdingRole('Data Entry')
            ->whereDoesntHave('roles', fn ($roles) => $roles->where('name', 'Admin'))
            ->get();

        foreach ($dataEntry as $person) {
            $idle = $person->dataEntryIdleness($now);

            if ($idle === null || $idle['alerted_at'] !== null || $idle['idle_seconds'] < $idle['alert_after']
                || ($idle['half_off'] !== null && Setting::isInHalfDayOff($idle['half_off'], $now))) {
                continue;
            }

            Notification::send(
                $recipients->reject(fn (User $recipient) => $recipient->is($person)),
                new DataEntryIdle($person, intdiv($idle['idle_seconds'], 60), $idle['waiting']),
            );

            $this->components->info("Alerted about {$person->name}: idle ".Setting::hoursLabel(intdiv($idle['idle_seconds'], 60)).'.');
        }

        return self::SUCCESS;
    }
}
