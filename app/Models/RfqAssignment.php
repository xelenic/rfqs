<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Pivot model for the rfq_user table — one row per Sourcing PART of an RFQ
 * (so a person holding three parts has three rows), tracking which part it
 * is, when it was assigned, when it was completed, whether Data Entry sent
 * it back for rework, whether Data Entry has completed its own processing of
 * it (sending it to finalize), whether its Sourcing member has finalized it,
 * and how far it has come since — approved by Senior Operations, then by
 * the Head of Business Development, then completed by GM Assistant, then
 * approved by the General Manager, then closed by Business Development. See
 * Rfq::assignees() / completeSourcingPart() / returnSourcingPart() /
 * completeDataEntryPart() / finalizePart() / approveSeniorOpsPart() / approveHeadOfBdPart() /
 * recordGmAssistantPart() / approveGmPart() / closePart().
 *
 * A part of a split can also be stopped on its own — put on hold or
 * cancelled (status), see Rfq::changePartStatus(). The isAwaiting…() and
 * whereAwaiting…() checks say where a part stands regardless; a stopped part
 * is out of every queue all the same — see isStopped() / whereActive().
 */
class RfqAssignment extends Pivot
{
    /**
     * The states progressState() can return, with how each reads.
     *
     * @var array<string, string>
     */
    public const PROGRESS_LABELS = [
        'in_progress' => 'In progress',
        'with_data_entry' => 'With Data Entry',
        'returned' => 'Returned',
        'data_entry_done' => 'Data Entry done',
        'finalized' => 'Finalized',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'part_number' => 'integer',
            'completed_at' => 'datetime',
            'returned_at' => 'datetime',
            'data_entry_completed_at' => 'datetime',
            'data_entry_entries' => 'integer',
            'data_entry_assigned_to' => 'integer',
            'line_items_to_add' => 'integer',
            'return_line_items' => 'integer',
            'verified_line_items' => 'integer',
            'submitted_line_items' => 'integer',
            'finalized_line_items' => 'integer',
            'data_entry_started_at' => 'datetime',
            'data_entry_paused_at' => 'datetime',
            'finalized_at' => 'datetime',
            'data_entry_returned_at' => 'datetime',
            'senior_ops_reviewed_at' => 'datetime',
            'head_of_bd_approved_at' => 'datetime',
            'gm_assistant_started_at' => 'datetime',
            'gm_assistant_paused_at' => 'datetime',
            'gm_assistant_completed_at' => 'datetime',
            'gm_approved_at' => 'datetime',
            'bd_closed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'part_rejected_at' => 'datetime',
        ];
    }

    /**
     * Whether a reviewer's Reject & Return last sent this part back to
     * $stage — what puts it on that step's Rejected page while it's waiting
     * there, rather than on the step's own queue or its Returns (a
     * co-worker's send-back clears it: Rfq::returnSourcingPart(),
     * returnToDataEntry()).
     */
    public function isRejectedTo(string $stage): bool
    {
        return $this->part_rejected_to === $stage;
    }

    /**
     * Narrows a query over the rfq_user rows to the parts a reviewer last
     * rejected back to $stage — the SQL twin of isRejectedTo().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function whereRejectedTo(Builder|QueryBuilder $query, string $stage): void
    {
        $query->where('rfq_user.part_rejected_to', $stage);
    }

    /**
     * Narrows a query over the rfq_user rows to the parts not last rejected
     * back to $stage — what stays on the step's own queue, or its Returns.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function whereNotRejectedTo(Builder|QueryBuilder $query, string $stage): void
    {
        $query->where(fn ($parts) => $parts->whereNull('rfq_user.part_rejected_to')->orWhere('rfq_user.part_rejected_to', '<>', $stage));
    }

    /**
     * Who last rejected this part back (part_rejected_by).
     */
    public function partRejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'part_rejected_by');
    }

    /**
     * Whether this part has been put on hold on its own.
     */
    public function isOnHold(): bool
    {
        return $this->status === Rfq::ON_HOLD;
    }

    /**
     * Whether this part has been cancelled on its own — the rest of its RFQ
     * goes ahead without it.
     */
    public function isCancelled(): bool
    {
        return $this->status === Rfq::CANCELLED;
    }

    /**
     * Whether this part has been stopped on its own — on hold or cancelled:
     * out of every queue, its time stopped, until it's set going again.
     */
    public function isStopped(): bool
    {
        return $this->status !== null;
    }

    /**
     * Narrows a query over the rfq_user rows to the parts going ahead — not
     * stopped on their own. The SQL twin of ! isStopped().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereActive(Builder $query): void
    {
        $query->whereNull('rfq_user.status');
    }

    /**
     * Where this part stands, for showing next to whoever holds it:
     * 'finalized' (its Sourcing member has finalized it after Data Entry),
     * 'data_entry_done' (Data Entry has sent it to finalize), 'with_data_entry'
     * (Sourcing finished it, Data Entry hasn't yet), 'returned' (sent back
     * to Sourcing for rework) or 'in_progress'.
     */
    public function progressState(): string
    {
        return match (true) {
            $this->finalized_at !== null => 'finalized',
            $this->data_entry_completed_at !== null => 'data_entry_done',
            $this->completed_at !== null => 'with_data_entry',
            $this->returned_at !== null => 'returned',
            default => 'in_progress',
        };
    }

    /**
     * Where a part its Sourcing member has finalized is now, on its way
     * through the reviews: [label, tone] — Senior Operations' review, the
     * Head of Business Development, GM Assistant, the General Manager, then
     * ready to close, and closed.
     *
     * @return array{0: string, 1: string}
     */
    public function stageAfterFinalize(): array
    {
        return match (true) {
            $this->bd_closed_at !== null => ['Closed', 'success'],
            $this->gm_approved_at !== null => ['Ready to close', 'success'],
            $this->gm_assistant_completed_at !== null => ['With the General Manager', 'primary'],
            $this->head_of_bd_approved_at !== null => ['With GM Assistant', 'primary'],
            $this->senior_ops_reviewed_at !== null => ['With Head of Business Development', 'primary'],
            default => ['In Senior Operations review', 'info'],
        };
    }

    /**
     * The human label for progressState().
     */
    public function progressLabel(): string
    {
        return self::PROGRESS_LABELS[$this->progressState()];
    }

    /**
     * Narrows a query over the rfq_user rows (e.g. inside
     * whereHas('assignees')) to the parts in one progressState() — the SQL
     * twin of it, so the two say the same thing.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function wherePartIs(Builder $query, string $state): void
    {
        match ($state) {
            'finalized' => $query->whereNotNull('rfq_user.finalized_at'),
            'data_entry_done' => $query->whereNotNull('rfq_user.data_entry_completed_at')->whereNull('rfq_user.finalized_at'),
            'with_data_entry' => $query->whereNotNull('rfq_user.completed_at')->whereNull('rfq_user.data_entry_completed_at'),
            'returned' => $query->whereNotNull('rfq_user.returned_at')->whereNull('rfq_user.completed_at')->whereNull('rfq_user.data_entry_completed_at'),
            default => $query->whereNull('rfq_user.completed_at')->whereNull('rfq_user.returned_at')->whereNull('rfq_user.data_entry_completed_at'),
        };
    }

    /**
     * Narrows a query over the rfq_user rows to the parts that aren't sitting
     * with Sourcing for rework — everything but wherePartIs($query,
     * 'returned'). What My Pending RFQs lists: a part Data Entry sent back
     * is on the Returns list instead, until it's completed again.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereNotReturned(Builder $query): void
    {
        $query->where(fn (Builder $parts) => $parts
            ->whereNull('rfq_user.returned_at')
            ->orWhereNotNull('rfq_user.completed_at')
            ->orWhereNotNull('rfq_user.data_entry_completed_at'));
    }

    /**
     * The badge class to pair with progressState().
     */
    public function progressBadgeClass(): string
    {
        return match ($this->progressState()) {
            'finalized' => 'badge-soft-success',
            'data_entry_done' => 'badge-soft-info',
            'with_data_entry' => 'badge-soft-primary',
            'returned' => 'badge-soft-danger',
            default => 'badge-soft-warning',
        };
    }

    /**
     * Where this part has got to now — the furthest step it's through — for
     * following it on once it's out of someone's hands (Data Entry's
     * Completed tab): stopped on its own, closed, waiting on Business
     * Development to close it, or with whoever's next. As [label, badge
     * class].
     *
     * @return array{0: string, 1: string}
     */
    public function whereNow(): array
    {
        return match (true) {
            $this->isCancelled() => ['Cancelled', 'badge-soft-secondary'],
            $this->isOnHold() => ['On hold', 'badge-soft-info'],
            $this->bd_closed_at !== null => ['Closed', 'badge-soft-success'],
            $this->gm_approved_at !== null => ['Ready to close', 'badge-soft-success'],
            $this->gm_assistant_completed_at !== null => ['With General Manager', 'badge-soft-primary'],
            $this->head_of_bd_approved_at !== null => ['With GM Assistant', 'badge-soft-primary'],
            $this->senior_ops_reviewed_at !== null => ['With Head of Business Development', 'badge-soft-primary'],
            $this->finalized_at !== null => ['With Senior Operations (review)', 'badge-soft-primary'],
            $this->data_entry_completed_at !== null => ['With Sourcing to finalize', 'badge-soft-info'],
            default => [$this->progressLabel(), $this->progressBadgeClass()],
        };
    }

    /**
     * Whether Senior Operations has approved this part.
     */
    public function isSeniorOpsApproved(): bool
    {
        return $this->senior_ops_reviewed_at !== null;
    }

    /**
     * Whether Data Entry has sent this part to finalize and it's waiting on
     * its Sourcing member's Finalize.
     */
    public function isAwaitingFinalize(): bool
    {
        return $this->data_entry_completed_at !== null && $this->finalized_at === null;
    }

    /**
     * Narrows a query over the rfq_user rows to the parts waiting on their
     * Sourcing member's Finalize — the SQL twin of isAwaitingFinalize().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereAwaitingFinalize(Builder $query): void
    {
        $query->whereNotNull('rfq_user.data_entry_completed_at')
            ->whereNull('rfq_user.finalized_at');
    }

    /**
     * Whether this part has been through Sourcing, Data Entry and its
     * Sourcing member's Finalize, and is waiting on Senior Operations' review.
     */
    public function isAwaitingSeniorOpsReview(): bool
    {
        return $this->completed_at !== null
            && $this->data_entry_completed_at !== null
            && $this->finalized_at !== null
            && $this->senior_ops_reviewed_at === null;
    }

    /**
     * Narrows a query over the rfq_user rows to the parts waiting on Senior
     * Operations' review — the SQL twin of isAwaitingSeniorOpsReview().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereAwaitingSeniorOpsReview(Builder $query): void
    {
        $query->whereNotNull('rfq_user.completed_at')
            ->whereNotNull('rfq_user.data_entry_completed_at')
            ->whereNotNull('rfq_user.finalized_at')
            ->whereNull('rfq_user.senior_ops_reviewed_at');
    }

    /**
     * Whether the Head of Business Development has approved this part.
     */
    public function isHeadOfBdApproved(): bool
    {
        return $this->head_of_bd_approved_at !== null;
    }

    /**
     * Whether Senior Operations has approved this part and it's waiting on
     * the Head of Business Development.
     */
    public function isAwaitingHeadOfBdReview(): bool
    {
        return $this->senior_ops_reviewed_at !== null && $this->head_of_bd_approved_at === null;
    }

    /**
     * Narrows a query over the rfq_user rows to the parts waiting on the Head
     * of Business Development — the SQL twin of isAwaitingHeadOfBdReview().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereAwaitingHeadOfBdReview(Builder $query): void
    {
        $query->whereNotNull('rfq_user.senior_ops_reviewed_at')
            ->whereNull('rfq_user.head_of_bd_approved_at');
    }

    /**
     * Whether GM Assistant has started on this part — see
     * Rfq::startGmAssistantPart().
     */
    public function hasGmAssistantStarted(): bool
    {
        return $this->gm_assistant_started_at !== null;
    }

    /**
     * Whether GM Assistant paused it after starting it — its time stopped,
     * till they resume it (Rfq::pauseGmAssistantPart()).
     */
    public function isGmAssistantPaused(): bool
    {
        return $this->gm_assistant_paused_at !== null && $this->gm_assistant_completed_at === null;
    }

    /**
     * Whether GM Assistant is on it right now: started, not paused, not yet
     * submitted to the General Manager.
     */
    public function isGmAssistantRunning(): bool
    {
        return $this->hasGmAssistantStarted() && ! $this->isGmAssistantPaused() && $this->gm_assistant_completed_at === null;
    }

    /**
     * The GM Assistant (or Admin) user who started on this part.
     */
    public function gmAssistantStartedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_assistant_started_by');
    }

    /**
     * Whether GM Assistant has submitted this part to the General Manager.
     */
    public function isGmAssistantCompleted(): bool
    {
        return $this->gm_assistant_completed_at !== null;
    }

    /**
     * Whether the Head of Business Development has approved this part and it's
     * waiting on GM Assistant.
     */
    public function isAwaitingGmAssistant(): bool
    {
        return $this->head_of_bd_approved_at !== null && $this->gm_assistant_completed_at === null;
    }

    /**
     * Whether the General Manager has approved this part.
     */
    public function isGmApproved(): bool
    {
        return $this->gm_approved_at !== null;
    }

    /**
     * Whether GM Assistant has completed this part and it's waiting on the
     * General Manager.
     */
    public function isAwaitingGmApproval(): bool
    {
        return $this->gm_assistant_completed_at !== null && $this->gm_approved_at === null;
    }

    /**
     * Narrows a query over the rfq_user rows to the parts waiting on GM
     * Assistant — the SQL twin of isAwaitingGmAssistant().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereAwaitingGmAssistant(Builder $query): void
    {
        $query->whereNotNull('rfq_user.head_of_bd_approved_at')
            ->whereNull('rfq_user.gm_assistant_completed_at');
    }

    /**
     * Narrows a query over the rfq_user rows to the parts waiting on the
     * General Manager — the SQL twin of isAwaitingGmApproval().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereAwaitingGmApproval(Builder $query): void
    {
        $query->whereNotNull('rfq_user.gm_assistant_completed_at')
            ->whereNull('rfq_user.gm_approved_at');
    }

    /**
     * Whether Business Development has closed this part.
     */
    public function isBdClosed(): bool
    {
        return $this->bd_closed_at !== null;
    }

    /**
     * Whether the General Manager has approved this part and it's waiting for
     * Business Development to close it.
     */
    public function isAwaitingBdClosing(): bool
    {
        return $this->gm_approved_at !== null && $this->bd_closed_at === null;
    }

    /**
     * Narrows a query over the rfq_user rows to the parts waiting for
     * Business Development to close — the SQL twin of isAwaitingBdClosing().
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function whereAwaitingBdClosing(Builder $query): void
    {
        $query->whereNotNull('rfq_user.gm_approved_at')
            ->whereNull('rfq_user.bd_closed_at');
    }

    /**
     * The Senior Operations (or Admin) user who last stopped this part, or set
     * it going again.
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * The Business Development (or Admin) user who closed this part.
     */
    public function bdClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bd_closed_by');
    }

    /**
     * The GM Assistant (or Admin) user who completed this part.
     */
    public function gmAssistantCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_assistant_completed_by');
    }

    /**
     * The General Manager (or Admin) user who approved this part.
     */
    public function gmApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_approved_by');
    }

    /**
     * The Head of Business Development (or Admin) user who approved this part.
     */
    public function headOfBdApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_of_bd_approved_by');
    }

    /**
     * The Senior Operations (or Admin) user who approved this part.
     */
    public function seniorOpsReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'senior_ops_reviewed_by');
    }

    /**
     * The Data Entry (or Admin) user who sent this split back for rework.
     */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /**
     * The Data Entry (or Admin) user who completed this split's Data Entry
     * work — see Rfq::completeDataEntryPartFor().
     */
    public function dataEntryCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_entry_completed_by');
    }

    /**
     * Whether Data Entry has started on this part — see
     * Rfq::startDataEntryPart().
     */
    public function hasDataEntryStarted(): bool
    {
        return $this->data_entry_started_at !== null;
    }

    /**
     * Whether Data Entry paused it after starting it — its time stopped, till
     * they resume it (Rfq::pauseDataEntryPart()).
     */
    public function isDataEntryPaused(): bool
    {
        return $this->data_entry_paused_at !== null && $this->data_entry_completed_at === null;
    }

    /**
     * Whether Data Entry is on it right now: started, not paused, not yet
     * sent to finalize.
     */
    public function isDataEntryRunning(): bool
    {
        return $this->hasDataEntryStarted() && ! $this->isDataEntryPaused() && $this->data_entry_completed_at === null;
    }

    /**
     * How far the line items Data Entry added are from the ones Sourcing gave
     * them to add, once Data Entry has sent the part to finalize: added minus
     * to add, so -2 is two short and 3 is three over. Null when they match or
     * either count is missing.
     */
    public function lineItemsDiscrepancy(): ?int
    {
        return $this->data_entry_completed_at === null
            ? null
            : self::lineItemsDiscrepancyBetween($this->data_entry_entries, $this->line_items_to_add);
    }

    /**
     * How many line items Data Entry had added when they sent this part back
     * to Sourcing (rfq_user.return_line_items), as it reads beside the
     * return's reason — "12 line items added" — or null when they didn't say
     * (a reviewer's send-back, or one from before it was asked).
     */
    public function returnLineItemsLabel(): ?string
    {
        return $this->return_line_items === null
            ? null
            : trans_choice('1 line item added|:count line items added', $this->return_line_items, ['count' => number_format($this->return_line_items)]);
    }

    /**
     * The discrepancy as it reads beside the line items added ("2 fewer than
     * the 24 requested"), or null when there's none — see lineItemsDiscrepancy().
     */
    public function lineItemsDiscrepancyLabel(): ?string
    {
        return $this->lineItemsDiscrepancy() === null
            ? null
            : self::lineItemsDiscrepancyText($this->data_entry_entries, $this->line_items_to_add);
    }

    /**
     * The line items as the part's checks see them — Sourcing finalizing it
     * (_finalize_modal), Senior Operations approving it
     * (_senior_ops_approve_modal): what Sourcing gave Data Entry to add, what
     * Data Entry added — once they've sent it to finalize — added minus to
     * add (0 when they match; null while either is missing), and what
     * Sourcing submitted finalizing it (submittedLineItems()).
     *
     * @return array{to_add: ?int, added: ?int, difference: ?int, submitted: ?int}
     */
    public function lineItemsCheck(): array
    {
        $added = $this->data_entry_completed_at !== null ? $this->data_entry_entries : null;

        return [
            'to_add' => $this->line_items_to_add,
            'added' => $added,
            'difference' => $added !== null && $this->line_items_to_add !== null ? $added - $this->line_items_to_add : null,
            'submitted' => $this->submittedLineItems(),
        ];
    }

    /**
     * The line items the Sourcing member submitted on the part as they
     * finalized it (Rfq::finalizePart()) — null until they have, or since it
     * was sent back past their Finalize.
     */
    public function submittedLineItems(): ?int
    {
        return $this->finalized_at !== null ? $this->submitted_line_items : null;
    }

    /**
     * What Sourcing submitted finalizing the part less what Data Entry added
     * to it — what Senior Operations checks before counting the actual line
     * items (_senior_ops_approve_modal): 0 when they match, negative when
     * Sourcing submitted fewer; null while either is missing.
     */
    public function submittedDifference(): ?int
    {
        $added = $this->data_entry_completed_at !== null ? $this->data_entry_entries : null;
        $submitted = $this->submittedLineItems();

        return $added !== null && $submitted !== null ? $submitted - $added : null;
    }

    /**
     * The line items Senior Operations counted on the part as they approved
     * it (Rfq::approveSeniorOpsPart()) — null until they have, or since it
     * was sent back past their review.
     */
    public function verifiedLineItems(): ?int
    {
        return $this->isSeniorOpsApproved() ? $this->verified_line_items : null;
    }

    /**
     * The line items the Head of Business Development finalized on the part
     * as they approved it on to GM Assistant (Rfq::approveHeadOfBdPart()) —
     * null until they have, or since it was sent back past their review.
     */
    public function finalizedLineItems(): ?int
    {
        return $this->isHeadOfBdApproved() ? $this->finalized_line_items : null;
    }

    /**
     * $added minus $toAdd, or null when they match or either is missing.
     */
    public static function lineItemsDiscrepancyBetween(?int $added, ?int $toAdd): ?int
    {
        if ($added === null || $toAdd === null || $added === $toAdd) {
            return null;
        }

        return $added - $toAdd;
    }

    /**
     * "2 fewer than the 24 requested" or "3 more than the 24 requested" —
     * what Data Entry added against what Sourcing asked for — null when they
     * match or either is missing.
     */
    public static function lineItemsDiscrepancyText(?int $added, ?int $toAdd): ?string
    {
        $discrepancy = self::lineItemsDiscrepancyBetween($added, $toAdd);

        if ($discrepancy === null) {
            return null;
        }

        return number_format(abs($discrepancy)).($discrepancy < 0 ? ' fewer' : ' more').' than the '.number_format($toAdd).' requested';
    }

    /**
     * The Data Entry (or Admin) user who started on this part.
     */
    public function dataEntryStartedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_entry_started_by');
    }

    /**
     * Narrows a query over the rfq_user rows to the parts $userId in Data
     * Entry can take: given to them by name, or to anyone in Data Entry
     * (data_entry_assigned_to null). Every part when null.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    public static function whereForDataEntryUser($query, ?int $userId): void
    {
        $query->when($userId !== null, fn ($theirs) => $theirs->where(fn ($forThem) => $forThem
            ->whereNull('rfq_user.data_entry_assigned_to')
            ->orWhere('rfq_user.data_entry_assigned_to', $userId)));
    }

    /**
     * Whom in Data Entry its Sourcing member gave it to — null for anyone
     * there. See Rfq::completeSourcingPart().
     */
    public function dataEntryAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_entry_assigned_to');
    }

    /**
     * Who finalized this part after Data Entry sent it to finalize — its
     * Sourcing member (Admin finalizes as them). See Rfq::finalizePart().
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }
}
