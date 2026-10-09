<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Someone in Data Entry hasn't started anything for a while in working time,
 * with parts waiting on them — sent to Senior Operations (and Admin) by
 * App\Console\Commands\AlertIdleDataEntry, once per stretch they're free.
 * Kept in the database: it's what the top bar's bell lists
 * (layouts/_notification_bell.blade.php), and it opens their conversation in
 * Messages, to nudge them.
 */
class DataEntryIdle extends Notification
{
    use Queueable;

    /**
     * Who's alerted — and so who has the bell.
     *
     * @var array<int, string>
     */
    public const RECIPIENT_ROLES = ['Senior Operations', 'Admin'];

    public function __construct(
        public User $dataEntry,
        public int $idleMinutes,
        public int $waitingParts,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{data_entry_user_id: int, data_entry_name: string, idle_minutes: int, waiting_parts: int, message: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'data_entry_user_id' => $this->dataEntry->id,
            'data_entry_name' => $this->dataEntry->name,
            'idle_minutes' => $this->idleMinutes,
            'waiting_parts' => $this->waitingParts,
            'message' => $this->message(),
        ];
    }

    /**
     * How it reads: "Morgan (Data Entry) hasn't started anything for 10m —
     * 3 parts are waiting."
     */
    public function message(): string
    {
        $waiting = $this->waitingParts === 1 ? '1 part is waiting' : "{$this->waitingParts} parts are waiting";

        return "{$this->dataEntry->name} (Data Entry) hasn't started anything for "
            .Setting::hoursLabel($this->idleMinutes)." — {$waiting}.";
    }
}
