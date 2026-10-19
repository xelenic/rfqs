<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Someone in GM Assistant hasn't started anything for a while in working
 * time, with parts waiting on them — as DataEntryIdle is for Data Entry, and
 * to the same people (RECIPIENT_ROLES), by App\Console\Commands\AlertIdleGmAssistant,
 * once per stretch they're free. Kept in the database: it's in their bell,
 * and opens the person's conversation in Messages, to nudge them.
 */
class GmAssistantIdle extends Notification
{
    use Queueable;

    /**
     * Who's alerted — as for Data Entry's.
     *
     * @var array<int, string>
     */
    public const RECIPIENT_ROLES = DataEntryIdle::RECIPIENT_ROLES;

    public function __construct(
        public User $gmAssistant,
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
     * @return array{gm_assistant_user_id: int, gm_assistant_name: string, idle_minutes: int, waiting_parts: int, message: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'gm_assistant_user_id' => $this->gmAssistant->id,
            'gm_assistant_name' => $this->gmAssistant->name,
            'idle_minutes' => $this->idleMinutes,
            'waiting_parts' => $this->waitingParts,
            'message' => $this->message(),
            'url' => route('admin.messages.show', $this->gmAssistant),
        ];
    }

    /**
     * How it reads: "Casey (GM Assistant) hasn't started anything for 10m —
     * 3 parts are waiting."
     */
    public function message(): string
    {
        $waiting = $this->waitingParts === 1 ? '1 part is waiting' : "{$this->waitingParts} parts are waiting";

        return "{$this->gmAssistant->name} (GM Assistant) hasn't started anything for "
            .Setting::hoursLabel($this->idleMinutes)." — {$waiting}.";
    }
}
