<?php

namespace App\Notifications;

use App\Models\Rfq;
use App\Models\RfqAssignment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;

/**
 * Data Entry sent a part to finalize with a different number of line items
 * added than Sourcing gave them to add (RfqAssignment::lineItemsDiscrepancy()).
 * Sent to Senior Operations and the General Manager (RECIPIENT_ROLES) from
 * RfqController::completeDataEntry(). Kept in the database: it's in their
 * bell, and opens the RFQ.
 */
class LineItemsDiscrepancy extends Notification
{
    use Queueable;

    /**
     * Who's told of a discrepancy.
     */
    public const RECIPIENT_ROLES = ['Senior Operations', 'General Manager'];

    public function __construct(
        public Rfq $rfq,
        public int $part,
        public int $added,
        public int $toAdd,
        public User $addedBy,
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
     * @return array{rfq_id: int, part: int, added: int, to_add: int, discrepancy: int, added_by: int, message: string, url: string, icon: string, note: string}
     */
    public function toArray(object $notifiable): array
    {
        $added = trans_choice('1 line item|:count line items', $this->added, ['count' => number_format($this->added)]);

        return [
            'rfq_id' => $this->rfq->id,
            'part' => $this->part,
            'added' => $this->added,
            'to_add' => $this->toAdd,
            'discrepancy' => $this->added - $this->toAdd,
            'added_by' => $this->addedBy->id,
            'message' => "{$this->rfq->partNumberLabel($this->part)}: {$this->addedBy->name} added {$added}, ".RfqAssignment::lineItemsDiscrepancyText($this->added, $this->toAdd)." — {$this->rfq->subject}",
            'url' => route('admin.rfqs.show', $this->rfq),
            'icon' => 'bi-exclamation-diamond',
            'note' => 'line items differ',
        ];
    }
}
