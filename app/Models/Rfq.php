<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
     * The ?view= values the RFQ list understands, each a second (or third)
     * queue for a role alongside its default one — Sourcing's and Business
     * Development's returns (the same value, one page each, scoped by who's
     * looking), Senior Operations' review, Business Development's
     * ready-to-close.
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
     * Every stage, in pipeline order, that a reject can send an RFQ back
     * to — from the very start (Business Development itself, if it needs
     * fixing there before anything else can be redone) through Senior
     * Operations' own assignment/split step and GM Assistant, just short
     * of the General Manager's own review, which nothing rejects back to.
     * Which of these a given reject-capable stage can actually use is
     * whatever comes before its own position here — see
     * rejectTargetStages().
     *
     * @var array<int, string>
     */
    public const REJECT_STAGE_ORDER = [
        'business_development', 'operations', 'sourcing', 'data_entry', 'senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review',
    ];

    /**
     * The stages that can reject an RFQ back to an earlier one — Senior
     * Operations' second review, the Head of Business Development, and the
     * General Manager. GM Assistant, the other stage in the chain, only
     * ever forwards. See rejectTargetStages(), rejectToStage().
     *
     * @var array<int, string>
     */
    public const REJECTABLE_STAGES = ['senior_ops_review', 'head_of_bd_review', 'gm_review'];

    /**
     * The stages with a Returns page of their own that holds what's sent back
     * to them — the Head of Business Development's review and GM Assistant's
     * step, which only the General Manager sends RFQs back to. An RFQ sent
     * back to one is listed and dealt with there, and only there: not on that
     * role's own Review page, nor on any other role's, whichever of its parts,
     * until they've dealt with it — see scopeNotHeldOnAReturnsPage(),
     * isHeldOnAnotherReturnsPage() and resolveReturnOnceDealtWith().
     *
     * @var array<int, string>
     */
    public const RETURNS_PAGE_STAGES = ['head_of_bd_review', 'gm_assistant'];

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
        'rejected', 'gm_assistant_completed', 'gm_approved', 'bd_closed',
    ];

    protected $fillable = [
        'created_by',
        'operations_assigned_by',
        'operations_assigned_at',
        'sourcing_completed_by',
        'sourcing_completed_at',
        'data_entry_completed_by',
        'data_entry_completed_at',
        'finalized_by',
        'finalized_at',
        'category',
        'category_set_by',
        'category_set_at',
        'split_count',
        'stage',
        'senior_ops_reviewed_by',
        'senior_ops_reviewed_at',
        'head_of_bd_approved_by',
        'head_of_bd_approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'reject_from_stage',
        'reject_target_stage',
        'bd_return_count',
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
            'finalized_at' => 'datetime',
            'category_set_at' => 'datetime',
            'split_count' => 'integer',
            'bd_return_count' => 'integer',
            'senior_ops_reviewed_at' => 'datetime',
            'head_of_bd_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'gm_assistant_completed_at' => 'datetime',
            'gm_approved_at' => 'datetime',
            'bd_closed_at' => 'datetime',
        ];
    }

    /**
     * The stages $fromStage's own review can send an RFQ back to —
     * everything earlier in REJECT_STAGE_ORDER. Senior Operations' second
     * review reaches back through their own assignment/split step to
     * Business Development itself; the Head of Business Development's
     * review reaches back through Senior Operations'; the General
     * Manager's reaches all the way back through GM Assistant. Empty for
     * anything that isn't a REJECTABLE_STAGE.
     *
     * @return array<int, string>
     */
    public static function rejectTargetStages(string $fromStage): array
    {
        $index = array_search($fromStage, self::REJECT_STAGE_ORDER, true);

        return $index === false ? [] : array_slice(self::REJECT_STAGE_ORDER, 0, $index);
    }

    /**
     * The human label for a stage value — used in the reject-target picker,
     * the activity timeline, and status messages. Covers both the stored
     * `stage` values and the two implicit pre-Data-Entry "stages" a reject
     * can send an RFQ back to.
     */
    public static function stageLabel(?string $stage): string
    {
        return match ($stage) {
            'business_development' => 'Business Development',
            'operations' => 'Senior Operations (assignment)',
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
     * Whether this RFQ is currently sitting on Business Development's
     * Returns page — sent all the way back to them and not yet fixed by
     * them (resolveBusinessDevelopmentReturn()) or carried past Senior
     * Operations' review again. Business Development can only edit an RFQ's
     * own details while this is true — see RfqController::update().
     * Re-assigning it is still Senior Operations' job, never theirs — see
     * RfqController::assign().
     */
    public function isReturnedToBusinessDevelopment(): bool
    {
        return $this->reject_target_stage === 'business_development';
    }

    /**
     * Business Development has fixed an RFQ sent back to them — it comes off
     * their Returns page, and the "sent back" banner and note go with it.
     * The rejection's reason stays in the comment thread, where
     * rejectToStage() posted it, and bd_return_count still counts it. A
     * no-op for an RFQ that isn't sitting with them. See
     * RfqController::update().
     */
    public function resolveBusinessDevelopmentReturn(): void
    {
        if (! $this->isReturnedToBusinessDevelopment()) {
            return;
        }

        $this->update($this->clearedRejectRecord());
    }

    /**
     * Whether this RFQ is held on a Returns page (RETURNS_PAGE_STAGES) other
     * than $ownStage's — sent back there and not yet dealt with. While it
     * is, no other review stage can act on any part of it.
     */
    public function isHeldOnAnotherReturnsPage(?string $ownStage = null): bool
    {
        return in_array($this->reject_target_stage, self::RETURNS_PAGE_STAGES, true)
            && $this->reject_target_stage !== $ownStage;
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
            ->withPivot(['part_number', 'completed_at', 'returned_at', 'return_reason', 'returned_by', 'data_entry_completed_at', 'data_entry_completed_by', 'finalized_at', 'finalized_by', 'data_entry_returned_at', 'data_entry_return_reason', 'senior_ops_reviewed_at', 'senior_ops_reviewed_by', 'head_of_bd_approved_at', 'head_of_bd_approved_by', 'gm_assistant_completed_at', 'gm_assistant_completed_by', 'gm_approved_at', 'gm_approved_by', 'bd_closed_at', 'bd_closed_by'])
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
     * Every stretch this RFQ's parts have spent at a timed step, round by
     * round, oldest first — see RfqStep, syncSteps() and timeSpent().
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RfqStep::class)->oldest('started_at')->oldest('id');
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
     * The Sourcing member whose Finalize was the last one needed — every
     * part finalized, the RFQ as a whole moved on to Senior Operations'
     * review. See finalizePart().
     */
    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
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
     * Whoever last rejected this RFQ back to an earlier stage — Senior
     * Operations, the Head of Business Development, or the General
     * Manager; which one is reject_from_stage. See rejectToStage().
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
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
     * Whether every Sourcing part has been finalized by its Sourcing member —
     * the condition that moves the RFQ as a whole on to Senior Operations'
     * review. Same conditions as allDataEntryPartsCompleted().
     */
    public function allPartsFinalized(): bool
    {
        return $this->assignees->isNotEmpty()
            && $this->allPartsAssigned()
            && $this->assignees->every(fn (User $assignee) => $assignee->pivot->finalized_at !== null);
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
     * What's on Senior Operations' Returns page: an RFQ sent back to them —
     * by their own second review, the Head of Business Development, or the
     * General Manager (rejectToStage()/rejectPartToStage()) — that's still
     * waiting on them. Sent back to their assignment step ('operations'),
     * that's while a part is still unassigned; once they've re-assigned it,
     * it's done with here. Sent back to their second review, it's while a
     * part still awaits that review; approving it clears the record (see
     * clearedRejectRecord()).
     */
    public function scopeReturnedToSeniorOperations(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where(fn (Builder $toAssignment) => $toAssignment
                ->where('rfqs.reject_target_stage', 'operations')
                ->needingSourcing())
            ->orWhere(fn (Builder $toReview) => $toReview
                ->where('rfqs.reject_target_stage', 'senior_ops_review')
                ->awaitingSeniorOpsReview()));
    }

    /**
     * What's on the Head of Business Development's Returns page: an RFQ the
     * General Manager sent back to their review (rejectToStage()/
     * rejectPartToStage() targeting 'head_of_bd_review' — nobody else can)
     * that's still waiting on it. Approving it again clears the record (see
     * clearedRejectRecord()); sending it further back retargets it.
     */
    public function scopeReturnedToHeadOfBd(Builder $query): void
    {
        $query->where('rfqs.reject_target_stage', 'head_of_bd_review')->awaitingHeadOfBdReview();
    }

    /**
     * What's on GM Assistant's Returns page: an RFQ the General Manager sent
     * back to their step (rejectToStage()/rejectPartToStage() targeting
     * 'gm_assistant' — nobody else can) that's still waiting on them. Adding
     * the details again resolves it (resolveReturnOnceDealtWith()); being
     * sent further back by the General Manager retargets it.
     */
    public function scopeReturnedToGmAssistant(Builder $query): void
    {
        $query->where('rfqs.reject_target_stage', 'gm_assistant')->awaitingGmAssistant();
    }

    /**
     * Leaves out what's held on a Returns page (RETURNS_PAGE_STAGES) — an
     * RFQ sent back to the Head of Business Development or GM Assistant is
     * dealt with there, and only there: not on that role's own Review page,
     * and not on any other role's review page either (Senior Operations',
     * the Head's, GM Assistant's, the General Manager's), whatever its other
     * parts are up to, until it's been dealt with.
     */
    public function scopeNotHeldOnAReturnsPage(Builder $query): void
    {
        static::excludeHeldOnAReturnsPage($query);
    }

    /**
     * The condition behind scopeNotHeldOnAReturnsPage(), for the review
     * counts' plain rfq_user/rfqs queries too.
     */
    private static function excludeHeldOnAReturnsPage(Builder|QueryBuilder $query): void
    {
        $query->where(fn ($q) => $q
            ->whereNull('rfqs.reject_target_stage')
            ->orWhereNotIn('rfqs.reject_target_stage', self::RETURNS_PAGE_STAGES));
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

        $this->syncSteps();
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

        $this->syncSteps();
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
            'finalized_at' => null,
            'finalized_by' => null,
            'data_entry_returned_at' => null,
            'data_entry_return_reason' => null,
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
        if ($this->data_entry_completed_at !== null || $this->finalized_at !== null || $this->isDataEntryCompleted()) {
            $this->update([
                'status' => 'Pending',
                'data_entry_completed_by' => null,
                'data_entry_completed_at' => null,
                'finalized_by' => null,
                'finalized_at' => null,
            ]);
        }

        $this->postActionComment($returnedBy, 'returned_to_sourcing', $reason, $this->partContext($part) + ['who' => $assignee->name]);

        $this->syncSteps();
    }

    /**
     * Data Entry finishes processing one Sourcing part and sends it to
     * finalize — back to its Sourcing member, whose Finalize (finalizePart())
     * is what sends it on to Senior Operations' second review. Independent
     * of every other part on the same RFQ, so completing one never touches
     * another (even another held by the same person). Idempotent —
     * completing an already-completed part is a no-op. Once every part has
     * been through here, that's recorded on the RFQ too, but it doesn't
     * move on until every part has also been finalized. A $comment, if
     * given, is posted to the RFQ's thread as $completedBy's.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function completeDataEntryPart(int $part, User $completedBy, ?string $comment = null): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee || $assignee->pivot->data_entry_completed_at !== null) {
            return;
        }

        // Sending it to finalize again puts any return from its Sourcing
        // member (returnToDataEntry()) behind it.
        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'data_entry_completed_at' => now(),
            'data_entry_completed_by' => $completedBy->id,
            'data_entry_returned_at' => null,
            'data_entry_return_reason' => null,
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
            ]);
        }

        $this->syncSteps();
    }

    /**
     * Whether $part is waiting on its Sourcing member's Finalize: the RFQ is
     * still open, and Data Entry has sent the part to finalize.
     */
    public function partAwaitsFinalize(int $part): bool
    {
        return $this->status === 'Pending'
            && $this->assigneeForPart($part)?->pivot->isAwaitingFinalize() === true;
    }

    /**
     * The Sourcing member finalizes one part Data Entry has sent to finalize
     * — on its own, and it goes straight on to Senior Operations' review,
     * without waiting for the rest of a split. Once every part has been
     * finalized the RFQ as a whole moves on to that review (stage
     * senior_ops_review). Recorded as the part's own assignee, whoever
     * clicks it (Admin can, on their behalf). Idempotent — a part that isn't
     * waiting on it is left alone.
     *
     * Caller is responsible for verifying who's finalizing it.
     */
    public function finalizePart(int $part): void
    {
        if (! $this->partAwaitsFinalize($part)) {
            return;
        }

        $assignee = $this->assigneeForPart($part);

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'finalized_at' => now(),
            'finalized_by' => $assignee->id,
        ]);
        $this->load('assignees');

        if ($this->allPartsFinalized()) {
            $this->update([
                'finalized_by' => $assignee->id,
                'finalized_at' => now(),
                'stage' => 'senior_ops_review',
            ]);
        }

        $this->syncSteps();
    }

    /**
     * The Sourcing member sends a part Data Entry has sent to finalize back
     * to Data Entry instead, with a reason — undoing Data Entry's Send to
     * Finalize on just that part, so it's on their Ready for Data Entry queue
     * again, the reason shown with it until they send it to finalize again.
     * If every part had been through Data Entry, the RFQ's own record of that
     * goes too. The reason is posted to the RFQ's thread as the member's,
     * marked as this return. Recorded as the part's own assignee, whoever
     * clicks it (Admin can, on their behalf). Idempotent — a part that isn't
     * waiting on its member's Finalize is left alone.
     *
     * Caller is responsible for verifying who's returning it.
     */
    public function returnToDataEntry(int $part, string $reason): void
    {
        if (! $this->partAwaitsFinalize($part)) {
            return;
        }

        $assignee = $this->assigneeForPart($part);

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'data_entry_completed_at' => null,
            'data_entry_completed_by' => null,
            'data_entry_returned_at' => now(),
            'data_entry_return_reason' => $reason,
        ]);
        $this->load('assignees');

        if ($this->data_entry_completed_at !== null) {
            $this->update([
                'data_entry_completed_by' => null,
                'data_entry_completed_at' => null,
            ]);
        }

        $this->postActionComment($assignee, 'returned_to_data_entry', $reason, $this->partContext($part));

        $this->syncSteps();
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
            && ! $this->isHeldOnAnotherReturnsPage()
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
            ->whereNotNull('finalized_at')
            ->whereNull('senior_ops_reviewed_at')
            ->update([
                'senior_ops_reviewed_at' => now(),
                'senior_ops_reviewed_by' => $reviewer->id,
            ]);
        $this->load('assignees');

        $this->update($this->clearedRejectRecord() + [
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
            && ! $this->isHeldOnAnotherReturnsPage('head_of_bd_review')
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

        $this->resolveReturnOnceDealtWith('head_of_bd_review');

        $this->syncSteps();
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

        $this->update($this->clearedRejectRecord() + [
            'head_of_bd_approved_by' => $approver->id,
            'head_of_bd_approved_at' => now(),
            'stage' => 'gm_assistant',
        ]);

        $this->syncSteps();
    }

    /**
     * The rfqs-table columns — the same names exist on the rfq_user pivot,
     * see RfqAssignment::casts() — that record a stage's own approval or
     * completion. Used to work out what a reject to a given stage has to
     * clear: that stage's own marker and everything recorded after it,
     * since nothing later can still stand once an earlier one is being
     * redone. 'operations' and 'sourcing' aren't here — rejecting to
     * either reopens the part itself rather than just clearing a marker,
     * see rejectToStage().
     *
     * @var array<string, array<int, string>>
     */
    private const REJECT_MARKER_COLUMNS = [
        // Redoing Data Entry means finalizing it again too.
        'data_entry' => ['data_entry_completed_at', 'data_entry_completed_by', 'finalized_at', 'finalized_by'],
        'senior_ops_review' => ['senior_ops_reviewed_at', 'senior_ops_reviewed_by'],
        'head_of_bd_review' => ['head_of_bd_approved_at', 'head_of_bd_approved_by'],
        'gm_assistant' => ['gm_assistant_completed_at', 'gm_assistant_completed_by'],
        'gm_review' => ['gm_approved_at', 'gm_approved_by'],
    ];

    /**
     * The columns (rfqs-table mirror, and identically-named rfq_user pivot
     * columns) a reject to $targetStage clears — that stage's own marker
     * and every one after it, right through the General Manager's. See
     * REJECT_MARKER_COLUMNS.
     *
     * @return array<int, string>
     */
    private static function markerColumnsFrom(string $targetStage): array
    {
        $index = array_search($targetStage, self::REJECT_STAGE_ORDER, true);
        $stages = $index === false ? [] : array_slice(self::REJECT_STAGE_ORDER, $index);

        return collect($stages)->flatMap(fn (string $stage) => self::REJECT_MARKER_COLUMNS[$stage] ?? [])->all();
    }

    /**
     * The fields that retire a pending "sent back" record — merged into a
     * forward-moving approval's own update() (completeSeniorOpsReview(),
     * approveByHeadOfBd(), approveByGm()), since reaching that far again
     * means whatever it was rejected over, by whichever of the three, no
     * longer needs saying.
     *
     * @return array<string, null>
     */
    private function clearedRejectRecord(): array
    {
        return [
            'rejected_by' => null,
            'rejected_at' => null,
            'reject_reason' => null,
            'reject_from_stage' => null,
            'reject_target_stage' => null,
        ];
    }

    /**
     * Frees one Sourcing part for Senior Operations to redo the assignment
     * on — detached rather than merely reopened, so it can go to whoever
     * they choose (the same person again, or someone else), exactly like a
     * still-open part of a partly-assigned split. A no-op if nobody holds
     * it. See rejectToStage()/rejectPartToStage()'s 'operations' target.
     */
    private function freePart(int $part): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->detach($assignee->id);
        $this->load('assignees');
    }

    /**
     * Senior Operations' second review, the Head of Business Development, or
     * the General Manager rejects — sends the whole RFQ back to an earlier
     * stage with a reason, undoing whatever came after that stage so it has
     * to be earned again:
     *
     * - Business Development itself ('business_development'), further back
     *   than Operations' own step: the same as 'operations' below, and
     *   also clears who in Operations is routing it — going back this far
     *   means even that has to be picked up again, same as a brand new
     *   RFQ.
     * - Senior Operations' own assignment/split step ('operations'): every
     *   open Sourcing part is freed for them to redo the assignment — see
     *   freePart().
     * - Sourcing: every assignee's split reopens in place instead — reuses
     *   returnSourcingPart() per assignee unchanged, exactly as if Data
     *   Entry had sent each of them back individually.
     * - Data Entry, Senior Operations' review, or GM Assistant: that
     *   stage's own approval, and everything recorded after it, is
     *   cleared — see markerColumnsFrom().
     *
     * A part Business Development has already closed is done with, and
     * left as it is throughout. Also posts a comment recording the
     * rejection, same convention as returnSourcingPart().
     *
     * Caller is responsible for verifying $fromStage is one of
     * REJECTABLE_STAGES, stage === $fromStage, and $targetStage is one of
     * rejectTargetStages($fromStage).
     */
    public function rejectToStage(string $targetStage, string $reason, User $rejectedBy, string $fromStage = 'head_of_bd_review'): void
    {
        $columns = array_fill_keys(self::markerColumnsFrom($targetStage), null);

        DB::table('rfq_user')->where('rfq_id', $this->id)->whereNull('bd_closed_at')->update($columns);
        $this->load('assignees');

        $this->update($columns + [
            'rejected_by' => $rejectedBy->id,
            'rejected_at' => now(),
            'reject_reason' => $reason,
            'reject_from_stage' => $fromStage,
            'reject_target_stage' => $targetStage,
            'stage' => in_array($targetStage, self::STAGES, true) ? $targetStage : null,
        ]);

        $openParts = $this->assignees->reject(fn (User $assignee) => $assignee->pivot->isBdClosed());

        if (in_array($targetStage, ['business_development', 'operations'], true)) {
            foreach ($openParts as $assignee) {
                $this->freePart($assignee->pivot->part_number);
            }
            if ($this->isWithDataEntry()) {
                $this->update(['sourcing_completed_by' => null, 'sourcing_completed_at' => null]);
            }
            if ($targetStage === 'business_development') {
                // Further back than a redo of the assignment: the whole
                // Assign Sourcing wizard opens again for whoever picks this
                // up, category step included, not just "fill the same
                // split back in" — split_count null is what makes assign()
                // treat it as unplanned again. The category itself is left
                // as it was, a sensible starting point that step 1 still
                // lets be changed.
                $this->update(['operations_assigned_by' => null, 'operations_assigned_at' => null, 'split_count' => null]);
                $this->increment('bd_return_count');
            }
        } elseif ($targetStage === 'sourcing') {
            foreach ($openParts as $assignee) {
                $this->returnSourcingPart($assignee->pivot->part_number, $reason, $rejectedBy);
            }
        }

        $this->postActionComment($rejectedBy, 'rejected', $reason, ['stage' => self::stageLabel($targetStage)]);

        $this->syncSteps();
    }

    /**
     * Senior Operations' second review, the Head of Business Development, or
     * the General Manager rejects one part on its own — sends just that part
     * back to an earlier stage with a reason, the same stages
     * rejectToStage() sends a whole RFQ to. Every other part, and any
     * approval already given for it, is left as it was; only this part's
     * approvals go, and with it no longer through $targetStage the RFQ as a
     * whole no longer is either, so it drops back to wherever its parts now
     * leave it. Also posts a comment recording the rejection, naming the
     * part.
     *
     * Caller is responsible for verifying $fromStage is one of
     * REJECTABLE_STAGES, the part actually awaits $fromStage's review, and
     * $targetStage is one of rejectTargetStages($fromStage).
     *
     * 'business_development' behaves the same as 'operations' here — just
     * this part is freed. Unlike the whole-RFQ rejectToStage(), it doesn't
     * clear who in Operations is routing the RFQ: that's a whole-RFQ fact,
     * and any other part of a split may still legitimately be theirs.
     */
    public function rejectPartToStage(int $part, string $targetStage, string $reason, User $rejectedBy, string $fromStage = 'head_of_bd_review'): void
    {
        if (in_array($targetStage, ['business_development', 'operations'], true)) {
            if (! $this->assigneeForPart($part)) {
                return;
            }

            $this->freePart($part);

            if ($this->isWithDataEntry()) {
                $this->update(['sourcing_completed_by' => null, 'sourcing_completed_at' => null]);
            }
            if ($targetStage === 'business_development') {
                $this->increment('bd_return_count');
            }
        } elseif ($targetStage === 'sourcing') {
            if (! $this->assigneeForPart($part)) {
                return;
            }

            $this->returnSourcingPart($part, $reason, $rejectedBy);
        } else {
            $assignee = $this->assigneeForPart($part);

            if (! $assignee) {
                return;
            }

            $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot(
                $assignee->id,
                array_fill_keys(self::markerColumnsFrom($targetStage), null)
            );
            $this->load('assignees');
        }

        // The RFQ's own Data Entry and Finalize records stand only while
        // every part's still through them; its stage is the furthest one
        // every part has reached (stageEveryPartHasReached()).
        $allThroughDataEntry = $this->allDataEntryPartsCompleted();
        $allFinalized = $this->allPartsFinalized();

        $mirrorColumns = array_fill_keys(self::markerColumnsFrom($targetStage), null);
        $mirrorColumns['data_entry_completed_by'] = $allThroughDataEntry ? $this->data_entry_completed_by : null;
        $mirrorColumns['data_entry_completed_at'] = $allThroughDataEntry ? $this->data_entry_completed_at : null;
        $mirrorColumns['finalized_by'] = $allFinalized ? $this->finalized_by : null;
        $mirrorColumns['finalized_at'] = $allFinalized ? $this->finalized_at : null;

        $this->update($mirrorColumns + [
            'rejected_by' => $rejectedBy->id,
            'rejected_at' => now(),
            'reject_reason' => $reason,
            'reject_from_stage' => $fromStage,
            'reject_target_stage' => $targetStage,
            'stage' => $this->stageEveryPartHasReached(),
        ]);

        $this->postActionComment($rejectedBy, 'rejected', $reason, ['stage' => self::stageLabel($targetStage)] + $this->partContext($part));

        $this->syncSteps();
    }

    /**
     * The furthest stage every part of this RFQ has reached — what its
     * `stage` should read once one part's been sent back on its own
     * (rejectPartToStage()), the same way every other step only moves the
     * RFQ on once all its parts are through: null while any part is still
     * short of Finalize, then each review in turn. A part sent back by the
     * General Manager to the Head of Business Development leaves the RFQ at
     * the Head's review, not back at Senior Operations', where none of it
     * is waiting.
     */
    private function stageEveryPartHasReached(): ?string
    {
        return match (true) {
            ! $this->allPartsFinalized() => null,
            ! $this->allSeniorOpsPartsReviewed() => 'senior_ops_review',
            ! $this->allHeadOfBdPartsApproved() => 'head_of_bd_review',
            ! $this->allGmAssistantPartsCompleted() => 'gm_assistant',
            ! $this->allGmPartsApproved() => 'gm_review',
            default => 'bd_closing',
        };
    }

    /**
     * Brings the step log up to date with where each part is now: a part
     * that's left the step it was logged at has that stretch ended, and one
     * that's reached a timed step has a new stretch started — both now. A
     * part that's been freed has its stretch ended too, and on a closed RFQ
     * every stretch is. Called at the end of every workflow action that moves
     * a part; safe to call any time, since a part still where it was logged
     * is left alone.
     */
    public function syncSteps(): void
    {
        $this->load('assignees');

        $current = $this->status === 'Pending'
            ? $this->assignees
                ->mapWithKeys(fn (User $assignee) => [$assignee->pivot->part_number => [
                    'assignee_id' => $assignee->id,
                    'step' => $this->timedStepFor($assignee->pivot),
                ]])
                ->filter(fn (array $now) => $now['step'] !== null)
            : collect();

        foreach (RfqStep::query()->where('rfq_id', $this->id)->whereNull('ended_at')->get() as $open) {
            $now = $current->get($open->part_number);

            if ($now !== null && $now['step'] === $open->step && $now['assignee_id'] === $open->assignee_id) {
                $current->forget($open->part_number);
            } else {
                $part = $this->assignees->first(fn (User $assignee) => $assignee->pivot->part_number === $open->part_number
                    && $assignee->id === $open->assignee_id)?->pivot;

                $endedByRole = $this->status === 'Pending' && $part !== null && $this->roleFinished($open->step, $part);

                $open->update([
                    'ended_at' => now(),
                    'ended_by_role' => $endedByRole,
                    'worked_by' => $open->worked_by ?? ($endedByRole ? $this->finishedBy($open->step, $part) : null),
                ]);
            }
        }

        foreach ($current as $part => $now) {
            RfqStep::query()->create([
                'rfq_id' => $this->id,
                'part_number' => $part,
                'assignee_id' => $now['assignee_id'],
                // Sourcing's own time is the part's member's from the start;
                // Data Entry's and GM Assistant's is whoever finishes it.
                'worked_by' => in_array($now['step'], ['sourcing', 'finalize'], true) ? $now['assignee_id'] : null,
                'step' => $now['step'],
                'started_at' => now(),
            ]);
        }
    }

    /**
     * Whether $part left $step by its role's own doing — Sourcing marking it
     * complete or finalizing it (or sending it back to Data Entry), Data Entry
     * sending it to finalize (or back to Sourcing), GM Assistant adding their
     * details — rather than by being held or reset by someone else. Read
     * just after it left, from where the part is now.
     */
    private function roleFinished(string $step, RfqAssignment $part): bool
    {
        return match ($step) {
            'sourcing' => $part->completed_at !== null,
            'data_entry' => $part->data_entry_completed_at !== null
                || ($part->completed_at === null && $part->returned_at !== null),
            'finalize' => $part->finalized_at !== null
                || ($part->data_entry_completed_at === null && $part->data_entry_returned_at !== null),
            'gm_assistant' => $part->gm_assistant_completed_at !== null,
            default => false,
        };
    }

    /**
     * Who finished $step on $part, by its role's own doing (roleFinished()) —
     * whose time it was: the part's member for Sourcing's steps, whoever sent
     * it to finalize (or back) for Data Entry, whoever added the details for
     * GM Assistant. Read just after, from where the part is now.
     */
    private function finishedBy(string $step, RfqAssignment $part): ?int
    {
        return match ($step) {
            'sourcing', 'finalize' => $part->user_id,
            'data_entry' => $part->data_entry_completed_by ?? $part->returned_by,
            'gm_assistant' => $part->gm_assistant_completed_by,
            default => null,
        };
    }

    /**
     * The timed step a part is at, if it's at one (see RfqStep::ROLE_STEPS):
     * with Sourcing until they mark it complete — rework included — then
     * with Data Entry until they send it to finalize, then back with Sourcing
     * to finalize. After that it's with the reviewers, untimed, until the
     * Head of Business Development approves it and it's GM Assistant's — but
     * not while the RFQ's held on another stage's Returns page, when GM
     * Assistant can't act on it.
     */
    private function timedStepFor(RfqAssignment $part): ?string
    {
        return match (true) {
            $part->completed_at === null => 'sourcing',
            $part->data_entry_completed_at === null => 'data_entry',
            $part->finalized_at === null => 'finalize',
            $part->isAwaitingGmAssistant() && ! $this->isHeldOnAnotherReturnsPage('gm_assistant') => 'gm_assistant',
            default => null,
        };
    }

    /**
     * Where $part's countdown stands with its Sourcing member: the working
     * time its priority allows them (Setting::sourcingTargets()) less what
     * this round with them has taken so far, less any day they were absent
     * (Attendance) — negative once it's overdue.
     * Null if the part isn't with Sourcing right now. Each round starts its
     * own countdown. Expects steps to be loaded, or loads them.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function sourcingCountdown(int $part, ?CarbonInterface $now = null): ?array
    {
        $stretch = $this->steps->first(fn (RfqStep $step) => $step->part_number === $part
            && $step->step === 'sourcing'
            && $step->ended_at === null);

        if ($stretch === null || $this->status !== 'Pending') {
            return null;
        }

        $target = $this->sourcingTargetSeconds();

        // A day its member was absent doesn't count against them.
        return [
            'target' => $target,
            'remaining' => $target - $stretch->secondsExcludingAbsence(Attendance::book([$stretch->worked_by]), $now),
        ];
    }

    /**
     * The working time Sourcing has for a round on this RFQ, by its priority
     * — see Setting::sourcingTargets(). In seconds.
     */
    public function sourcingTargetSeconds(): int
    {
        return (Setting::sourcingTargets()[$this->priority_level] ?? Setting::DEFAULT_SOURCING_TARGETS['Medium']) * 60;
    }

    /**
     * How this RFQ stands against Sourcing's deadline — its priority's target
     * for each round with them (sourcingTargetSeconds()): the least time left
     * on a round still open (negative once it's overdue; null with none open),
     * how many finished rounds ran past the target and by how much in all,
     * and whether it's been exceeded at all — overdue now, or finished late.
     * A day its member was absent (Attendance) doesn't count against it. Null
     * if Sourcing hasn't had it yet. $book is Attendance::book(), loaded if
     * not given. Expects steps to be loaded, or loads them.
     *
     * @param  array<int, array<string, string>>|null  $book
     * @return array{target: int, remaining: ?int, late_rounds: int, late_by: int, exceeded: bool}|null in seconds
     */
    public function sourcingDeadline(?CarbonInterface $now = null, ?array $book = null): ?array
    {
        $rounds = $this->steps->where('step', 'sourcing');

        if ($rounds->isEmpty()) {
            return null;
        }

        $now ??= now();
        $book ??= Attendance::book($rounds->pluck('worked_by'));
        $target = $this->sourcingTargetSeconds();

        // A day its member was absent doesn't count against them.
        $taken = $rounds->mapWithKeys(fn (RfqStep $round) => [$round->id => $round->secondsExcludingAbsence($book, $now)]);

        $remaining = $this->status === 'Pending'
            ? $rounds->whereNull('ended_at')->map(fn (RfqStep $round) => $target - $taken[$round->id])->min()
            : null;
        $late = $rounds->filter(fn (RfqStep $round) => $round->ended_at !== null && $taken[$round->id] > $target);

        return [
            'target' => $target,
            'remaining' => $remaining,
            'late_rounds' => $late->count(),
            'late_by' => (int) $late->sum(fn (RfqStep $round) => $taken[$round->id] - $target),
            'exceeded' => $late->isNotEmpty() || ($remaining !== null && $remaining <= 0),
        ];
    }

    /**
     * The time each tracked role has spent on this RFQ (see
     * RfqStep::ROLE_STEPS), every round added up. Its working time — only the
     * working hours count (Setting::workingSecondsBetween()) — and, from the
     * day attendance starts, only the days the attendance sheet has whoever
     * it's credited to present (Attendance): per part and in all, in minutes
     * and to the second; besides it, what's on a day whose sheet isn't made
     * yet (awaiting), and what's on a day they were absent. Its elapsed
     * (clock) time, nights and days off included, so a stretch outside
     * working hours still shows; how many rounds it took and how many of
     * those were rework (a part back at the role after its first round); and
     * whether it's still going — counted up to $now while it is. And the work
     * done out of hours (RfqStep::isOutOfHoursWork()): how many stretches,
     * and how much of them fell outside the working hours. $book is
     * Attendance::book(), loaded for this RFQ's people if not given. Expects
     * steps to be loaded, or loads them.
     *
     * @param  array<int, array<string, string>>|null  $book
     * @return array{roles: array<string, array{parts: array<int, int>, minutes: int, seconds: int, awaiting: int, absent: int, elapsed: int, out_of_hours: int, out_of_hours_count: int, rounds: int, reworks: int, ongoing: bool}>, minutes: int, seconds: int, awaiting: int, absent: int, elapsed: int, out_of_hours: int, out_of_hours_count: int}
     */
    public function timeSpent(?CarbonInterface $now = null, ?array $book = null): array
    {
        $now ??= now();
        $book ??= Attendance::book($this->steps->pluck('worked_by'));
        $roles = [];

        foreach (RfqStep::ROLE_STEPS as $role => $roleSteps) {
            $stretches = $this->steps->filter(fn (RfqStep $step) => in_array($step->step, $roleSteps, true));
            $splits = $stretches->mapWithKeys(fn (RfqStep $step) => [$step->id => $step->secondsByAttendance($book, $now)]);

            $partSeconds = $stretches->groupBy('part_number')
                ->map(fn ($partStretches) => $partStretches->sum(fn (RfqStep $step) => $splits[$step->id]['present']))
                ->sortKeys();
            $seconds = (int) $partSeconds->sum();

            // A round is a time a part reached the role's own work — reaching
            // Finalize doesn't make a new one for Sourcing.
            $rounds = $stretches->where('step', $roleSteps[0]);

            $roles[$role] = [
                'parts' => $partSeconds->map(fn (int $partTotal) => intdiv($partTotal, 60))->all(),
                'minutes' => intdiv($seconds, 60),
                'seconds' => $seconds,
                'awaiting' => (int) $splits->sum('unmarked'),
                'absent' => (int) $splits->sum('absent'),
                'elapsed' => (int) $stretches->sum(fn (RfqStep $step) => $step->elapsedSeconds($now)),
                'out_of_hours' => (int) $stretches->sum(fn (RfqStep $step) => $step->outOfHoursSeconds()),
                'out_of_hours_count' => $stretches->filter(fn (RfqStep $step) => $step->isOutOfHoursWork())->count(),
                'rounds' => $rounds->count(),
                'reworks' => $rounds->count() - $rounds->pluck('part_number')->unique()->count(),
                'ongoing' => $stretches->contains(fn (RfqStep $step) => $step->ended_at === null),
            ];
        }

        $seconds = array_sum(array_column($roles, 'seconds'));

        return [
            'roles' => $roles,
            'minutes' => intdiv($seconds, 60),
            'seconds' => $seconds,
            'awaiting' => array_sum(array_column($roles, 'awaiting')),
            'absent' => array_sum(array_column($roles, 'absent')),
            'elapsed' => array_sum(array_column($roles, 'elapsed')),
            'out_of_hours' => array_sum(array_column($roles, 'out_of_hours')),
            'out_of_hours_count' => array_sum(array_column($roles, 'out_of_hours_count')),
        ];
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
            && ! $this->isHeldOnAnotherReturnsPage('gm_assistant')
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
            && ! $this->isHeldOnAnotherReturnsPage()
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

        $this->resolveReturnOnceDealtWith('gm_assistant');

        $this->syncSteps();
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

        $this->resolveReturnOnceDealtWith('gm_assistant');

        $this->syncSteps();
    }

    /**
     * An RFQ held on $stage's Returns page (RETURNS_PAGE_STAGES) is resolved
     * once that stage has nothing of it left to deal with — every part it
     * was sent back for is through again — even while other parts are still
     * on their way: it comes off that Returns page, and back onto everyone
     * else's review pages. Without this it would stay held until the whole
     * RFQ went past the stage, which a part still in Sourcing, say, would put
     * off indefinitely. Nothing to do while anything's still waiting on
     * $stage, or if it isn't $stage's return.
     */
    private function resolveReturnOnceDealtWith(string $stage): void
    {
        $stillWaiting = match ($stage) {
            'head_of_bd_review' => fn (User $assignee) => $assignee->pivot->isAwaitingHeadOfBdReview(),
            'gm_assistant' => fn (User $assignee) => $assignee->pivot->isAwaitingGmAssistant(),
        };

        if ($this->reject_target_stage !== $stage || $this->assignees->contains($stillWaiting)) {
            return;
        }

        $this->update($this->clearedRejectRecord());
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

        $this->update($this->clearedRejectRecord() + [
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

        $this->syncSteps();
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

            if ($assignee->pivot->finalized_at) {
                $entries[] = [
                    'type' => 'sourcing_finalized',
                    'at' => $assignee->pivot->finalized_at,
                    'actor' => $assignee->pivot->finalizedBy,
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

        if ($this->rejected_at) {
            $entries[] = [
                'type' => 'rejected',
                'at' => $this->rejected_at,
                'actor' => $this->rejectedBy,
                'related' => null,
                'detail' => 'By '.self::stageLabel($this->reject_from_stage).' — returned to '.self::stageLabel($this->reject_target_stage).': '.$this->reject_reason,
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
            ->tap(fn ($query) => static::excludeHeldOnAReturnsPage($query))
            ->whereNotNull('rfq_user.completed_at')
            ->whereNotNull('rfq_user.data_entry_completed_at')
            ->whereNotNull('rfq_user.finalized_at')
            ->whereNull('rfq_user.senior_ops_reviewed_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'senior_ops_review')
            ->notHeldOnAReturnsPage()
            ->whereDoesntHave('assignees', fn (Builder $assignees) => RfqAssignment::whereAwaitingSeniorOpsReview($assignees))
            ->count();

        return $parts + $wholeRfqs;
    }

    /**
     * How many approvals are waiting on the Head of Business Development —
     * the rows on their Review page: each part Senior Operations has approved
     * and they haven't yet, plus any RFQ at their review stage with no such
     * part of its own, listed whole (see scopeAwaitingHeadOfBdReview()) — but
     * none the General Manager sent back to them, which are on their Returns
     * page instead (see headOfBdReturnsCount()). What the badge on their
     * sidebar's Review link counts.
     */
    public static function headOfBdReviewCount(): int
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review']))
            ->tap(fn ($query) => static::excludeHeldOnAReturnsPage($query))
            ->whereNotNull('rfq_user.senior_ops_reviewed_at')
            ->whereNull('rfq_user.head_of_bd_approved_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'head_of_bd_review')
            ->notHeldOnAReturnsPage()
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
            ->tap(fn ($query) => static::excludeHeldOnAReturnsPage($query))
            ->whereNotNull('rfq_user.head_of_bd_approved_at')
            ->whereNull('rfq_user.gm_assistant_completed_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'gm_assistant')
            ->notHeldOnAReturnsPage()
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
            ->tap(fn ($query) => static::excludeHeldOnAReturnsPage($query))
            ->whereNotNull('rfq_user.gm_assistant_completed_at')
            ->whereNull('rfq_user.gm_approved_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'gm_review')
            ->notHeldOnAReturnsPage()
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
     * How many RFQs are sitting on Business Development's Returns page —
     * sent back to them by Senior Operations' second review, the Head of
     * Business Development, or the General Manager (rejectToStage()/
     * rejectPartToStage() targeting 'business_development'), and not yet
     * fixed by them or carried past Senior Operations' review again. What the badge on
     * their sidebar's Returns link counts. Unlike the other queue counts
     * above, this isn't a per-part state — reject_target_stage lives on
     * the RFQ itself — so it's just a flat count.
     */
    public static function bdReturnsCount(): int
    {
        return static::where('status', 'Pending')->where('reject_target_stage', 'business_development')->count();
    }

    /**
     * How many RFQs are sitting on Senior Operations' Returns page — see
     * scopeReturnedToSeniorOperations(). What the badge on their sidebar's
     * Returns link counts. A flat count, same as bdReturnsCount().
     */
    public static function seniorOpsReturnsCount(): int
    {
        return static::returnedToSeniorOperations()->count();
    }

    /**
     * How many RFQs are sitting on the Head of Business Development's Returns
     * page — see scopeReturnedToHeadOfBd(). What the badge on their
     * sidebar's Returns link counts. A flat count, same as bdReturnsCount().
     */
    public static function headOfBdReturnsCount(): int
    {
        return static::returnedToHeadOfBd()->count();
    }

    /**
     * How many RFQs are sitting on GM Assistant's Returns page — see
     * scopeReturnedToGmAssistant(). What the badge on their sidebar's Returns
     * link counts. A flat count, same as bdReturnsCount().
     */
    public static function gmAssistantReturnsCount(): int
    {
        return static::returnedToGmAssistant()->count();
    }

    /**
     * The English ordinal for $n — "1st", "2nd", "3rd", "4th", … including
     * the 11th/12th/13th exception. Used on Business Development's Returns
     * page to flag a second, third, … time an RFQ has bounced back to them
     * (bd_return_count) differently from a first.
     */
    public static function ordinal(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 13) {
            return $n.'th';
        }

        return $n.match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    /**
     * How many parts are waiting on a Sourcing member's Finalize — Data Entry
     * has sent them to finalize (see RfqAssignment::isAwaitingFinalize()).
     * $userId narrows it to one member's own parts; null counts everyone's.
     * Counted with their pending parts, since both are theirs to act on.
     */
    public static function awaitingFinalizeCount(?int $userId = null): int
    {
        return DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->when($userId, fn ($query) => $query->where('rfq_user.user_id', $userId))
            ->whereNotNull('rfq_user.data_entry_completed_at')
            ->whereNull('rfq_user.finalized_at')
            ->count();
    }

    /**
     * How many items sit in each role's queue company-wide — what the
     * badges on Admin's grouped sidebar show. Mirrors what each role's own
     * sidebar badge counts (see layouts/app.blade.php), except Sourcing's,
     * which counts everyone's parts rather than one person's — pending ones
     * leaving out those sent back, which are the returns, and counting those
     * waiting on their Finalize.
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
            'bd_returns' => static::bdReturnsCount(),
            'unassigned' => static::where('status', 'Pending')->needingSourcing()->count(),
            'ops_review' => static::seniorOpsReviewCount(),
            'ops_returns' => static::seniorOpsReturnsCount(),
            'sourcing_pending' => $sourcingParts()->whereNull('rfq_user.returned_at')->count() + static::awaitingFinalizeCount(),
            'sourcing_returns' => $sourcingParts()->whereNotNull('rfq_user.returned_at')->count(),
            'data_entry' => DB::table('rfq_user')->whereNotNull('completed_at')->whereNull('data_entry_completed_at')->count(),
            'head_of_bd' => static::headOfBdReviewCount(),
            'head_of_bd_returns' => static::headOfBdReturnsCount(),
            'gm_assistant' => static::gmAssistantReviewCount(),
            'gm_assistant_returns' => static::gmAssistantReturnsCount(),
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
