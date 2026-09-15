<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Rfq extends Model
{
    use HasFactory;

    /**
     * The selectable priority levels, in ascending order of urgency.
     *
     * @var array<int, string>
     */
    public const PRIORITIES = ['Low', 'Medium', 'High', 'Urgent'];

    /**
     * The selectable statuses. An RFQ starts Pending and is marked
     * Completed once it's been fulfilled.
     *
     * @var array<int, string>
     */
    public const STATUSES = ['Pending', 'Completed'];

    /**
     * Prefix, zero-padding width, and starting sequence for auto-generated
     * RFQ numbers, e.g. "RFQ1001", "RFQ1002". See nextRfqNumber().
     */
    public const RFQ_NUMBER_PREFIX = 'RFQ';

    public const RFQ_NUMBER_DIGITS = 4;

    public const RFQ_NUMBER_START = 1000;

    /**
     * The post-Data-Entry pipeline position, stored in the `stage` column —
     * Senior Operations' second review through Business Development's
     * final close. Null (and absent from this list) while an RFQ is still
     * in the earlier, implicit pipeline — Sourcing/Data Entry — which
     * already encodes its own position via operations_assigned_at, the
     * assignees pivot, and sourcing_/data_entry_completed_at. See
     * stageLabel() and completeDataEntryPartFor().
     *
     * @var array<int, string>
     */
    public const STAGES = [
        'senior_ops_review', 'head_of_bd_review', 'gm_assistant',
        'gm_review', 'bd_closing', 'closed',
    ];

    /**
     * Stages Head of Business Development can reject an RFQ back to. See
     * rejectToStage().
     *
     * @var array<int, string>
     */
    public const REJECT_TARGET_STAGES = ['sourcing', 'data_entry', 'senior_ops_review'];

    /**
     * activityTimeline() entry types belonging to the post-Data-Entry
     * approval chain (category through BD's close) — used to single out
     * these entries from the earlier Sourcing/Data-Entry ones and from
     * comments, e.g. so Business Development can see this chain in the
     * timeline while everything else there stays restricted for them. See
     * show.blade.php's $restrictAssignment handling.
     *
     * @var array<int, string>
     */
    public const APPROVAL_CHAIN_TIMELINE_TYPES = [
        'category_set', 'senior_ops_reviewed', 'head_of_bd_approved',
        'head_of_bd_rejected', 'gm_assistant_completed', 'gm_approved', 'bd_closed',
    ];

    protected $fillable = [
        'created_by',
        'operations_assigned_by',
        'operations_assigned_at',
        'sourcing_completed_by',
        'sourcing_completed_at',
        'data_entry_completed_by',
        'data_entry_completed_at',
        'category',
        'category_set_by',
        'category_set_at',
        'stage',
        'senior_ops_reviewed_by',
        'senior_ops_reviewed_at',
        'head_of_bd_approved_by',
        'head_of_bd_approved_at',
        'head_of_bd_rejected_by',
        'head_of_bd_rejected_at',
        'head_of_bd_reject_reason',
        'head_of_bd_reject_target_stage',
        'client_details',
        'payment_terms',
        'gm_assistant_completed_by',
        'gm_assistant_completed_at',
        'gm_approved_by',
        'gm_approved_at',
        'bd_closed_by',
        'bd_closed_at',
        'wc_number',
        'rfq_number',
        'priority_level',
        'status',
        'subject',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operations_assigned_at' => 'datetime',
            'sourcing_completed_at' => 'datetime',
            'data_entry_completed_at' => 'datetime',
            'category_set_at' => 'datetime',
            'senior_ops_reviewed_at' => 'datetime',
            'head_of_bd_approved_at' => 'datetime',
            'head_of_bd_rejected_at' => 'datetime',
            'gm_assistant_completed_at' => 'datetime',
            'gm_approved_at' => 'datetime',
            'bd_closed_at' => 'datetime',
        ];
    }

    /**
     * The human label for a stage value — used in the reject-target picker,
     * the activity timeline, and status messages. Covers both the stored
     * `stage` values and the two implicit pre-Data-Entry "stages" Head of
     * BD can reject back to.
     */
    public static function stageLabel(?string $stage): string
    {
        return match ($stage) {
            'sourcing' => 'Sourcing',
            'data_entry' => 'Data Entry',
            'senior_ops_review' => 'Senior Operations (2nd review)',
            'head_of_bd_review' => 'Head of Business Development',
            'gm_assistant' => 'GM Assistant',
            'gm_review' => 'General Manager',
            'bd_closing' => 'Business Development (closing)',
            'closed' => 'Closed',
            default => $stage ?? 'Unknown',
        };
    }

    /**
     * The badge class to pair with this RFQ's priority level.
     */
    public function priorityBadgeClass(): string
    {
        return match ($this->priority_level) {
            'Urgent' => 'badge-soft-danger',
            'High' => 'badge-soft-warning',
            'Low' => 'badge-soft-secondary',
            default => 'badge-soft-primary',
        };
    }

    /**
     * The badge class to pair with this RFQ's status.
     */
    public function statusBadgeClass(): string
    {
        return $this->status === 'Completed' ? 'badge-soft-success' : 'badge-soft-warning';
    }

    /**
     * This RFQ's status as it should display — "Completed" reads as
     * "Closed" now that Business Development's final close is the true end
     * of the lifecycle. The underlying status value, Rfq::STATUSES, and
     * every ?status=Completed filter/query are unchanged; only this
     * display text differs. See closeOut().
     */
    public function statusLabel(): string
    {
        return $this->status === 'Completed' ? 'Closed' : $this->status;
    }

    /**
     * Users (expected to hold the Sourcing role) assigned to work this RFQ.
     * Assignment is optional — an RFQ can have none, one, or several.
     * Ordered by assignment order (pivot id) — that order drives the
     * per-assignee split RFQ numbers, see sourcingSplitNumbers().
     *
     * Each assignee completes their own split independently — pivot
     * completed_at — rather than one click completing the whole RFQ for
     * everyone. See completeSourcingPartFor() / allSourcingPartsCompleted().
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(RfqAssignment::class)
            ->withTimestamps()
            ->withPivot(['completed_at', 'returned_at', 'return_reason', 'returned_by', 'data_entry_completed_at', 'data_entry_completed_by'])
            ->orderBy('rfq_user.id');
    }

    /**
     * The user who created this RFQ. Set automatically from the
     * authenticated user on creation — never user-editable. May be null
     * for RFQs whose creator's account was later deleted.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The Operations-team member who routed this RFQ onward — a single
     * assignment, set via the "Assign Operations" action. Comes before
     * Sourcing assignment in the RFQ's lifecycle.
     */
    public function operationsAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operations_assigned_by');
    }

    /**
     * Top-level discussion thread on this RFQ, oldest first. Replies live
     * under each comment's replies() relation — see RfqComment.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(RfqComment::class)->whereNull('parent_id')->oldest();
    }

    /**
     * The Sourcing-team member whose Mark Complete click was the last one
     * needed — i.e. the person who completed their own split after every
     * other assignee had already completed theirs, actually handing the
     * RFQ off to Data Entry. Not "the only person who worked it": with a
     * multi-way split, everyone still had to complete their own part first.
     */
    public function sourcingCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sourcing_completed_by');
    }

    /**
     * The Data Entry member whose completion was the last one needed —
     * i.e. the person who completed their processing of the last
     * outstanding assignee's split, formally closing the RFQ out. See
     * completeDataEntryPartFor().
     */
    public function dataEntryCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'data_entry_completed_by');
    }

    /**
     * The Senior Operations member who set this RFQ's category. See
     * categorize().
     */
    public function categorySetBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'category_set_by');
    }

    /**
     * The Senior Operations member who gave the second-stage review, after
     * Data Entry. See completeSeniorOpsReview().
     */
    public function seniorOpsReviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'senior_ops_reviewed_by');
    }

    /**
     * The Head of Business Development member who approved this RFQ
     * onward to GM Assistant. See approveByHeadOfBd().
     */
    public function headOfBdApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_of_bd_approved_by');
    }

    /**
     * The Head of Business Development member who last rejected this RFQ
     * back to an earlier stage. See rejectToStage().
     */
    public function headOfBdRejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_of_bd_rejected_by');
    }

    /**
     * The GM Assistant who recorded this RFQ's client details and payment
     * terms. See recordGmAssistantDetails().
     */
    public function gmAssistantCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_assistant_completed_by');
    }

    /**
     * The General Manager who gave this RFQ its final approval. See
     * approveByGm().
     */
    public function gmApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gm_approved_by');
    }

    /**
     * The Business Development member who formally closed this RFQ out.
     * See closeOut().
     */
    public function bdClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bd_closed_by');
    }

    /**
     * Whether every Sourcing assignee has completed their own part and the
     * RFQ has actually handed off to Data Entry.
     */
    public function isWithDataEntry(): bool
    {
        return $this->sourcing_completed_at !== null;
    }

    /**
     * Whether Data Entry has formally closed this RFQ out.
     */
    public function isDataEntryCompleted(): bool
    {
        return $this->status === 'Completed';
    }

    /**
     * Whether the given user has completed their own split of this RFQ's
     * Sourcing work. False for anyone not actually assigned.
     */
    public function hasCompletedSourcingPart(User $user): bool
    {
        return $this->assignees->firstWhere('id', $user->id)?->pivot->completed_at !== null;
    }

    /**
     * Whether Data Entry has sent the given user's split back for rework
     * and they haven't completed it again yet. False for anyone not
     * actually assigned.
     */
    public function hasReturnedSourcingPart(User $user): bool
    {
        $pivot = $this->assignees->firstWhere('id', $user->id)?->pivot;

        return $pivot && $pivot->returned_at !== null && $pivot->completed_at === null;
    }

    /**
     * Whether Data Entry has finished processing the given Sourcing
     * assignee's split — independent of every other assignee's split on
     * the same RFQ. False for anyone not actually assigned.
     */
    public function hasDataEntryCompletedPart(User $user): bool
    {
        return $this->assignees->firstWhere('id', $user->id)?->pivot->data_entry_completed_at !== null;
    }

    /**
     * Whether every Sourcing assignee has completed their own part — the
     * condition that actually hands the RFQ off to Data Entry. False for
     * an RFQ with no Sourcing assignees at all (nothing to complete).
     */
    public function allSourcingPartsCompleted(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->completed_at !== null);
    }

    /**
     * Whether every Sourcing assignee's split has been completed by Data
     * Entry — the condition that formally closes the whole RFQ out. False
     * for an RFQ with no Sourcing assignees at all.
     */
    public function allDataEntryPartsCompleted(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->data_entry_completed_at !== null);
    }

    /**
     * Marks the given user's own split of this RFQ's Sourcing work done.
     * Idempotent — completing an already-completed part is a no-op. Once
     * every assignee has completed their part, the RFQ itself is marked
     * handed off to Data Entry, recording this as the closing completion.
     *
     * Caller is responsible for verifying $user is actually assigned.
     */
    public function completeSourcingPartFor(User $user): void
    {
        if ($this->hasCompletedSourcingPart($user)) {
            return;
        }

        $this->assignees()->updateExistingPivot($user->id, [
            'completed_at' => now(),
            'returned_at' => null,
            'return_reason' => null,
            'returned_by' => null,
        ]);
        $this->load('assignees');

        if ($this->allSourcingPartsCompleted() && ! $this->isWithDataEntry()) {
            $this->update([
                'sourcing_completed_by' => $user->id,
                'sourcing_completed_at' => now(),
            ]);
        }
    }

    /**
     * $returnedBy sends the given Sourcing assignee's split back for
     * rework — clears their completed_at (so Mark Complete is available
     * to them again) and records why and by whom, for the activity
     * timeline. Also clears that assignee's own Data Entry completion, if
     * any — sending a split back undoes Data Entry having processed it.
     * If the RFQ had already fully handed off to Data Entry, or had
     * already been formally closed out, both are undone too, since it's
     * no longer true that every assignee is done. Only this one
     * assignee's split is affected — every other assignee's own
     * completion (Sourcing- or Data-Entry-side) is untouched.
     *
     * The reason is also posted as a regular comment from $returnedBy, so
     * it counts toward the comment total and shows with full comment
     * treatment (avatar, delete) alongside the dedicated "Returned to
     * Sourcing" activity-timeline entry — not just that entry's own
     * one-line reason text.
     *
     * Caller is responsible for verifying $user is actually assigned.
     */
    public function returnSourcingPartFor(User $user, string $reason, User $returnedBy): void
    {
        $this->assignees()->updateExistingPivot($user->id, [
            'completed_at' => null,
            'returned_at' => now(),
            'return_reason' => $reason,
            'returned_by' => $returnedBy->id,
            'data_entry_completed_at' => null,
            'data_entry_completed_by' => null,
        ]);
        $this->load('assignees');

        if ($this->isWithDataEntry()) {
            $this->update([
                'sourcing_completed_by' => null,
                'sourcing_completed_at' => null,
            ]);
        }

        // The whole-RFQ "every assignee's split is Data-Entry-complete"
        // marker, if it was set, no longer holds — this assignee's isn't
        // anymore. Checked directly rather than via isDataEntryCompleted()
        // (status === 'Completed'): under the post-Data-Entry approval
        // chain, status stays 'Pending' all the way through, so that check
        // would never fire here even though this field still needs
        // clearing whenever a return happens after the RFQ had reached
        // Senior Operations' review or later. Also drops status back to
        // Pending, covering the rarer case of a return reaching this method
        // after the RFQ had actually been fully closed out.
        if ($this->data_entry_completed_at !== null || $this->isDataEntryCompleted()) {
            $this->update([
                'status' => 'Pending',
                'data_entry_completed_by' => null,
                'data_entry_completed_at' => null,
            ]);
        }

        $this->comments()->create([
            'user_id' => $returnedBy->id,
            'body' => "Sent {$user->name}'s part back to Sourcing: {$reason}",
        ]);
        $this->load(['comments.author', 'comments.replies.author']);
    }

    /**
     * Data Entry finishes processing the given Sourcing assignee's split —
     * independent of every other assignee's split on the same RFQ, so
     * completing one person's part never touches another's. Idempotent —
     * completing an already-completed split is a no-op. Only once every
     * assignee's split has been completed here does the RFQ as a whole
     * move on to Senior Operations' second review — status stays Pending
     * all the way through that approval chain; see closeOut() for what
     * actually closes it out.
     *
     * Caller is responsible for verifying $assignee is actually assigned.
     */
    public function completeDataEntryPartFor(User $assignee, User $completedBy): void
    {
        if ($this->hasDataEntryCompletedPart($assignee)) {
            return;
        }

        $this->assignees()->updateExistingPivot($assignee->id, [
            'data_entry_completed_at' => now(),
            'data_entry_completed_by' => $completedBy->id,
        ]);
        $this->load('assignees');

        if ($this->allDataEntryPartsCompleted()) {
            $this->update([
                'data_entry_completed_by' => $completedBy->id,
                'data_entry_completed_at' => now(),
                'stage' => 'senior_ops_review',
            ]);
        }
    }

    /**
     * Senior Operations categorizes an incoming RFQ. Not idempotent-guarded
     * — re-categorizing is fine — and doesn't move the RFQ's stage on its
     * own; it's informational, usually set around the same time as
     * assigning Sourcing.
     */
    public function categorize(string $category, User $setBy): void
    {
        $this->update([
            'category' => $category,
            'category_set_by' => $setBy->id,
            'category_set_at' => now(),
        ]);
    }

    /**
     * Senior Operations gives the second-stage review, after Data Entry has
     * finished every assignee's split. Idempotent. Escalates the RFQ on to
     * Head of Business Development.
     *
     * Caller is responsible for verifying stage === 'senior_ops_review'.
     */
    public function completeSeniorOpsReview(User $reviewer): void
    {
        if ($this->senior_ops_reviewed_at !== null) {
            return;
        }

        $this->update([
            'senior_ops_reviewed_by' => $reviewer->id,
            'senior_ops_reviewed_at' => now(),
            'stage' => 'head_of_bd_review',
        ]);
    }

    /**
     * Head of Business Development approves — escalates the RFQ on to GM
     * Assistant. Idempotent. Retires any prior rejection record, since an
     * approval supersedes it.
     *
     * Caller is responsible for verifying stage === 'head_of_bd_review'.
     */
    public function approveByHeadOfBd(User $approver): void
    {
        if ($this->head_of_bd_approved_at !== null) {
            return;
        }

        $this->update([
            'head_of_bd_approved_by' => $approver->id,
            'head_of_bd_approved_at' => now(),
            'head_of_bd_rejected_by' => null,
            'head_of_bd_rejected_at' => null,
            'head_of_bd_reject_reason' => null,
            'head_of_bd_reject_target_stage' => null,
            'stage' => 'gm_assistant',
        ]);
    }

    /**
     * Head of Business Development rejects — sends the RFQ back to an
     * earlier stage (Sourcing, Data Entry, or Senior Operations' own
     * review) with a reason, undoing whatever downstream approval had
     * already happened so it has to be earned again:
     *
     * - Sourcing: every assignee's split reopens — reuses
     *   returnSourcingPartFor() per assignee unchanged, exactly as if Data
     *   Entry had sent each of them back individually.
     * - Data Entry: lighter reopen — only each assignee's own Data Entry
     *   completion clears (their Sourcing work stays done), so the RFQ
     *   reappears in Data Entry's existing "By Sourcing" queue untouched.
     * - Senior Operations' review: nothing further to reopen below it.
     *
     * Also posts a comment recording the rejection, same convention as
     * returnSourcingPartFor().
     *
     * Caller is responsible for verifying stage === 'head_of_bd_review' and
     * $targetStage is one of REJECT_TARGET_STAGES.
     */
    public function rejectToStage(string $targetStage, string $reason, User $rejectedBy): void
    {
        $this->update([
            'senior_ops_reviewed_by' => null,
            'senior_ops_reviewed_at' => null,
            'head_of_bd_approved_by' => null,
            'head_of_bd_approved_at' => null,
            'head_of_bd_rejected_by' => $rejectedBy->id,
            'head_of_bd_rejected_at' => now(),
            'head_of_bd_reject_reason' => $reason,
            'head_of_bd_reject_target_stage' => $targetStage,
            'stage' => $targetStage === 'senior_ops_review' ? 'senior_ops_review' : null,
        ]);

        if ($targetStage === 'sourcing') {
            foreach ($this->assignees as $assignee) {
                $this->returnSourcingPartFor($assignee, $reason, $rejectedBy);
            }
        } elseif ($targetStage === 'data_entry') {
            foreach ($this->assignees as $assignee) {
                $this->assignees()->updateExistingPivot($assignee->id, [
                    'data_entry_completed_at' => null,
                    'data_entry_completed_by' => null,
                ]);
            }
            $this->load('assignees');
            $this->update([
                'data_entry_completed_by' => null,
                'data_entry_completed_at' => null,
            ]);
        }

        $this->comments()->create([
            'user_id' => $rejectedBy->id,
            'body' => 'Head of Business Development rejected — returned to '.self::stageLabel($targetStage).": {$reason}",
        ]);
        $this->load(['comments.author', 'comments.replies.author']);
    }

    /**
     * GM Assistant records this RFQ's client details and payment terms and
     * forwards it on to the General Manager. Not idempotent-guarded — the
     * details can be corrected before the General Manager acts on them.
     *
     * Caller is responsible for verifying stage === 'gm_assistant'.
     */
    public function recordGmAssistantDetails(User $completedBy, string $clientDetails, ?string $paymentTerms): void
    {
        $this->update([
            'client_details' => $clientDetails,
            'payment_terms' => $paymentTerms,
            'gm_assistant_completed_by' => $completedBy->id,
            'gm_assistant_completed_at' => now(),
            'stage' => 'gm_review',
        ]);
    }

    /**
     * General Manager gives final approval — the RFQ is now ready for
     * Business Development to close out. Idempotent.
     *
     * Caller is responsible for verifying stage === 'gm_review'.
     */
    public function approveByGm(User $approver): void
    {
        if ($this->gm_approved_at !== null) {
            return;
        }

        $this->update([
            'gm_approved_by' => $approver->id,
            'gm_approved_at' => now(),
            'stage' => 'bd_closing',
        ]);
    }

    /**
     * Business Development formally closes this RFQ out — the true end of
     * the lifecycle. Idempotent. Reuses the existing 'Completed' status
     * value (see statusLabel() for why the display text says "Closed").
     *
     * Caller is responsible for verifying stage === 'bd_closing'.
     */
    public function closeOut(User $closedBy): void
    {
        if ($this->stage === 'closed') {
            return;
        }

        $this->update([
            'bd_closed_by' => $closedBy->id,
            'bd_closed_at' => now(),
            'stage' => 'closed',
            'status' => 'Completed',
        ]);
    }

    /**
     * The full chronological activity log for this RFQ's detail page — every
     * lifecycle event (created, assigned by Operations, assigned to/completed/
     * returned per Sourcing assignee, handed off to Data Entry, each
     * assignee's split completed by Data Entry, RFQ formally closed)
     * interleaved with every comment and reply, oldest first. Purely a read
     * model over existing columns — no separate activity-log table. The
     * view decides what to blur per role; this just supplies the raw facts.
     *
     * Each entry: type, at (Carbon), actor (who performed it / comment
     * author), related (the Sourcing assignee it concerns, for the
     * per-assignee types — same as actor except on sourcing_returned and
     * data_entry_part_completed, where actor is who returned/completed it
     * and related is whose part it was), detail (split number, or the
     * return reason), and comment (the RfqComment itself, only for type
     * comment).
     *
     * @return array<int, array{
     *     type: string,
     *     at: Carbon,
     *     actor: ?User,
     *     related: ?User,
     *     detail: ?string,
     *     comment: ?RfqComment,
     * }>
     */
    public function activityTimeline(): array
    {
        $entries = [
            [
                'type' => 'created',
                'at' => $this->created_at,
                'actor' => $this->creator,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ],
        ];

        if ($this->operationsAssignee && $this->operations_assigned_at) {
            $entries[] = [
                'type' => 'operations_assigned',
                'at' => $this->operations_assigned_at,
                'actor' => $this->operationsAssignee,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        foreach ($this->assignees as $assignee) {
            $entries[] = [
                'type' => 'sourcing_assigned',
                'at' => $assignee->pivot->created_at,
                'actor' => $assignee,
                'related' => $assignee,
                'detail' => $this->sourcingSplitNumberFor($assignee),
                'comment' => null,
            ];

            if ($assignee->pivot->completed_at) {
                $entries[] = [
                    'type' => 'sourcing_completed',
                    'at' => $assignee->pivot->completed_at,
                    'actor' => $assignee,
                    'related' => $assignee,
                    'detail' => $this->sourcingSplitNumberFor($assignee),
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->returned_at) {
                $entries[] = [
                    'type' => 'sourcing_returned',
                    'at' => $assignee->pivot->returned_at,
                    'actor' => $assignee->pivot->returnedBy,
                    'related' => $assignee,
                    'detail' => $assignee->pivot->return_reason,
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->data_entry_completed_at) {
                $entries[] = [
                    'type' => 'data_entry_part_completed',
                    'at' => $assignee->pivot->data_entry_completed_at,
                    'actor' => $assignee->pivot->dataEntryCompletedBy,
                    'related' => $assignee,
                    'detail' => $this->sourcingSplitNumberFor($assignee),
                    'comment' => null,
                ];
            }
        }

        if ($this->sourcing_completed_at) {
            $entries[] = [
                'type' => 'sourcing_handoff',
                'at' => $this->sourcing_completed_at,
                'actor' => $this->sourcingCompletedBy,
                'related' => $this->sourcingCompletedBy,
                'detail' => null,
                'comment' => null,
            ];
        }

        if ($this->data_entry_completed_at) {
            $entries[] = [
                'type' => 'data_entry_all_completed',
                'at' => $this->data_entry_completed_at,
                'actor' => $this->dataEntryCompletedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        if ($this->category_set_at) {
            $entries[] = [
                'type' => 'category_set',
                'at' => $this->category_set_at,
                'actor' => $this->categorySetBy,
                'related' => null,
                'detail' => $this->category,
                'comment' => null,
            ];
        }

        if ($this->senior_ops_reviewed_at) {
            $entries[] = [
                'type' => 'senior_ops_reviewed',
                'at' => $this->senior_ops_reviewed_at,
                'actor' => $this->seniorOpsReviewedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        if ($this->head_of_bd_approved_at) {
            $entries[] = [
                'type' => 'head_of_bd_approved',
                'at' => $this->head_of_bd_approved_at,
                'actor' => $this->headOfBdApprovedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        if ($this->head_of_bd_rejected_at) {
            $entries[] = [
                'type' => 'head_of_bd_rejected',
                'at' => $this->head_of_bd_rejected_at,
                'actor' => $this->headOfBdRejectedBy,
                'related' => null,
                'detail' => 'Returned to '.self::stageLabel($this->head_of_bd_reject_target_stage).': '.$this->head_of_bd_reject_reason,
                'comment' => null,
            ];
        }

        if ($this->gm_assistant_completed_at) {
            $entries[] = [
                'type' => 'gm_assistant_completed',
                'at' => $this->gm_assistant_completed_at,
                'actor' => $this->gmAssistantCompletedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        if ($this->gm_approved_at) {
            $entries[] = [
                'type' => 'gm_approved',
                'at' => $this->gm_approved_at,
                'actor' => $this->gmApprovedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        if ($this->bd_closed_at) {
            $entries[] = [
                'type' => 'bd_closed',
                'at' => $this->bd_closed_at,
                'actor' => $this->bdClosedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
            ];
        }

        foreach ($this->comments as $comment) {
            $entries[] = [
                'type' => 'comment',
                'at' => $comment->created_at,
                'actor' => $comment->author,
                'related' => null,
                'detail' => null,
                'comment' => $comment,
            ];

            foreach ($comment->replies as $reply) {
                $entries[] = [
                    'type' => 'comment',
                    'at' => $reply->created_at,
                    'actor' => $reply->author,
                    'related' => null,
                    'detail' => "Reply to {$comment->author?->name}'s comment",
                    'comment' => $reply,
                ];
            }
        }

        usort($entries, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $entries;
    }

    /**
     * The next auto-generated RFQ number, e.g. "RFQ1001", "RFQ1002" — one
     * past the highest existing sequence among numbers already following
     * this format, never lower than RFQ_NUMBER_START + 1. Older/manually-
     * entered RFQ numbers (including the old "WP####" ones) don't match
     * the pattern and are ignored rather than breaking the sequence.
     */
    public static function nextRfqNumber(): string
    {
        $prefix = self::RFQ_NUMBER_PREFIX;

        $lastSequence = static::query()
            ->where('rfq_number', 'like', "{$prefix}%")
            ->pluck('rfq_number')
            ->map(function (string $rfqNumber) use ($prefix) {
                return preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $rfqNumber, $matches)
                    ? (int) $matches[1]
                    : 0;
            })
            ->max() ?? 0;

        $nextSequence = max($lastSequence, self::RFQ_NUMBER_START) + 1;

        return $prefix.str_pad((string) $nextSequence, self::RFQ_NUMBER_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Split RFQ numbers per Sourcing assignee — e.g. with three assignees
     * on "RFQ1001": "RFQ1001-P1 of P3", "RFQ1001-P2 of P3",
     * "RFQ1001-P3 of P3", in assignment order. With only a single assignee
     * (or none), everyone maps to the plain rfq_number — splitting only
     * kicks in once the work is actually shared across more than one
     * person.
     *
     * @return array<int, string> user id => display RFQ number
     */
    public function sourcingSplitNumbers(): array
    {
        $assignees = $this->assignees;
        $total = $assignees->count();

        if ($total <= 1) {
            return $assignees->mapWithKeys(fn (User $assignee) => [$assignee->id => $this->rfq_number])->all();
        }

        return $assignees->values()->mapWithKeys(function (User $assignee, int $index) use ($total) {
            return [$assignee->id => "{$this->rfq_number}-P".($index + 1)." of P{$total}"];
        })->all();
    }

    /**
     * The RFQ number as it should display for one specific Sourcing
     * assignee. Falls back to the plain rfq_number for anyone not
     * actually assigned (e.g. other roles viewing the record as a whole).
     */
    public function sourcingSplitNumberFor(User $user): string
    {
        return $this->sourcingSplitNumbers()[$user->id] ?? $this->rfq_number;
    }
}
