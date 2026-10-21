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
     * How it reads on the RFQ's timeline (Rfq::sentAlerts()), and its tone.
     */
    public const TIMELINE_TITLE = 'Line item count mismatch — alert sent';

    public const TIMELINE_TONE = 'danger';

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
     * How it reads: "RFQ1686 — Life Jackets: Visal Fernando added 3 line
     * items, but Sourcing asked for 1 (2 more than requested)." — the part,
     * the subject, who added how many, and how that differs from what
     * Sourcing gave them to add.
     */
    public static function messageFor(string $part, string $subject, string $addedBy, int $added, int $toAdd): string
    {
        $addedItems = trans_choice('1 line item|:count line items', $added, ['count' => number_format($added)]);
        $difference = number_format(abs($added - $toAdd)).($added < $toAdd ? ' fewer' : ' more').' than requested';

        return "{$part} — {$subject}: {$addedBy} added {$addedItems}, but Sourcing asked for ".number_format($toAdd)." ({$difference}).";
    }

    /**
     * What the RFQ's timeline says of it (Rfq::sentAlerts()): messageFor(),
     * from the counts it was sent with — so one sent before its wording
     * changed reads the same — or, without them, the message it was sent
     * with.
     *
     * @param  array<string, mixed>  $data
     */
    public static function timelineDetail(array $data, string $recipients, ?Rfq $rfq = null): string
    {
        if ($rfq === null || ! isset($data['part'], $data['added'], $data['to_add'])) {
            return (string) ($data['message'] ?? '');
        }

        $addedBy = isset($data['added_by']) ? User::query()->whereKey($data['added_by'])->value('name') : null;

        return self::messageFor($rfq->partNumberLabel((int) $data['part']), $rfq->subject, $addedBy ?? 'Data Entry', (int) $data['added'], (int) $data['to_add']);
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
        return [
            'rfq_id' => $this->rfq->id,
            'part' => $this->part,
            'added' => $this->added,
            'to_add' => $this->toAdd,
            'discrepancy' => $this->added - $this->toAdd,
            'added_by' => $this->addedBy->id,
            'message' => self::messageFor($this->rfq->partNumberLabel($this->part), $this->rfq->subject, $this->addedBy->name, $this->added, $this->toAdd),
            'url' => route('admin.rfqs.show', $this->rfq),
            'icon' => 'bi-exclamation-diamond',
            'note' => 'line item count mismatch',
        ];
    }
}
