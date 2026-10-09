<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

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
            'data_entry_started_at' => 'datetime',
            'finalized_at' => 'datetime',
            'data_entry_returned_at' => 'datetime',
            'senior_ops_reviewed_at' => 'datetime',
            'head_of_bd_approved_at' => 'datetime',
            'gm_assistant_completed_at' => 'datetime',
            'gm_approved_at' => 'datetime',
            'bd_closed_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
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
     * The Data Entry (or Admin) user who started on this part.
     */
    public function dataEntryStartedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_entry_started_by');
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
