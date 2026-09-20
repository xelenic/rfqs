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
 * it, and how far it has come since — approved by Senior Operations, then by
 * the Head of Business Development, then completed by GM Assistant, then
 * approved by the General Manager, then closed by Business Development. See
 * Rfq::assignees() / completeSourcingPart() / returnSourcingPart() /
 * completeDataEntryPart() / approveSeniorOpsPart() / approveHeadOfBdPart() /
 * recordGmAssistantPart() / approveGmPart() / closePart().
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
            'senior_ops_reviewed_at' => 'datetime',
            'head_of_bd_approved_at' => 'datetime',
            'gm_assistant_completed_at' => 'datetime',
            'gm_approved_at' => 'datetime',
            'bd_closed_at' => 'datetime',
        ];
    }

    /**
     * Where this part stands, for showing next to whoever holds it:
     * 'data_entry_done' (Data Entry has processed it), 'with_data_entry'
     * (Sourcing finished it, Data Entry hasn't yet), 'returned' (sent back
     * to Sourcing for rework) or 'in_progress'.
     */
    public function progressState(): string
    {
        return match (true) {
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
            'data_entry_done' => $query->whereNotNull('rfq_user.data_entry_completed_at'),
            'with_data_entry' => $query->whereNotNull('rfq_user.completed_at')->whereNull('rfq_user.data_entry_completed_at'),
            'returned' => $query->whereNotNull('rfq_user.returned_at')->whereNull('rfq_user.completed_at')->whereNull('rfq_user.data_entry_completed_at'),
            default => $query->whereNull('rfq_user.completed_at')->whereNull('rfq_user.returned_at')->whereNull('rfq_user.data_entry_completed_at'),
        };
    }

    /**
     * The badge class to pair with progressState().
     */
    public function progressBadgeClass(): string
    {
        return match ($this->progressState()) {
            'data_entry_done' => 'badge-soft-success',
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
     * Whether this part has been through both Sourcing and Data Entry and is
     * waiting on Senior Operations' review.
     */
    public function isAwaitingSeniorOpsReview(): bool
    {
        return $this->completed_at !== null
            && $this->data_entry_completed_at !== null
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
     * Whether GM Assistant has added their details for this part.
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
}
