<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
     * The most parts one RFQ can be split into in the Assign Sourcing
     * wizard.
     */
    public const MAX_SPLIT_PARTS = 20;

    /**
     * The roles that each own a step of the RFQ workflow, in workflow
     * order — what Admin's sidebar groups its pages by. Each one's own
     * queue can be opened by Admin with ?role=<slug>, see
     * RfqController::index().
     *
     * @var array<int, string>
     */
    public const WORKFLOW_ROLES = [
        'Business Development', 'Senior Operations', 'Sourcing', 'Data Entry',
        'Head of Business Development', 'GM Assistant', 'General Manager',
    ];

    /**
     * The ?view= values the RFQ list understands, each a second queue for a
     * role alongside its default one — Sourcing's returns, Senior
     * Operations' review, Business Development's ready-to-close.
     *
     * @var array<int, string>
     */
    public const QUEUE_VIEWS = ['returns', 'review', 'closing'];

    /**
     * The post-Data-Entry pipeline position, stored in the `stage` column —
     * Senior Operations' second review through Business Development's
     * final close. Null (and absent from this list) while an RFQ is still
     * in the earlier, implicit pipeline — Sourcing/Data Entry — which
     * already encodes its own position via operations_assigned_at, the
     * assignees pivot, and sourcing_/data_entry_completed_at. See
     * stageLabel() and completeDataEntryPart().
     *
     * @var array<int, string>
     */
    public const STAGES = [
        'senior_ops_review', 'head_of_bd_review', 'gm_assistant',
        'gm_review', 'bd_closing', 'closed',
    ];

    /**
     * The stages an RFQ is under review in — after Data Entry, before it's
     * ready for Business Development to close. Counted as "in review" on
     * Business Development's dashboard.
     *
     * @var array<int, string>
     */
    public const REVIEW_STAGES = [
        'senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review',
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
        'split_count',
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
            'split_count' => 'integer',
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
     * Sourcing assignments on this RFQ — one row per PART, each a User with
     * its own pivot (part_number, completed_at, returned_at, ...), so the
     * same person appears once for every part they hold. Ordered by part
     * number. An RFQ can have none, one, or several, and with a planned
     * split (split_count) some parts can still be empty — see
     * sourcingParts().
     *
     * Every part is completed on its own — pivot completed_at — rather than
     * one click completing the whole RFQ for everyone; only once every part
     * is assigned and completed does the RFQ hand off to Data Entry. See
     * completeSourcingPart() / allSourcingPartsCompleted().
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->using(RfqAssignment::class)
            ->withTimestamps()
            ->withPivot(['part_number', 'completed_at', 'returned_at', 'return_reason', 'returned_by', 'data_entry_completed_at', 'data_entry_completed_by', 'senior_ops_reviewed_at', 'senior_ops_reviewed_by', 'head_of_bd_approved_at', 'head_of_bd_approved_by', 'gm_assistant_completed_at', 'gm_assistant_completed_by', 'gm_approved_at', 'gm_approved_by', 'bd_closed_at', 'bd_closed_by'])
            ->orderBy('rfq_user.part_number')
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
     * Top-level discussion thread on this RFQ, oldest first — and in the
     * order they were posted when several land in the same second, as an
     * action's comments can. Replies live under each comment's replies()
     * relation — see RfqComment.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(RfqComment::class)->whereNull('parent_id')->oldest()->oldest('id');
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
     * completeDataEntryPart().
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
     * Whether every Sourcing part has been completed and the RFQ has actually
     * handed off to Data Entry.
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
     * The assignment (a User, with its pivot) holding the given Sourcing
     * part — null if that part is empty or doesn't exist.
     */
    public function assigneeForPart(int $part): ?User
    {
        return $this->assignees->first(fn (User $assignee) => $assignee->pivot->part_number === $part);
    }

    /**
     * Whether every Sourcing part has been completed — the condition that
     * actually hands the RFQ off to Data Entry. False for an RFQ with no
     * Sourcing assignees at all (nothing to complete), and false while any
     * planned part is still unassigned — everyone assigned so far finishing
     * doesn't mean the work is done.
     */
    public function allSourcingPartsCompleted(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->completed_at !== null);
    }

    /**
     * Whether every Sourcing part has been completed by Data Entry — the
     * condition that formally closes the whole RFQ out. False for an RFQ
     * with no Sourcing assignees at all, and false while any planned part
     * is still unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allDataEntryPartsCompleted(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->data_entry_completed_at !== null);
    }

    /**
     * How many Sourcing parts this RFQ is split into: what Operations
     * planned in the Assign Sourcing wizard (split_count), or — for RFQs
     * assigned before that existed — just however many people are
     * assigned. Always at least 1.
     */
    public function splitTotal(): int
    {
        return $this->split_count ?? max($this->assignees->count(), 1);
    }

    /**
     * Whether the work is shared across more than one Sourcing part —
     * including parts nobody has been assigned to yet.
     */
    public function isSplit(): bool
    {
        return $this->splitTotal() > 1;
    }

    /**
     * Every planned Sourcing part, in order, whether or not anyone holds it
     * yet — e.g. a 5-way split with two parts assigned is five entries,
     * three of them with a null assignee. One person can hold several
     * parts, so the same person can appear on more than one entry (each
     * with that part's own pivot). An RFQ with nobody assigned at all and
     * no split planned is a single, empty part.
     *
     * @return Collection<int, array{part: int, number: string, assignee: ?User}>
     */
    public function sourcingParts(): Collection
    {
        $assigneesByPart = $this->assignees->keyBy(fn (User $assignee) => $assignee->pivot->part_number);

        return collect(range(1, $this->splitTotal()))->map(fn (int $part) => [
            'part' => $part,
            'number' => $this->partNumberLabel($part),
            'assignee' => $assigneesByPart->get($part),
        ]);
    }

    /**
     * The part numbers the given person holds on this RFQ, ascending —
     * empty if they hold none.
     *
     * @return array<int, int>
     */
    public function partNumbersFor(User $user): array
    {
        return $this->assignees
            ->filter(fn (User $assignee) => $assignee->id === $user->id)
            ->map(fn (User $assignee) => $assignee->pivot->part_number)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * RFQs that still have a Sourcing part nobody holds yet — nobody
     * assigned at all, or fewer parts assigned than the planned split.
     * Operations' "Unassigned" queue, mirrored in PHP by
     * hasUnassignedParts(). Every assignment is one part, so counting them
     * against split_count is enough.
     */
    public function scopeNeedingSourcing(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->doesntHave('assignees')
            ->orWhereRaw('rfqs.split_count > (select count(*) from rfq_user where rfq_user.rfq_id = rfqs.id)'));
    }

    /**
     * The opposite: somebody's assigned, and every planned part has
     * someone on it.
     */
    public function scopeFullySourced(Builder $query): void
    {
        $query->has('assignees')->where(fn (Builder $q) => $q
            ->whereNull('rfqs.split_count')
            ->orWhereRaw('rfqs.split_count <= (select count(*) from rfq_user where rfq_user.rfq_id = rfqs.id)'));
    }

    /**
     * What's waiting on Senior Operations' second review: an RFQ every part
     * of which has been through Data Entry (stage senior_ops_review), and a
     * split still in progress with at least one part that has been through
     * Sourcing and Data Entry and not yet been approved — each part is
     * reviewed as it comes through, without waiting for the rest. An RFQ
     * that's moved on (or been closed) has nothing left here.
     */
    public function scopeAwaitingSeniorOpsReview(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where('rfqs.stage', 'senior_ops_review')
            ->orWhere(fn (Builder $inProgress) => $inProgress
                ->whereNull('rfqs.stage')
                ->whereHas('assignees', fn (Builder $parts) => RfqAssignment::whereAwaitingSeniorOpsReview($parts))));
    }

    /**
     * What's waiting on the Head of Business Development: an RFQ every part
     * of which Senior Operations has approved (stage head_of_bd_review), and
     * one still on its way there with at least one part Senior Operations has
     * approved and the Head hasn't yet — each part is reviewed as it comes,
     * without waiting for the rest. An RFQ that's moved on (or been closed)
     * has nothing left here.
     */
    public function scopeAwaitingHeadOfBdReview(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where('rfqs.stage', 'head_of_bd_review')
            ->orWhere(fn (Builder $inProgress) => $inProgress
                ->where(fn (Builder $stage) => $stage->whereNull('rfqs.stage')->orWhere('rfqs.stage', 'senior_ops_review'))
                ->whereHas('assignees', fn (Builder $parts) => RfqAssignment::whereAwaitingHeadOfBdReview($parts))));
    }

    /**
     * What's waiting on GM Assistant: an RFQ every part of which the Head of
     * Business Development has approved (stage gm_assistant), and one still on
     * its way there with at least one part the Head has approved and GM
     * Assistant hasn't yet completed — each part goes to them as it comes,
     * without waiting for the rest. An RFQ that's moved on has nothing left
     * here.
     */
    public function scopeAwaitingGmAssistant(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where('rfqs.stage', 'gm_assistant')
            ->orWhere(fn (Builder $inProgress) => $inProgress
                ->where(fn (Builder $stage) => $stage->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review']))
                ->whereHas('assignees', fn (Builder $parts) => RfqAssignment::whereAwaitingGmAssistant($parts))));
    }

    /**
     * What's waiting on the General Manager's approval: an RFQ every part of
     * which GM Assistant has completed (stage gm_review), and one still on its
     * way there with at least one part GM Assistant has completed and the
     * General Manager hasn't yet approved. An RFQ that's moved on has nothing
     * left here.
     */
    public function scopeAwaitingGmApproval(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where('rfqs.stage', 'gm_review')
            ->orWhere(fn (Builder $inProgress) => $inProgress
                ->where(fn (Builder $stage) => $stage->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant']))
                ->whereHas('assignees', fn (Builder $parts) => RfqAssignment::whereAwaitingGmApproval($parts))));
    }

    /**
     * What's ready for Business Development to close: an RFQ every part of
     * which the General Manager has approved (stage bd_closing), and one still
     * on its way there with at least one part the General Manager has approved
     * and Business Development hasn't yet closed — each part is ready as it
     * comes, without waiting for the rest. A closed RFQ has nothing left here.
     */
    public function scopeAwaitingBdClosing(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where('rfqs.stage', 'bd_closing')
            ->orWhere(fn (Builder $inProgress) => $inProgress
                ->where(fn (Builder $stage) => $stage->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review']))
                ->whereHas('assignees', fn (Builder $parts) => RfqAssignment::whereAwaitingBdClosing($parts))));
    }

    /**
     * What the Closed RFQs list is made of: an RFQ that's been closed, and one
     * still open with at least one part Business Development has closed — a
     * closed part shows there as soon as it's closed, with the rest of its RFQ
     * still in progress.
     */
    public function scopeClosedOrWithClosedParts(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('rfqs.status', 'Completed')
            ->orWhereHas('assignees', fn (Builder $parts) => $parts->whereNotNull('rfq_user.bd_closed_at')));
    }

    /**
     * Whether any planned Sourcing part is still waiting for someone —
     * what keeps "Assign Sourcing" available to Operations after a partial
     * assignment.
     */
    public function hasUnassignedParts(): bool
    {
        return $this->sourcingParts()->contains(fn (array $part) => $part['assignee'] === null);
    }

    public function allPartsAssigned(): bool
    {
        return ! $this->hasUnassignedParts();
    }

    /**
     * Records how many parts Operations planned this RFQ into. 1 means
     * "not split" — still one part, so the same assign-a-person step
     * applies.
     */
    public function planSplit(int $parts): void
    {
        $this->update(['split_count' => max($parts, 1)]);
    }

    /**
     * Puts Sourcing users onto still-empty parts — part number => user id.
     * Blank entries leave that part empty for later; parts someone already
     * holds, and part numbers outside the planned split, are skipped, so
     * this never reassigns or overwrites. The same person can be given
     * several parts — each becomes its own assignment, worked and completed
     * on its own. Caller is responsible for verifying every user actually
     * holds the Sourcing role.
     *
     * @param  array<int|string, int|string|null>  $userIdsByPart
     */
    public function assignSourcingParts(array $userIdsByPart): void
    {
        $heldParts = $this->assignees->pluck('pivot.part_number');
        $total = $this->splitTotal();

        foreach ($userIdsByPart as $part => $userId) {
            $part = (int) $part;

            if (! $userId || $part < 1 || $part > $total || $heldParts->contains($part)) {
                continue;
            }

            $this->assignees()->attach($userId, ['part_number' => $part]);
            $heldParts->push($part);
        }

        $this->load('assignees');
    }

    /**
     * Marks one Sourcing part done. Idempotent — completing an already-
     * completed part is a no-op. Once every part is assigned and completed,
     * the RFQ itself is marked handed off to Data Entry, recording this
     * part's assignee as the closing completion. A $comment, if given, is
     * posted to the RFQ's thread as the assignee's.
     *
     * Caller is responsible for verifying the user completing it is the
     * part's assignee.
     */
    public function completeSourcingPart(int $part, ?string $comment = null): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee || $assignee->pivot->completed_at !== null) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'completed_at' => now(),
            'returned_at' => null,
            'return_reason' => null,
            'returned_by' => null,
        ]);
        $this->load('assignees');

        // The completion comment goes into the RFQ's thread as the
        // assignee's own, so Data Entry reads it with everything else —
        // marked as a completion, and naming the part on a split, since a
        // thread covers the whole RFQ.
        if (filled($comment)) {
            $this->postActionComment($assignee, 'sourcing_completed', $comment, $this->partContext($part));
        }

        if ($this->allSourcingPartsCompleted() && ! $this->isWithDataEntry()) {
            $this->update([
                'sourcing_completed_by' => $assignee->id,
                'sourcing_completed_at' => now(),
            ]);
        }
    }

    /**
     * $returnedBy sends one Sourcing part back for rework — clears its
     * completed_at (so Mark Complete is available to its assignee again)
     * and records why and by whom, for the activity timeline. Also clears
     * that part's own Data Entry completion, if any — sending it back
     * undoes Data Entry having processed it (and anything done with it since —
     * approvals by Senior Operations, the Head of Business Development and the
     * General Manager, GM Assistant's details, its closing). If the RFQ had already fully
     * handed off to Data Entry, or had already been formally closed out,
     * both are undone too, since it's no longer true that every part is
     * done. Only this one part is affected — every other part's own
     * completion (Sourcing- or Data-Entry-side) is untouched, including
     * other parts held by the same person.
     *
     * The reason is also posted as a regular comment from $returnedBy, so
     * it counts toward the comment total and shows with full comment
     * treatment (avatar, delete) alongside the dedicated "Returned to
     * Sourcing" activity-timeline entry — not just that entry's own
     * one-line reason text.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function returnSourcingPart(int $part, string $reason, User $returnedBy): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'completed_at' => null,
            'returned_at' => now(),
            'return_reason' => $reason,
            'returned_by' => $returnedBy->id,
            'data_entry_completed_at' => null,
            'data_entry_completed_by' => null,
            'senior_ops_reviewed_at' => null,
            'senior_ops_reviewed_by' => null,
            'head_of_bd_approved_at' => null,
            'head_of_bd_approved_by' => null,
            'gm_assistant_completed_at' => null,
            'gm_assistant_completed_by' => null,
            'gm_approved_at' => null,
            'gm_approved_by' => null,
            'bd_closed_at' => null,
            'bd_closed_by' => null,
        ]);
        $this->load('assignees');

        if ($this->isWithDataEntry()) {
            $this->update([
                'sourcing_completed_by' => null,
                'sourcing_completed_at' => null,
            ]);
        }

        // The whole-RFQ "every part is Data-Entry-complete" marker, if it
        // was set, no longer holds — this part's isn't anymore. Checked
        // directly rather than via isDataEntryCompleted() (status ===
        // 'Completed'): under the post-Data-Entry approval chain, status
        // stays 'Pending' all the way through, so that check would never
        // fire here even though this field still needs clearing whenever a
        // return happens after the RFQ had reached Senior Operations'
        // review or later. Also drops status back to Pending, covering the
        // rarer case of a return reaching this method after the RFQ had
        // actually been fully closed out.
        if ($this->data_entry_completed_at !== null || $this->isDataEntryCompleted()) {
            $this->update([
                'status' => 'Pending',
                'data_entry_completed_by' => null,
                'data_entry_completed_at' => null,
            ]);
        }

        $this->postActionComment($returnedBy, 'returned_to_sourcing', $reason, $this->partContext($part) + ['who' => $assignee->name]);
    }

    /**
     * Data Entry finishes processing one Sourcing part — independent of
     * every other part on the same RFQ, so completing one never touches
     * another (even another held by the same person). Idempotent —
     * completing an already-completed part is a no-op. Only once every
     * part has been completed here does the RFQ as a whole move on to
     * Senior Operations' second review — status stays Pending all the way
     * through that approval chain; see closeOut() for what actually
     * closes it out. A $comment, if given, is posted to the RFQ's thread
     * as $completedBy's.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function completeDataEntryPart(int $part, User $completedBy, ?string $comment = null): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee || $assignee->pivot->data_entry_completed_at !== null) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'data_entry_completed_at' => now(),
            'data_entry_completed_by' => $completedBy->id,
        ]);
        $this->load('assignees');

        // Marked as Data Entry's completion, naming whose part and — on a
        // split — which, since a thread covers the whole RFQ.
        if (filled($comment)) {
            $this->postActionComment($completedBy, 'data_entry_completed', $comment, $this->partContext($part) + ['who' => $assignee->name]);
        }

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
     * Whether $part is waiting on Senior Operations' review: the RFQ is still
     * with them (or on its way to them), and the part has been through both
     * Sourcing and Data Entry without being approved yet.
     */
    public function partAwaitsSeniorOpsReview(int $part): bool
    {
        return $this->status === 'Pending'
            && in_array($this->stage, [null, 'senior_ops_review'], true)
            && $this->assigneeForPart($part)?->pivot->isAwaitingSeniorOpsReview() === true;
    }

    /**
     * Whether every planned part has been approved by Senior Operations —
     * what lets the RFQ as a whole move on. False for an RFQ with no
     * Sourcing assignees at all, and false while any planned part is still
     * unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allSeniorOpsPartsReviewed(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->isSeniorOpsApproved());
    }

    /**
     * Senior Operations approves one Sourcing part that has been through
     * Sourcing and Data Entry — on its own, without waiting for the rest of
     * a split. Once every part has been approved the RFQ as a whole moves
     * on, exactly as completeSeniorOpsReview() does. Idempotent — a part
     * that isn't waiting on review (not through Data Entry yet, or already
     * approved) is left alone.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function approveSeniorOpsPart(int $part, User $reviewer): void
    {
        if (! $this->partAwaitsSeniorOpsReview($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'senior_ops_reviewed_at' => now(),
            'senior_ops_reviewed_by' => $reviewer->id,
        ]);
        $this->load('assignees');

        if ($this->allSeniorOpsPartsReviewed()) {
            $this->completeSeniorOpsReview($reviewer);
        }
    }

    /**
     * Senior Operations gives the second-stage review of the whole RFQ, after
     * Data Entry has finished every assignee's split — approving whatever
     * parts haven't been approved one by one along the way. Idempotent.
     * Escalates the RFQ on to Head of Business Development.
     *
     * Caller is responsible for verifying stage === 'senior_ops_review'.
     */
    public function completeSeniorOpsReview(User $reviewer): void
    {
        if ($this->senior_ops_reviewed_at !== null) {
            return;
        }

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNotNull('data_entry_completed_at')
            ->whereNull('senior_ops_reviewed_at')
            ->update([
                'senior_ops_reviewed_at' => now(),
                'senior_ops_reviewed_by' => $reviewer->id,
            ]);
        $this->load('assignees');

        $this->update([
            'senior_ops_reviewed_by' => $reviewer->id,
            'senior_ops_reviewed_at' => now(),
            'stage' => 'head_of_bd_review',
        ]);
    }

    /**
     * Whether $part is waiting on the Head of Business Development: the RFQ
     * is on its way through (or at) their review, and Senior Operations has
     * approved the part without the Head having yet.
     */
    public function partAwaitsHeadOfBdReview(int $part): bool
    {
        return $this->status === 'Pending'
            && in_array($this->stage, [null, 'senior_ops_review', 'head_of_bd_review'], true)
            && $this->assigneeForPart($part)?->pivot->isAwaitingHeadOfBdReview() === true;
    }

    /**
     * Whether every planned part has been approved by the Head of Business
     * Development — what lets the RFQ as a whole move on. False for an RFQ
     * with no Sourcing assignees at all, and false while any planned part is
     * still unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allHeadOfBdPartsApproved(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->isHeadOfBdApproved());
    }

    /**
     * The Head of Business Development approves one Sourcing part Senior
     * Operations has approved — on its own, without waiting for the rest of
     * a split. Once every part has been approved the RFQ as a whole moves
     * on, exactly as approveByHeadOfBd() does. Idempotent — a part that
     * isn't waiting on them (not approved by Senior Operations yet, or
     * already approved) is left alone.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function approveHeadOfBdPart(int $part, User $approver): void
    {
        if (! $this->partAwaitsHeadOfBdReview($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'head_of_bd_approved_at' => now(),
            'head_of_bd_approved_by' => $approver->id,
        ]);
        $this->load('assignees');

        if ($this->allHeadOfBdPartsApproved()) {
            $this->approveByHeadOfBd($approver);
        }
    }

    /**
     * Head of Business Development approves the whole RFQ — approving any
     * part not yet approved on its own — and escalates it on to GM
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

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNotNull('senior_ops_reviewed_at')
            ->whereNull('head_of_bd_approved_at')
            ->update([
                'head_of_bd_approved_at' => now(),
                'head_of_bd_approved_by' => $approver->id,
            ]);
        $this->load('assignees');

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
     *   returnSourcingPart() per assignee unchanged, exactly as if Data
     *   Entry had sent each of them back individually.
     * - Data Entry: lighter reopen — only each assignee's own Data Entry
     *   completion clears (their Sourcing work stays done), so the RFQ
     *   reappears in Data Entry's existing "By Sourcing" queue untouched.
     * - Senior Operations' review: nothing further to reopen below it —
     *   every part is up for their review again.
     *
     * Also posts a comment recording the rejection, same convention as
     * returnSourcingPart().
     *
     * Caller is responsible for verifying stage === 'head_of_bd_review' and
     * $targetStage is one of REJECT_TARGET_STAGES.
     */
    public function rejectToStage(string $targetStage, string $reason, User $rejectedBy): void
    {
        // Whatever it's sent back to, every part's approvals — Senior
        // Operations', the Head's own, and any that had got as far as GM
        // Assistant or the General Manager — go with it; they have to be
        // earned again. A part Business Development has already closed is
        // done with, and left as it is.
        DB::table('rfq_user')->where('rfq_id', $this->id)->whereNull('bd_closed_at')->update([
            'senior_ops_reviewed_at' => null,
            'senior_ops_reviewed_by' => null,
            'head_of_bd_approved_at' => null,
            'head_of_bd_approved_by' => null,
            'gm_assistant_completed_at' => null,
            'gm_assistant_completed_by' => null,
            'gm_approved_at' => null,
            'gm_approved_by' => null,
        ]);
        $this->load('assignees');

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
            foreach ($this->assignees->reject(fn (User $assignee) => $assignee->pivot->isBdClosed()) as $assignee) {
                $this->returnSourcingPart($assignee->pivot->part_number, $reason, $rejectedBy);
            }
        } elseif ($targetStage === 'data_entry') {
            DB::table('rfq_user')->where('rfq_id', $this->id)->whereNull('bd_closed_at')->update([
                'data_entry_completed_at' => null,
                'data_entry_completed_by' => null,
            ]);
            $this->load('assignees');
            $this->update([
                'data_entry_completed_by' => null,
                'data_entry_completed_at' => null,
            ]);
        }

        $this->postActionComment($rejectedBy, 'rejected', $reason, ['stage' => self::stageLabel($targetStage)]);
    }

    /**
     * Head of Business Development rejects one part on its own — sends just
     * that part back to an earlier stage (Sourcing, Data Entry, or Senior
     * Operations' own review) with a reason, the same three places
     * rejectToStage() sends a whole RFQ. Every other part, and any approval
     * already given for it, is left as it was; only this part's approvals go,
     * and with the part no longer approved by Senior Operations the RFQ as a
     * whole no longer is either, so it drops back to wherever its parts now
     * leave it. Also posts a comment recording the rejection, naming the
     * part.
     *
     * Caller is responsible for verifying partAwaitsHeadOfBdReview() and that
     * $targetStage is one of REJECT_TARGET_STAGES.
     */
    public function rejectPartToStage(int $part, string $targetStage, string $reason, User $rejectedBy): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'senior_ops_reviewed_at' => null,
            'senior_ops_reviewed_by' => null,
            'head_of_bd_approved_at' => null,
            'head_of_bd_approved_by' => null,
            'gm_assistant_completed_at' => null,
            'gm_assistant_completed_by' => null,
            'gm_approved_at' => null,
            'gm_approved_by' => null,
            'bd_closed_at' => null,
            'bd_closed_by' => null,
        ]);
        $this->load('assignees');

        if ($targetStage === 'sourcing') {
            $this->returnSourcingPart($part, $reason, $rejectedBy);
        } elseif ($targetStage === 'data_entry') {
            $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
                'data_entry_completed_at' => null,
                'data_entry_completed_by' => null,
            ]);
            $this->load('assignees');
        }

        // Every part through Data Entry means Senior Operations' review; any
        // that isn't means still the pipeline before it.
        $allThroughDataEntry = $this->allDataEntryPartsCompleted();

        $this->update([
            'senior_ops_reviewed_by' => null,
            'senior_ops_reviewed_at' => null,
            'head_of_bd_rejected_by' => $rejectedBy->id,
            'head_of_bd_rejected_at' => now(),
            'head_of_bd_reject_reason' => $reason,
            'head_of_bd_reject_target_stage' => $targetStage,
            'data_entry_completed_by' => $allThroughDataEntry ? $this->data_entry_completed_by : null,
            'data_entry_completed_at' => $allThroughDataEntry ? $this->data_entry_completed_at : null,
            'stage' => $allThroughDataEntry ? 'senior_ops_review' : null,
        ]);

        $this->postActionComment($rejectedBy, 'rejected', $reason, ['stage' => self::stageLabel($targetStage)] + $this->partContext($part));
    }

    /**
     * Posts a comment that goes with an action — its text as written, marked
     * with what was done and the context that went with it (see
     * RfqComment::ACTIONS), so the thread can show that beside the author's
     * name instead of it being written into the text.
     *
     * @param  array<string, mixed>  $meta
     */
    private function postActionComment(User $author, string $action, string $text, array $meta = []): void
    {
        $this->comments()->create([
            'user_id' => $author->id,
            'action' => $action,
            'body' => trim($text),
            'meta' => $meta === [] ? null : $meta,
        ]);
        $this->load(['comments.author', 'comments.replies.author']);
    }

    /**
     * Which part an action was about, for its comment's context — nothing
     * on an RFQ kept whole, where there's only the one.
     *
     * @return array<string, mixed>
     */
    private function partContext(int $part): array
    {
        return $this->isSplit() ? ['part' => $part, 'label' => $this->partNumberLabel($part)] : [];
    }

    /**
     * Whether $part is waiting on GM Assistant: the RFQ is on its way through
     * (or at) their step, and the Head of Business Development has approved
     * the part without GM Assistant having completed it yet.
     */
    public function partAwaitsGmAssistant(int $part): bool
    {
        return $this->status === 'Pending'
            && in_array($this->stage, [null, 'senior_ops_review', 'head_of_bd_review', 'gm_assistant'], true)
            && $this->assigneeForPart($part)?->pivot->isAwaitingGmAssistant() === true;
    }

    /**
     * Whether $part is waiting on the General Manager: the RFQ is on its way
     * through (or at) their step, and GM Assistant has completed the part
     * without the General Manager having approved it yet.
     */
    public function partAwaitsGmApproval(int $part): bool
    {
        return $this->status === 'Pending'
            && in_array($this->stage, [null, 'senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review'], true)
            && $this->assigneeForPart($part)?->pivot->isAwaitingGmApproval() === true;
    }

    /**
     * Whether GM Assistant has completed every planned part — what lets the RFQ
     * as a whole move on to the General Manager. False for an RFQ with no
     * Sourcing assignees at all, and false while any planned part is still
     * unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allGmAssistantPartsCompleted(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->isGmAssistantCompleted());
    }

    /**
     * Whether the General Manager has approved every planned part — what lets
     * the RFQ as a whole move on to Business Development. Same conditions as
     * allGmAssistantPartsCompleted().
     */
    public function allGmPartsApproved(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->isGmApproved());
    }

    /**
     * GM Assistant adds their details for one Sourcing part the Head of
     * Business Development has approved — on its own, without waiting for the
     * rest of a split — and it goes on to the General Manager. The client
     * details and payment terms belong to the RFQ (one client, one set of
     * terms), so what's given here stands for every part: the latest ones
     * win, and are what the form offers next time. Once every part has been
     * completed the RFQ as a whole moves on to the General Manager. Idempotent
     * — a part that isn't waiting on GM Assistant (not approved by the Head
     * yet, or already completed) is left alone, details and all.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function recordGmAssistantPart(int $part, User $completedBy, string $clientDetails, ?string $paymentTerms): void
    {
        if (! $this->partAwaitsGmAssistant($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'gm_assistant_completed_at' => now(),
            'gm_assistant_completed_by' => $completedBy->id,
        ]);
        $this->load('assignees');

        $this->update([
            'client_details' => $clientDetails,
            'payment_terms' => $paymentTerms,
        ]);

        if ($this->allGmAssistantPartsCompleted()) {
            $this->update([
                'gm_assistant_completed_by' => $completedBy->id,
                'gm_assistant_completed_at' => now(),
                'stage' => 'gm_review',
            ]);
        }
    }

    /**
     * GM Assistant records this RFQ's client details and payment terms for
     * every part at once — completing any not yet completed on its own — and
     * forwards it on to the General Manager. Not idempotent-guarded — the
     * details can be corrected before the General Manager acts on them.
     *
     * Caller is responsible for verifying stage === 'gm_assistant'.
     */
    public function recordGmAssistantDetails(User $completedBy, string $clientDetails, ?string $paymentTerms): void
    {
        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNotNull('head_of_bd_approved_at')
            ->whereNull('gm_assistant_completed_at')
            ->update([
                'gm_assistant_completed_at' => now(),
                'gm_assistant_completed_by' => $completedBy->id,
            ]);
        $this->load('assignees');

        $this->update([
            'client_details' => $clientDetails,
            'payment_terms' => $paymentTerms,
            'gm_assistant_completed_by' => $completedBy->id,
            'gm_assistant_completed_at' => now(),
            'stage' => 'gm_review',
        ]);
    }

    /**
     * The General Manager approves one Sourcing part GM Assistant has
     * completed — on its own, without waiting for the rest of a split. Once
     * every part has been approved the RFQ as a whole is ready for Business
     * Development to close out, exactly as approveByGm() does. Idempotent — a
     * part that isn't waiting on the General Manager (not completed by GM
     * Assistant yet, or already approved) is left alone.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function approveGmPart(int $part, User $approver): void
    {
        if (! $this->partAwaitsGmApproval($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'gm_approved_at' => now(),
            'gm_approved_by' => $approver->id,
        ]);
        $this->load('assignees');

        if ($this->allGmPartsApproved()) {
            $this->approveByGm($approver);
        }
    }

    /**
     * General Manager gives final approval to the whole RFQ — approving any
     * part not yet approved on its own — and it's now ready for Business
     * Development to close out. Idempotent.
     *
     * Caller is responsible for verifying stage === 'gm_review'.
     */
    public function approveByGm(User $approver): void
    {
        if ($this->gm_approved_at !== null) {
            return;
        }

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNotNull('gm_assistant_completed_at')
            ->whereNull('gm_approved_at')
            ->update([
                'gm_approved_at' => now(),
                'gm_approved_by' => $approver->id,
            ]);
        $this->load('assignees');

        $this->update([
            'gm_approved_by' => $approver->id,
            'gm_approved_at' => now(),
            'stage' => 'bd_closing',
        ]);
    }

    /**
     * Whether $part is ready for Business Development to close: the RFQ is
     * still open, and the General Manager has approved the part without it
     * having been closed yet.
     */
    public function partAwaitsBdClosing(int $part): bool
    {
        return $this->status === 'Pending'
            && in_array($this->stage, [null, 'senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing'], true)
            && $this->assigneeForPart($part)?->pivot->isAwaitingBdClosing() === true;
    }

    /**
     * Whether Business Development has closed every planned part — what
     * closes the RFQ as a whole. False for an RFQ with no Sourcing assignees
     * at all, and false while any planned part is still unassigned (same
     * reasoning as allSourcingPartsCompleted()).
     */
    public function allBdPartsClosed(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->isBdClosed());
    }

    /**
     * Business Development closes one Sourcing part the General Manager has
     * approved — on its own, without waiting for the rest of a split. Once
     * every part has been closed the RFQ as a whole is, exactly as closeOut()
     * does. Idempotent — a part that isn't ready to close (not approved by the
     * General Manager yet, or already closed) is left alone.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function closePart(int $part, User $closedBy): void
    {
        if (! $this->partAwaitsBdClosing($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'bd_closed_at' => now(),
            'bd_closed_by' => $closedBy->id,
        ]);
        $this->load('assignees');

        if ($this->allBdPartsClosed()) {
            $this->closeOut($closedBy);
        }
    }

    /**
     * Business Development formally closes this whole RFQ out — closing any
     * part not yet closed on its own — the true end of the lifecycle.
     * Idempotent. Reuses the existing 'Completed' status value (see
     * statusLabel() for why the display text says "Closed").
     *
     * Caller is responsible for verifying stage === 'bd_closing'.
     */
    public function closeOut(User $closedBy): void
    {
        if ($this->stage === 'closed') {
            return;
        }

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNotNull('gm_approved_at')
            ->whereNull('bd_closed_at')
            ->update([
                'bd_closed_at' => now(),
                'bd_closed_by' => $closedBy->id,
            ]);
        $this->load('assignees');

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
                'detail' => $this->partNumberLabel($assignee->pivot->part_number),
                'comment' => null,
            ];

            if ($assignee->pivot->completed_at) {
                $entries[] = [
                    'type' => 'sourcing_completed',
                    'at' => $assignee->pivot->completed_at,
                    'actor' => $assignee,
                    'related' => $assignee,
                    'detail' => $this->partNumberLabel($assignee->pivot->part_number),
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
                    'detail' => $this->partNumberLabel($assignee->pivot->part_number),
                    'comment' => null,
                ];
            }

            // Only on a split — an RFQ kept whole has just the one approval at
            // each step, its own (senior_ops_reviewed / head_of_bd_approved
            // below).
            if ($assignee->pivot->senior_ops_reviewed_at && $this->isSplit()) {
                $entries[] = [
                    'type' => 'senior_ops_part_reviewed',
                    'at' => $assignee->pivot->senior_ops_reviewed_at,
                    'actor' => $assignee->pivot->seniorOpsReviewedBy,
                    'related' => $assignee,
                    'detail' => $this->partNumberLabel($assignee->pivot->part_number),
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->head_of_bd_approved_at && $this->isSplit()) {
                $entries[] = [
                    'type' => 'head_of_bd_part_approved',
                    'at' => $assignee->pivot->head_of_bd_approved_at,
                    'actor' => $assignee->pivot->headOfBdApprovedBy,
                    'related' => $assignee,
                    'detail' => $this->partNumberLabel($assignee->pivot->part_number),
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->gm_assistant_completed_at && $this->isSplit()) {
                $entries[] = [
                    'type' => 'gm_assistant_part_completed',
                    'at' => $assignee->pivot->gm_assistant_completed_at,
                    'actor' => $assignee->pivot->gmAssistantCompletedBy,
                    'related' => $assignee,
                    'detail' => $this->partNumberLabel($assignee->pivot->part_number),
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->gm_approved_at && $this->isSplit()) {
                $entries[] = [
                    'type' => 'gm_part_approved',
                    'at' => $assignee->pivot->gm_approved_at,
                    'actor' => $assignee->pivot->gmApprovedBy,
                    'related' => $assignee,
                    'detail' => $this->partNumberLabel($assignee->pivot->part_number),
                    'comment' => null,
                ];
            }

            if ($assignee->pivot->bd_closed_at && $this->isSplit()) {
                $entries[] = [
                    'type' => 'bd_part_closed',
                    'at' => $assignee->pivot->bd_closed_at,
                    'actor' => $assignee->pivot->bdClosedBy,
                    'related' => $assignee,
                    'detail' => $this->partNumberLabel($assignee->pivot->part_number),
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
     * The workflow role a ?role= slug ("senior-operations") stands for, or
     * null for anything that isn't one.
     */
    public static function workflowRoleForSlug(?string $slug): ?string
    {
        return collect(self::WORKFLOW_ROLES)->first(fn (string $role) => Str::slug($role) === $slug);
    }

    /**
     * How many approvals are waiting on Senior Operations — the rows on their
     * Review page: each part that has been through Sourcing and Data Entry and
     * not yet been approved, plus any RFQ at its review stage with no such
     * part of its own, listed whole (see scopeAwaitingSeniorOpsReview()). What
     * the badge on their sidebar's Review link counts.
     */
    public static function seniorOpsReviewCount(): int
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhere('rfqs.stage', 'senior_ops_review'))
            ->whereNotNull('rfq_user.completed_at')
            ->whereNotNull('rfq_user.data_entry_completed_at')
            ->whereNull('rfq_user.senior_ops_reviewed_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'senior_ops_review')
            ->whereDoesntHave('assignees', fn (Builder $assignees) => RfqAssignment::whereAwaitingSeniorOpsReview($assignees))
            ->count();

        return $parts + $wholeRfqs;
    }

    /**
     * How many approvals are waiting on the Head of Business Development —
     * the rows on their Review page: each part Senior Operations has approved
     * and they haven't yet, plus any RFQ at their review stage with no such
     * part of its own, listed whole (see scopeAwaitingHeadOfBdReview()). What
     * the badge on their sidebar's Review link counts.
     */
    public static function headOfBdReviewCount(): int
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review']))
            ->whereNotNull('rfq_user.senior_ops_reviewed_at')
            ->whereNull('rfq_user.head_of_bd_approved_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'head_of_bd_review')
            ->whereDoesntHave('assignees', fn (Builder $assignees) => RfqAssignment::whereAwaitingHeadOfBdReview($assignees))
            ->count();

        return $parts + $wholeRfqs;
    }

    /**
     * How many approvals are waiting on GM Assistant — the rows on their Review
     * page: each part the Head of Business Development has approved and they
     * haven't yet completed, plus any RFQ at their step with no such part of
     * its own, listed whole (see scopeAwaitingGmAssistant()). What the badge on
     * their sidebar's Review link counts.
     */
    public static function gmAssistantReviewCount(): int
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant']))
            ->whereNotNull('rfq_user.head_of_bd_approved_at')
            ->whereNull('rfq_user.gm_assistant_completed_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'gm_assistant')
            ->whereDoesntHave('assignees', fn (Builder $assignees) => RfqAssignment::whereAwaitingGmAssistant($assignees))
            ->count();

        return $parts + $wholeRfqs;
    }

    /**
     * How many approvals are waiting on the General Manager — the rows on
     * their Review page: each part GM Assistant has completed and they haven't
     * yet approved, plus any RFQ at their step with no such part of its own,
     * listed whole (see scopeAwaitingGmApproval()). What the badge on their
     * sidebar's Review link counts.
     */
    public static function gmReviewCount(): int
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review']))
            ->whereNotNull('rfq_user.gm_assistant_completed_at')
            ->whereNull('rfq_user.gm_approved_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'gm_review')
            ->whereDoesntHave('assignees', fn (Builder $assignees) => RfqAssignment::whereAwaitingGmApproval($assignees))
            ->count();

        return $parts + $wholeRfqs;
    }

    /**
     * How many items are ready for Business Development to close — the rows
     * on their Ready to Close page: each part the General Manager has approved
     * and they haven't yet closed, plus any RFQ at its closing stage with no
     * such part of its own, listed whole (see scopeAwaitingBdClosing()). What
     * the badge on their sidebar's Ready to Close link counts.
     */
    public static function bdClosingCount(): int
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing']))
            ->whereNotNull('rfq_user.gm_approved_at')
            ->whereNull('rfq_user.bd_closed_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'bd_closing')
            ->whereDoesntHave('assignees', fn (Builder $assignees) => RfqAssignment::whereAwaitingBdClosing($assignees))
            ->count();

        return $parts + $wholeRfqs;
    }

    /**
     * How many items sit in each role's queue company-wide — what the
     * badges on Admin's grouped sidebar show. Mirrors what each role's own
     * sidebar badge counts (see layouts/app.blade.php), except Sourcing's,
     * which counts everyone's parts rather than one person's.
     *
     * @return array<string, int>
     */
    public static function queueCounts(): array
    {
        $sourcingParts = fn () => DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.completed_at');

        return [
            'closing' => static::bdClosingCount(),
            'unassigned' => static::where('status', 'Pending')->needingSourcing()->count(),
            'ops_review' => static::seniorOpsReviewCount(),
            'sourcing_pending' => $sourcingParts()->count(),
            'sourcing_returns' => $sourcingParts()->whereNotNull('rfq_user.returned_at')->count(),
            'data_entry' => DB::table('rfq_user')->whereNotNull('completed_at')->whereNull('data_entry_completed_at')->count(),
            'head_of_bd' => static::headOfBdReviewCount(),
            'gm_assistant' => static::gmAssistantReviewCount(),
            'gm_review' => static::gmReviewCount(),
        ];
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
     * The RFQ number for one planned Sourcing part — e.g. on "RFQ1001"
     * split five ways, part 3 is "RFQ1001-P3 of P5". Unsplit, there's just
     * the plain rfq_number.
     */
    public function partNumberLabel(int $part): string
    {
        $total = $this->splitTotal();

        return $total > 1 ? "{$this->rfq_number}-P{$part} of P{$total}" : $this->rfq_number;
    }

    /**
     * The RFQ number for a set of parts held together — "RFQ1001-P1 & P4 of
     * P5", "RFQ1001-P1, P2 & P4 of P5" — or just the one part's number when
     * it's a single part.
     *
     * @param  array<int, int>  $parts
     */
    public function partsLabel(array $parts): string
    {
        $total = $this->splitTotal();

        if ($total <= 1 || count($parts) <= 1) {
            return $this->partNumberLabel($parts[0] ?? 1);
        }

        $labels = array_map(fn (int $part) => "P{$part}", $parts);
        $last = array_pop($labels);

        return "{$this->rfq_number}-".implode(', ', $labels)." & {$last} of P{$total}";
    }

    /**
     * Split RFQ numbers per Sourcing assignee — e.g. with three assignees
     * on "RFQ1001": "RFQ1001-P1 of P3", "RFQ1001-P2 of P3",
     * "RFQ1001-P3 of P3". Each person's number follows the part(s) they
     * hold (so P3 is P3 even while P1 and P2 are still empty, and someone
     * holding two parts is "P1 & P4 of P5"); for assignees from before
     * parts were planned up front, it's their position in assignment
     * order. Unsplit, everyone maps to the plain rfq_number — splitting
     * only kicks in once the work is actually shared across more than one
     * part.
     *
     * @return array<int, string> user id => display RFQ number
     */
    public function sourcingSplitNumbers(): array
    {
        return $this->assignees->mapWithKeys(fn (User $assignee) => [
            $assignee->id => $this->partsLabel($this->partNumbersFor($assignee)),
        ])->all();
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
