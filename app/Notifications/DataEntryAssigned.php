<?php

namespace App\Notifications;

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A Sourcing member gave a part to this person in Data Entry by name, as they
 * assigned it to Data Entry (Rfq::completeSourcingPart()). Kept in the
 * database: it's in their bell, and opens their Ready for Data Entry list.
 */
class DataEntryAssigned extends Notification
{
    use Queueable;

    /**
     * How it reads on the RFQ's timeline (Rfq::sentAlerts()), and its tone.
     */
    public const TIMELINE_TITLE = 'Assigned in Data Entry — they were told';

    public const TIMELINE_TONE = 'secondary';

    /**
     * What the RFQ's timeline says of it: its message, which reads "to you"
     * in the bell, with whom it went to instead.
     *
     * @param  array<string, mixed>  $data
     */
    public static function timelineDetail(array $data, string $recipients): string
    {
        return str_replace(' to you', ' to '.$recipients, (string) ($data['message'] ?? ''));
    }

    public function __construct(
        public Rfq $rfq,
        public int $part,
        public User $assignedBy,
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
     * @return array{rfq_id: int, part: int, assigned_by: int, message: string, url: string, icon: string, note: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'rfq_id' => $this->rfq->id,
            'part' => $this->part,
            'assigned_by' => $this->assignedBy->id,
            'message' => "{$this->assignedBy->name} assigned {$this->rfq->partNumberLabel($this->part)} to you — {$this->rfq->subject}",
            'url' => route('admin.rfqs.index', ['status' => 'Pending']),
            'icon' => 'bi-person-check',
            'note' => 'ready for you',
        ];
    }
}
