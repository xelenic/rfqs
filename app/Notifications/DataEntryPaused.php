<?php

namespace App\Notifications;

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Data Entry paused a part they'd started (Rfq::pauseDataEntryPart()), saying
 * why and, if any, the document it's waiting on. Sent to Senior Operations
 * and the General Manager (RECIPIENT_ROLES) from
 * RfqController::pauseDataEntry(). Kept in the database: it's in their bell,
 * and opens the RFQ.
 */
class DataEntryPaused extends Notification
{
    use Queueable;

    /**
     * Who's told of a pause.
     */
    public const RECIPIENT_ROLES = ['Senior Operations', 'General Manager'];

    public function __construct(
        public Rfq $rfq,
        public int $part,
        public User $pausedBy,
        public string $reason,
        public ?string $reference = null,
    ) {}

    /**
     * The active people holding RECIPIENT_ROLES.
     *
     * @return Collection<int, User>
     */
    public static function recipients(): Collection
    {
        return User::query()->holdingAnyRole(self::RECIPIENT_ROLES)->get();
    }

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
     * @return array{rfq_id: int, part: int, paused_by: int, reason: string, reference: string|null, message: string, url: string, icon: string, note: string}
     */
    public function toArray(object $notifiable): array
    {
        $reference = $this->reference !== null ? " (Ref {$this->reference})" : '';

        return [
            'rfq_id' => $this->rfq->id,
            'part' => $this->part,
            'paused_by' => $this->pausedBy->id,
            'reason' => $this->reason,
            'reference' => $this->reference,
            'message' => "{$this->pausedBy->name} paused {$this->rfq->partNumberLabel($this->part)}: ".Str::limit($this->reason, 120).$reference." — {$this->rfq->subject}",
            'url' => route('admin.rfqs.show', $this->rfq),
            'icon' => 'bi-pause-circle',
            'note' => 'Data Entry paused',
        ];
    }
}
