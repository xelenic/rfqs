<?php

namespace App\Models;

use App\TaskMessages;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Rfq extends Model
{
    use HasFactory;

    /**
     * The selectable priority levels, in ascending order of urgency — what
     * rfqs.priority_level holds. Since 2026-10-21 seven, P7 Trivial to P1
     * Critical (PRIORITY_CODES); Urgent, High, Medium and Low as they were.
     *
     * @var array<int, string>
     */
    public const PRIORITIES = ['Trivial', 'Minimal', 'Low', 'Medium', 'High', 'Urgent', 'Critical'];

    /**
     * Each priority level's code, P1 the most pressing — shown with its name
     * ("P2 – Urgent", priorityLabel()).
     *
     * @var array<string, string>
     */
    public const PRIORITY_CODES = ['Critical' => 'P1', 'Urgent' => 'P2', 'High' => 'P3', 'Medium' => 'P4', 'Low' => 'P5', 'Minimal' => 'P6', 'Trivial' => 'P7'];

    /**
     * Each priority level's colour, as the business gave it — what the
     * priority picker shows, where a colour can't be drawn; everywhere else
     * it's the level's priority-chip-{level} colours (admin.css).
     *
     * @var array<string, string>
     */
    public const PRIORITY_SYMBOLS = ['Critical' => '🟣', 'Urgent' => '🔴', 'High' => '🟠', 'Medium' => '🔵', 'Low' => '🟢', 'Minimal' => '🟡', 'Trivial' => '⚪'];

    /**
     * The levels the dashboards count, and lead with, as urgent: P1 Critical
     * and P2 Urgent.
     *
     * @var array<int, string>
     */
    public const URGENT_PRIORITIES = ['Critical', 'Urgent'];

    /**
     * The selectable statuses. An RFQ starts Pending and is marked
     * Completed once it's been fulfilled.
     *
     * @var array<int, string>
     */
    public const STATUSES = ['Pending', 'Completed'];

    /**
     * Senior Operations can stop an open RFQ: on hold — paused, out of every
     * queue and its time not ticking, until it's resumed where it was — or
     * cancelled, out of the workflow, unless it's reopened. Set only through
     * changeStatus(), never the edit form.
     */
    public const ON_HOLD = 'On Hold';

    public const CANCELLED = 'Cancelled';

    /**
     * Every status an RFQ list can be filtered by — STATUSES and the two
     * Senior Operations sets (ON_HOLD, CANCELLED).
     *
     * @var array<int, string>
     */
    public const LIST_STATUSES = ['Pending', 'Completed', self::ON_HOLD, self::CANCELLED];

    /**
     * What All RFQs' Status filter (?state=, scopeInStatusFilter()) can
     * narrow it to, and what it calls each — where Senior Operations'
     * dashboard cards lead. Overdue and Due soon are the pending RFQs with a
     * task running past its target, or with under a quarter of it left
     * (DashboardController::targetStatesByRfq()).
     *
     * @var array<string, string>
     */
    public const STATUS_FILTERS = [
        'pending' => 'Pending',
        'overdue' => 'Overdue',
        'due_soon' => 'Due soon',
        'on_hold' => 'On Hold',
        'cancelled' => 'Cancelled',
        'closed' => 'Closed',
    ];

    /**
     * All RFQs' Status filter: STATUS_FILTERS, and Deadline exceeded — an RFQ
     * overdue now, or with a step its role finished late (deadlines()) —
     * just what the Time Spent report's tab of that name lists. Not a card on
     * Senior Operations' dashboard.
     *
     * @var array<string, string>
     */
    public const ALL_RFQS_STATUS_FILTERS = self::STATUS_FILTERS + [
        'exceeded' => 'Deadline exceeded',
    ];

    /**
     * Prefix, zero-padding width, and the sequence auto-generated RFQ
     * numbers count on from until an Admin says where they start
     * (Setting::rfqNumberStart()), e.g. "RFQ1001", "RFQ1002". See
     * nextRfqNumber().
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
     * queue for a role alongside its default one — the returns (the same
     * value, one page each, scoped by who's looking), every role's Rejected
     * page (likewise), Senior Operations' review, Business Development's
     * ready-to-close.
     *
     * @var array<int, string>
     */
    public const QUEUE_VIEWS = ['returns', 'rejected', 'review', 'closing'];

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
     * Operations' own assignment/split step and GM Assistant, to the General
     * Manager's own review, which only Business Development sends back to.
     * Which of these a given stage can actually use is RETURN_TARGETS'
     * call; this order is what a reject undoes — the target and everything
     * after it (markerColumnsFrom()).
     *
     * @var array<int, string>
     */
    public const REJECT_STAGE_ORDER = [
        'business_development', 'operations', 'sourcing', 'data_entry', 'senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review',
    ];

    /**
     * Who can send an RFQ — or one part of it — back to whom: each stage that
     * can, with the earlier stages it sends to, as the business has it. A
     * role never sends back to its own step, nor on to a later one.
     *
     * - Business Development, from Ready to Close: the Head of Business
     *   Development, the General Manager.
     * - Senior Operations, from their second review: Business Development,
     *   Sourcing, Data Entry.
     * - Sourcing, on a part still with them: Senior Operations — and, from
     *   Finalize, Data Entry (returnToDataEntry(), not a reject).
     * - Data Entry, on a part with them: Sourcing (returnSourcingPart(), not
     *   a reject).
     * - The Head of Business Development: Business Development, Senior
     *   Operations (its assignment or its review), Sourcing, Data Entry.
     * - GM Assistant: Senior Operations (its assignment or its review),
     *   Sourcing — not Data Entry.
     * - The General Manager: every stage before their own.
     *
     * Senior Operations' assignment step ('operations') frees the part for
     * them to assign again. See rejectTargetStages(), rejectToStage(),
     * rejectPartToStage().
     *
     * @var array<string, array<int, string>>
     */
    public const RETURN_TARGETS = [
        'sourcing' => ['operations'],
        'senior_ops_review' => ['business_development', 'sourcing', 'data_entry'],
        'head_of_bd_review' => ['business_development', 'operations', 'sourcing', 'data_entry', 'senior_ops_review'],
        'gm_assistant' => ['operations', 'sourcing', 'senior_ops_review'],
        'gm_review' => ['business_development', 'operations', 'sourcing', 'data_entry', 'senior_ops_review', 'head_of_bd_review', 'gm_assistant'],
        'bd_closing' => ['head_of_bd_review', 'gm_review'],
    ];

    /**
     * The review and closing stages that send an RFQ back with the shared
     * Reject & Return (RfqController::reject()) — every one in RETURN_TARGETS
     * but Sourcing's, who send their own part back
     * (RfqController::returnSeniorOps()).
     *
     * @var array<int, string>
     */
    public const REJECTABLE_STAGES = ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing'];

    /**
     * The reviews that can Return as well as Reject (since 2026-10-30, at the
     * user's request): the same send-back, to the same steps (RETURN_TARGETS),
     * but recorded as a Return (rfqs.reject_is_return, rfq_user.part_reject_is_return)
     * — so it lands on the receiving role's Returns page, not its Rejected
     * page (scopeRejectedByAReviewer(), RfqAssignment::isRejectedTo()).
     *
     * @var array<int, string>
     */
    public const RETURNABLE_STAGES = ['head_of_bd_review', 'gm_review'];

    /**
     * The workflow in order, every step that can be skipped when something
     * sent back is sent straight back to whoever sent it (forwardBack()) —
     * Sourcing's Finalize included, between Data Entry and Senior Operations'
     * review.
     *
     * @var array<int, string>
     */
    public const FORWARD_BACK_ORDER = [
        'business_development', 'operations', 'sourcing', 'data_entry', 'finalize', 'senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing',
    ];

    /**
     * How close together the same alert, sent again, still reads as the one
     * send on the timeline (sentAlerts()) — the same action submitted twice.
     */
    private const ALERT_REPEAT_SECONDS = 120;

    /**
     * Which role works each stage something can be sent back to — who gets
     * to send it straight back (forwardBack()).
     *
     * @var array<string, string>
     */
    /**
     * The columns of an RFQ, and of each of its parts (rfq_user), that name
     * someone who's had a hand in it — see peopleInvolved().
     *
     * @var array<string, array<int, string>>
     */
    private const PEOPLE_COLUMNS = [
        'rfqs' => [
            'created_by', 'operations_assigned_by', 'category_set_by', 'sourcing_completed_by',
            'data_entry_completed_by', 'finalized_by', 'senior_ops_reviewed_by', 'head_of_bd_approved_by',
            'head_of_bd_rejected_by', 'rejected_by', 'bd_fixed_by', 'gm_assistant_completed_by', 'gm_approved_by',
            'bd_closed_by', 'status_changed_by',
        ],
        'rfq_user' => [
            'user_id', 'returned_by', 'pending_reason_by', 'data_entry_assigned_to', 'data_entry_started_by', 'data_entry_completed_by',
            'finalized_by', 'senior_ops_reviewed_by', 'head_of_bd_approved_by', 'gm_assistant_started_by',
            'gm_assistant_completed_by', 'gm_approved_by', 'bd_closed_by', 'status_changed_by',
        ],
    ];

    public const RETURN_TARGET_ROLES = [
        'business_development' => 'Business Development',
        'operations' => 'Senior Operations',
        'sourcing' => 'Sourcing',
        'data_entry' => 'Data Entry',
        'senior_ops_review' => 'Senior Operations',
        'head_of_bd_review' => 'Head of Business Development',
        'gm_assistant' => 'GM Assistant',
        'gm_review' => 'General Manager',
    ];

    /**
     * The RFQ's own workflow columns a reject can change — saved with each
     * RfqReturn (rfq_before), for sending it straight back.
     *
     * @var array<int, string>
     */
    private const RETURN_RFQ_COLUMNS = [
        'stage', 'operations_assigned_by', 'operations_assigned_at', 'split_count',
        'sourcing_completed_by', 'sourcing_completed_at', 'data_entry_completed_by', 'data_entry_completed_at',
        'finalized_by', 'finalized_at', 'senior_ops_reviewed_by', 'senior_ops_reviewed_at',
        'head_of_bd_approved_by', 'head_of_bd_approved_at', 'gm_assistant_completed_by', 'gm_assistant_completed_at',
        'gm_approved_by', 'gm_approved_at',
        'rejected_by', 'rejected_at', 'reject_reason', 'reject_from_stage', 'reject_target_stage', 'reject_is_return',
    ];

    /**
     * A part's rfq_user columns sending it straight back leaves as they are
     * now: what it is, and whether it's been stopped on its own since.
     *
     * @var array<int, string>
     */
    private const RETURN_PART_KEPT_COLUMNS = ['id', 'rfq_id', 'user_id', 'part_number', 'created_at', 'updated_at', 'status', 'status_reason', 'status_changed_by', 'status_changed_at'];

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
     * activityTimeline() entry types that are Sourcing's and Data Entry's own
     * — all a Sourcing or Data Entry viewer sees of the lifecycle
     * (activityTimelineFor()): assigned to Sourcing, handed to Data Entry,
     * sent to finalize, sent back between them, finalized.
     *
     * @var array<int, string>
     */
    public const SOURCING_AND_DATA_ENTRY_TIMELINE_TYPES = [
        'sourcing_assigned', 'sourcing_completed', 'sourcing_returned', 'sourcing_handoff',
        'data_entry_part_completed', 'data_entry_all_completed', 'sourcing_finalized',
    ];

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
        'reject_is_return',
        'bd_return_count',
        'bd_fixed_at',
        'bd_fixed_by',
        'client_details',
        'payment_terms',
        'gm_assistant_completed_by',
        'gm_assistant_completed_at',
        'gm_approved_by',
        'gm_approved_at',
        'bd_closed_by',
        'bd_closed_at',
        'bd_reference_code',
        'wc_number',
        'rfq_number',
        'priority_level',
        'number_of_items',
        'status',
        'status_reason',
        'status_changed_by',
        'status_changed_at',
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
            'bd_fixed_at' => 'datetime',
            'number_of_items' => 'integer',
            'status_changed_at' => 'datetime',
            'senior_ops_reviewed_at' => 'datetime',
            'head_of_bd_approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'reject_is_return' => 'boolean',
            'gm_assistant_completed_at' => 'datetime',
            'gm_approved_at' => 'datetime',
            'bd_closed_at' => 'datetime',
        ];
    }

    /**
     * The stages $fromStage can send an RFQ back to — see RETURN_TARGETS.
     * Empty for a stage that can't send anything back.
     *
     * @return array<int, string>
     */
    public static function rejectTargetStages(string $fromStage): array
    {
        return self::RETURN_TARGETS[$fromStage] ?? [];
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
            'finalize' => 'Sourcing (finalize)',
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
     *
     * Since 2026-10-28, at the user's request, it goes back to Senior
     * Operations on their Returns page, not Unassigned: bd_fixed_at/by
     * (scopeReturnedToSeniorOperations()), till every part is assigned
     * again (assignSourcingParts()).
     */
    public function resolveBusinessDevelopmentReturn(User $fixedBy): void
    {
        if (! $this->isReturnedToBusinessDevelopment()) {
            return;
        }

        $this->update($this->clearedRejectRecord() + [
            'bd_fixed_at' => now(),
            'bd_fixed_by' => $fixedBy->id,
        ]);
    }

    /**
     * Whether $user can edit this RFQ's details — its numbers, priority,
     * subject and the rest (RfqController::update()): an Admin, any RFQ;
     * Senior Operations and Business Development, any not yet closed — one
     * sent back to Business Development to fix among them. Only an Admin
     * changes its status from there.
     */
    public function canBeEditedBy(User $user): bool
    {
        return match (true) {
            $user->hasRole('Admin') => true,
            $user->hasAnyRole(['Senior Operations', 'Business Development']) => $this->status !== 'Completed' && $this->isVisibleTo($user),
            default => false,
        };
    }

    /**
     * Whether $user may see this RFQ at all: anyone may, but for someone who
     * sees only the RFQs they created (User::seesOnlyOwnRfqs() — Business
     * Development), who may see their own — and any whose creator is no
     * longer on record (see scopeOwnedBy()) — and for someone who sees only
     * what Sourcing gave them (User::seesOnlyAssignedRfqs() — Data Entry),
     * who may see those (scopeVisibleTo()).
     */
    public function isVisibleTo(User $user): bool
    {
        return match (true) {
            $user->seesOnlyOwnRfqs() => $this->created_by === null || (int) $this->created_by === $user->id,
            $user->seesOnlyAssignedRfqs() => static::query()->whereKey($this->getKey())->visibleTo($user)->exists(),
            default => true,
        };
    }

    /**
     * The RFQs $user may see: every one, but for someone who sees only their
     * own (User::seesOnlyOwnRfqs() — Business Development: scopeOwnedBy()),
     * or only what Sourcing gave them (User::seesOnlyAssignedRfqs() — Data
     * Entry): an RFQ with a part assigned to them by name, one left with
     * anyone in Data Entry while it's with Data Entry (the shared queue — as
     * on their Ready list), or one they've worked on — started, sent to
     * finalize, or a round of theirs in its history (rfq_steps), however it's
     * gone since.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->seesOnlyOwnRfqs()) {
            $query->ownedBy($user->id);

            return;
        }

        if (! $user->seesOnlyAssignedRfqs()) {
            return;
        }

        $query->where(fn (Builder $theirs) => $theirs
            ->whereHas('assignees', fn (Builder $parts) => $parts->where(fn (Builder $part) => $part
                ->where('rfq_user.data_entry_assigned_to', $user->id)
                ->orWhere('rfq_user.data_entry_started_by', $user->id)
                ->orWhere('rfq_user.data_entry_completed_by', $user->id)
                ->orWhere(fn (Builder $forAnyone) => $forAnyone
                    ->whereNull('rfq_user.data_entry_assigned_to')
                    ->whereNull('rfq_user.status')
                    ->whereNotNull('rfq_user.completed_at')
                    ->whereNull('rfq_user.data_entry_completed_at'))))
            ->orWhereHas('steps', fn (Builder $rounds) => $rounds->where('step', 'data_entry')->where('worked_by', $user->id)));
    }

    /**
     * The RFQs for someone who sees only their own (User::ownRfqsOnlyId()):
     * the ones $userId created, and any whose creator is no longer on record
     * — their account deleted takes it off them (created_by nullOnDelete), and
     * those stay with everyone in Business Development rather than nobody.
     * Every RFQ when null, so a query is left as it is for everyone else.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwnedBy(Builder $query, ?int $userId): void
    {
        $query->when($userId !== null, fn (Builder $mine) => $mine->where(fn (Builder $theirs) => $theirs
            ->where('rfqs.created_by', $userId)
            ->orWhereNull('rfqs.created_by')));
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
     * The badge classes to pair with this RFQ's priority level: its colour —
     * 🟣 P1, 🔴 P2, 🟠 P3, 🔵 P4, 🟢 P5, 🟡 P6, ⚪ P7 (PRIORITY_SYMBOLS) — as a
     * tint led by a dot of it (.badge-priority, priority-chip-{level} in
     * admin.css).
     */
    public function priorityBadgeClass(): string
    {
        return in_array($this->priority_level, self::PRIORITIES, true)
            ? 'badge-priority priority-chip-'.strtolower($this->priority_level)
            : 'badge-soft-primary';
    }

    /**
     * This RFQ's priority with its code — "P2 – Urgent" (priorityLabelOf()).
     */
    public function priorityLabel(): string
    {
        return self::priorityLabelOf($this->priority_level);
    }

    /**
     * A priority level with its code — "P2 – Urgent" — or the level as it is,
     * if it isn't one of PRIORITY_CODES.
     */
    public static function priorityLabelOf(?string $priority): string
    {
        return isset(self::PRIORITY_CODES[$priority]) ? self::PRIORITY_CODES[$priority].' – '.$priority : (string) $priority;
    }

    /**
     * Whether this RFQ is one the dashboards count as urgent — P1 Critical or
     * P2 Urgent (URGENT_PRIORITIES).
     */
    public function isUrgent(): bool
    {
        return in_array($this->priority_level, self::URGENT_PRIORITIES, true);
    }

    /**
     * An SQL expression ordering $column's priority levels most pressing
     * first — P1 Critical 0 down to P7 Trivial 6, anything else last — for
     * orderByRaw().
     */
    public static function priorityOrderSql(string $column = 'priority_level'): string
    {
        $cases = collect(array_reverse(self::PRIORITIES))->map(fn (string $priority, int $rank) => "when '{$priority}' then {$rank}")->implode(' ');

        return "case {$column} {$cases} else ".count(self::PRIORITIES).' end';
    }

    /**
     * The badge class to pair with this RFQ's status.
     */
    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'Completed' => 'badge-soft-success',
            self::ON_HOLD => 'badge-soft-info',
            self::CANCELLED => 'badge-soft-secondary',
            default => 'badge-soft-warning',
        };
    }

    /**
     * Whether Senior Operations has stopped this RFQ — on hold or cancelled.
     */
    public function isStopped(): bool
    {
        return in_array($this->status, [self::ON_HOLD, self::CANCELLED], true);
    }

    /**
     * Senior Operations puts this RFQ on hold, cancels it, or — from either —
     * sets it going again (Pending), resuming exactly where it was: every
     * part's progress, and its stage, are left as they were; only the status
     * changes. A stop needs a reason. Either way it's recorded — who, when,
     * why, and for a hold whether it's waiting on the customer's
     * clarification ($awaitingCustomer) — and posted to the RFQ's thread, and
     * the step log follows: time
     * stops while it's stopped, and starts again when it's resumed, each
     * part's round carrying on rather than starting over (syncSteps()).
     *
     * Caller is responsible for verifying the change is allowed — see
     * RfqController::changeStatus().
     */
    public function changeStatus(string $status, User $by, ?string $reason = null, bool $awaitingCustomer = false): void
    {
        $action = match ($status) {
            self::ON_HOLD => 'put_on_hold',
            self::CANCELLED => 'cancelled',
            default => $this->status === self::CANCELLED ? 'reopened' : 'resumed',
        };

        $this->update([
            'status' => $status,
            'status_reason' => $status === 'Pending' ? null : $reason,
            'status_changed_by' => $by->id,
            'status_changed_at' => now(),
        ]);

        $this->postActionComment($by, $action, filled($reason) ? $reason : match ($action) {
            'reopened' => 'Reopened.',
            default => 'Resumed.',
        }, $this->customerClarificationMeta($status, $awaitingCustomer));

        $this->syncSteps(resuming: $status === 'Pending');
    }

    /**
     * What a hold's comment keeps to say it's waiting on the customer — for
     * clarification of what they asked for (App\PerformanceReports counts
     * these apart from the pending ones). Nothing for any other change.
     *
     * @return array{customer_clarification?: true}
     */
    private function customerClarificationMeta(string $status, bool $awaitingCustomer): array
    {
        return $status === self::ON_HOLD && $awaitingCustomer ? ['customer_clarification' => true] : [];
    }

    /**
     * Who last put it on hold, cancelled or resumed it.
     */
    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
    }

    /**
     * Senior Operations puts one part of a split on hold, cancels it, or —
     * from either — sets it going again (Pending), on its own: the rest of
     * the RFQ carries on. A part on hold is out of every queue, its time
     * stopped, just where it is until it's resumed — and the RFQ as a whole
     * doesn't move on past it meanwhile. A cancelled part is out of the RFQ:
     * nothing waits on it, so the RFQ moves on to wherever every other part
     * is through to already (catchUpWithLiveParts()); reopening it puts the
     * RFQ back to where that part leaves it (fallBackToLiveParts()). A stop
     * needs a reason. Either way it's recorded on the part and posted to the
     * RFQ's thread, naming the part.
     *
     * Caller is responsible for verifying the change is allowed — see
     * RfqController::changeStatus().
     */
    public function changePartStatus(int $part, string $status, User $by, ?string $reason = null, bool $awaitingCustomer = false): void
    {
        $assignee = $this->assigneeForPart($part);
        $wasCancelled = $assignee->pivot->isCancelled();

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'status' => $status === 'Pending' ? null : $status,
            'status_reason' => $status === 'Pending' ? null : $reason,
            'status_changed_by' => $by->id,
            'status_changed_at' => now(),
        ]);
        $this->load('assignees');

        $action = match ($status) {
            self::ON_HOLD => 'put_on_hold',
            self::CANCELLED => 'cancelled',
            default => $wasCancelled ? 'reopened' : 'resumed',
        };

        $this->postActionComment($by, $action, filled($reason) ? $reason : ($wasCancelled ? 'Reopened.' : 'Resumed.'), $this->partContext($part) + $this->customerClarificationMeta($status, $awaitingCustomer));

        if ($status === self::CANCELLED) {
            $this->catchUpWithLiveParts($by);
        } elseif ($wasCancelled) {
            $this->fallBackToLiveParts();
        }

        // Nothing on a Returns page waits on a stopped part either.
        if ($status !== 'Pending') {
            $this->resolveReturnOnceDealtWith('head_of_bd_review');
            $this->resolveReturnOnceDealtWith('gm_assistant');
        }

        $this->syncSteps(resuming: $status === 'Pending');
    }

    /**
     * Once a part's been cancelled, moves the RFQ on as far as its live parts
     * (liveParts()) are all through already — the cancelled part may have
     * been all it was waiting on, at any step. Each step is recorded as done
     * by whoever did the last of it on a live part, as if that had been the
     * one to finish it — or by $by, where nobody's on record.
     */
    private function catchUpWithLiveParts(User $by): void
    {
        $lastToDo = fn (string $at) => $this->liveParts()->sortBy(fn (User $assignee) => $assignee->pivot->{$at})->last();
        $whoDid = fn (string $at, string $byColumn): User => User::find($lastToDo($at)?->pivot->{$byColumn}) ?? $by;

        if ($this->allSourcingPartsCompleted() && ! $this->isWithDataEntry()) {
            $this->update(['sourcing_completed_by' => $lastToDo('completed_at')->id, 'sourcing_completed_at' => now()]);
        }

        if ($this->allDataEntryPartsCompleted() && $this->data_entry_completed_at === null) {
            $this->update(['data_entry_completed_by' => $whoDid('data_entry_completed_at', 'data_entry_completed_by')->id, 'data_entry_completed_at' => now()]);
        }

        if ($this->allPartsFinalized() && $this->stage === null) {
            $this->update(['finalized_by' => $lastToDo('finalized_at')->id, 'finalized_at' => now(), 'stage' => 'senior_ops_review']);
        }

        if ($this->stage === 'senior_ops_review' && $this->allSeniorOpsPartsReviewed()) {
            $this->completeSeniorOpsReview($whoDid('senior_ops_reviewed_at', 'senior_ops_reviewed_by'));
        }

        if ($this->stage === 'head_of_bd_review' && $this->allHeadOfBdPartsApproved()) {
            $this->approveByHeadOfBd($whoDid('head_of_bd_approved_at', 'head_of_bd_approved_by'));
        }

        if ($this->stage === 'gm_assistant' && $this->allGmAssistantPartsCompleted()) {
            $this->update([
                'gm_assistant_completed_by' => $whoDid('gm_assistant_completed_at', 'gm_assistant_completed_by')->id,
                'gm_assistant_completed_at' => now(),
                'stage' => 'gm_review',
            ]);
        }

        if ($this->stage === 'gm_review' && $this->allGmPartsApproved()) {
            $this->approveByGm($whoDid('gm_approved_at', 'gm_approved_by'));
        }

        if ($this->stage === 'bd_closing' && $this->allBdPartsClosed()) {
            $this->closeOut($whoDid('bd_closed_at', 'bd_closed_by'), $lastToDo('bd_closed_at')?->pivot->bd_reference_code);
        }
    }

    /**
     * Once a cancelled part's been reopened, puts the RFQ back to where its
     * live parts leave it: whatever that part isn't through, the RFQ as a
     * whole isn't either — its own record of each such step goes, and its
     * stage drops back to the furthest every part has reached
     * (stageEveryPartHasReached()).
     */
    private function fallBackToLiveParts(): void
    {
        $through = [
            'sourcing_completed' => $this->allSourcingPartsCompleted(),
            'data_entry_completed' => $this->allDataEntryPartsCompleted(),
            'finalized' => $this->allPartsFinalized(),
            'senior_ops_reviewed' => $this->allSeniorOpsPartsReviewed(),
            'head_of_bd_approved' => $this->allHeadOfBdPartsApproved(),
            'gm_assistant_completed' => $this->allGmAssistantPartsCompleted(),
            'gm_approved' => $this->allGmPartsApproved(),
        ];

        $columns = [];

        foreach ($through as $step => $isThrough) {
            if (! $isThrough) {
                $columns["{$step}_at"] = null;
                $columns["{$step}_by"] = null;
            }
        }

        $this->update($columns + ['stage' => $this->stageEveryPartHasReached()]);
    }

    /**
     * What this RFQ is grouped under on a list grouped by $group
     * (RfqController::LIST_GROUPS) — the PHP twin of
     * RfqController::listGroupKey(), so a row finds its group's total.
     */
    public function listGroupKey(string $group): string
    {
        return match ($group) {
            'wc' => strtoupper(trim((string) $this->wc_number)),
            'priority' => (string) $this->priority_level,
            default => (string) $this->status,
        };
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
     * Whether Business Development has closed it (status Completed, which
     * reads as "Closed" — see statusLabel()).
     */
    public function isClosed(): bool
    {
        return $this->status === 'Completed';
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
            ->withPivot(['part_number', 'completed_at', 'returned_at', 'return_reason', 'return_line_items', 'pending_reason', 'pending_reason_by', 'pending_reason_at', 'returned_by', 'part_rejected_to', 'part_rejected_from', 'part_rejected_by', 'part_rejected_at', 'part_reject_reason', 'part_reject_is_return', 'data_entry_completed_at', 'data_entry_completed_by', 'data_entry_entries', 'data_entry_started_at', 'data_entry_started_by', 'data_entry_paused_at', 'data_entry_pause_reason', 'data_entry_pause_reference', 'data_entry_assigned_to', 'line_items_to_add', 'finalized_at', 'finalized_by', 'submitted_line_items', 'data_entry_returned_at', 'data_entry_return_reason', 'senior_ops_reviewed_at', 'senior_ops_reviewed_by', 'verified_line_items', 'head_of_bd_approved_at', 'head_of_bd_approved_by', 'finalized_line_items', 'gm_assistant_started_at', 'gm_assistant_started_by', 'gm_assistant_paused_at', 'gm_assistant_pause_reason', 'gm_assistant_pause_reference', 'gm_assistant_completed_at', 'gm_assistant_completed_by', 'gm_approved_at', 'gm_approved_by', 'bd_closed_at', 'bd_closed_by', 'bd_reference_code', 'status', 'status_reason', 'status_changed_by', 'status_changed_at'])
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
    /**
     * The latest reason this RFQ was sent back with — its latest "rejected"
     * comment — for the photos and files it came with (RfqComment::
     * attachments()), shown with it on the Returns pages.
     */
    public function latestRejection(): HasOne
    {
        return $this->hasOne(RfqComment::class)->ofMany(['id' => 'max'], fn ($query) => $query->where('action', 'rejected'));
    }

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
     * Every part a reviewer has sent back, with what it was like before —
     * see RfqReturn and forwardBack().
     */
    public function returns(): HasMany
    {
        return $this->hasMany(RfqReturn::class);
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
     * Who in Business Development fixed this RFQ after it was sent back to
     * them — or an Admin, for them. See resolveBusinessDevelopmentReturn().
     */
    public function bdFixedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bd_fixed_by');
    }

    /**
     * The GM Assistant who submitted this RFQ to the General Manager. See
     * recordGmAssistantDetails() / recordGmAssistantPart().
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
     * $part's assignment while it's going ahead — null if the part's empty,
     * doesn't exist, or has been stopped on its own (changePartStatus()).
     * What every action on one part asks for.
     */
    private function activePart(int $part): ?RfqAssignment
    {
        $assignment = $this->assigneeForPart($part)?->pivot;

        return $assignment?->isStopped() === false ? $assignment : null;
    }

    /**
     * The parts still in this RFQ — every one assigned, less those cancelled
     * on their own (changePartStatus()). What every "every part is through"
     * check counts: nothing waits on a cancelled part. One on hold still
     * counts — the RFQ waits for it.
     *
     * @return Collection<int, User>
     */
    public function liveParts(): Collection
    {
        return $this->assignees->reject(fn (User $assignee) => $assignee->pivot->isCancelled());
    }

    /**
     * Whether every planned part is assigned and every live one
     * (liveParts()) passes $isThrough — false with none assigned at all.
     */
    private function everyLivePart(Closure $isThrough): bool
    {
        $liveParts = $this->liveParts();

        return $liveParts->isNotEmpty() && $this->allPartsAssigned() && $liveParts->every($isThrough);
    }

    /**
     * The parts on hold on their own, by number — what stops the RFQ being
     * acted on as a whole, which would move it on past them (see
     * RfqController::abortIfPartOnHold()).
     *
     * @return array<int, int>
     */
    public function partsOnHold(): array
    {
        return $this->assignees
            ->filter(fn (User $assignee) => $assignee->pivot->isOnHold())
            ->map(fn (User $assignee) => $assignee->pivot->part_number)
            ->sort()
            ->values()
            ->all();
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
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->completed_at !== null);
    }

    /**
     * Whether every Sourcing part has been completed by Data Entry — the
     * condition that formally closes the whole RFQ out. False for an RFQ
     * with no Sourcing assignees at all, and false while any planned part
     * is still unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allDataEntryPartsCompleted(): bool
    {
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->data_entry_completed_at !== null);
    }

    /**
     * Whether every Sourcing part has been finalized by its Sourcing member —
     * the condition that moves the RFQ as a whole on to Senior Operations'
     * review. Same conditions as allDataEntryPartsCompleted().
     */
    public function allPartsFinalized(): bool
    {
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->finalized_at !== null);
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
     * Mirrored in PHP by hasUnassignedParts(); Operations' "Unassigned"
     * queue is scopeAwaitingAssignment(). Every assignment is one part, so
     * counting them against split_count is enough.
     */
    public function scopeNeedingSourcing(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q
            ->doesntHave('assignees')
            ->orWhereRaw('rfqs.split_count > (select count(*) from rfq_user where rfq_user.rfq_id = rfqs.id)'));
    }

    /**
     * Senior Operations' Unassigned queue: Pending RFQs with a Sourcing part
     * nobody holds (scopeNeedingSourcing()) — but not one sent back to their
     * assignment step ('operations'), which is on their Returns or Rejected
     * page (scopeReturnedToSeniorOperations()), and only there, until it's
     * assigned again (since 2026-10-24); nor, since 2026-10-28, one sent back
     * to Business Development and still with them — it's theirs to fix first
     * (their Returns or Rejected page; Senior Operations' Get Details Again
     * also on Senior Operations' Returns, scopeWithBusinessDevelopmentForDetails()),
     * nor, since 2026-10-28, once they've fixed it (bd_fixed_at): it's back
     * on Senior Operations' Returns, till it's assigned again
     * (scopeReturnedToSeniorOperations()). All at the user's request.
     */
    public function scopeAwaitingAssignment(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->needingSourcing()->whereNull('rfqs.bd_fixed_at')->where(fn (Builder $q) => $q
            ->whereNull('rfqs.reject_target_stage')
            ->orWhereNotIn('rfqs.reject_target_stage', ['operations', 'business_development']));
    }

    /**
     * The RFQs Senior Operations sent back to Business Development for
     * details (Get Details Again — rejectToStage() from 'operations') and
     * still with them: on Senior Operations' Returns page, marked as with
     * Business Development, so they can follow them — not on Unassigned
     * (scopeAwaitingAssignment()). Once Business Development has fixed one,
     * it's on their Returns to assign (scopeReturnedToSeniorOperations()).
     */
    public function scopeWithBusinessDevelopmentForDetails(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')
            ->where('rfqs.reject_target_stage', 'business_development')
            ->where('rfqs.reject_from_stage', 'operations');
    }

    /**
     * RFQs Sourcing hasn't finalized yet: a part that isn't cancelled still
     * to finalize (rfq_user.finalized_at) — $memberId's own, when given — or,
     * asked of everyone, a planned part nobody holds yet (needingSourcing()).
     * The RFQ tracking sheet's "Not finalized yet", and All RFQs' "Not
     * finalized by Sourcing" (RfqController::ALL_RFQS_VIEWS).
     */
    public function scopeNotFinalizedBySourcing(Builder $query, ?int $memberId = null): void
    {
        $query->where(fn (Builder $rfqs) => $rfqs
            ->whereHas('assignees', fn (Builder $parts) => self::partsToFinalize($parts, $memberId))
            ->when($memberId === null, fn (Builder $unassigned) => $unassigned->orWhere(fn (Builder $open) => $open->needingSourcing())));
    }

    /**
     * The opposite, of those Sourcing has had: a part finalized — finalized
     * or held by $memberId, when given — and none left to finalize, nor,
     * asked of everyone, any planned part nobody holds.
     */
    public function scopeFinalizedBySourcing(Builder $query, ?int $memberId = null): void
    {
        $query->whereHas('assignees', fn (Builder $parts) => $parts->whereNotNull('rfq_user.finalized_at')
            ->when($memberId, fn (Builder $theirs, int $member) => $theirs->where(fn (Builder $by) => $by
                ->where('rfq_user.finalized_by', $member)
                ->orWhere('rfq_user.user_id', $member))))
            ->whereDoesntHave('assignees', fn (Builder $parts) => self::partsToFinalize($parts, $memberId))
            ->when($memberId === null, fn (Builder $all) => $all->fullySourced());
    }

    /**
     * Narrows rfq_user rows to the parts still to finalize: not finalized,
     * not cancelled — $memberId's, when given.
     *
     * @param  Builder<covariant Model>  $parts
     */
    private static function partsToFinalize(Builder $parts, ?int $memberId): void
    {
        $parts->whereNull('rfq_user.finalized_at')
            ->where(fn (Builder $live) => $live->whereNull('rfq_user.status')->orWhere('rfq_user.status', '!=', self::CANCELLED))
            ->when($memberId, fn (Builder $mine, int $member) => $mine->where('rfq_user.user_id', $member));
    }

    /**
     * The parts of this RFQ Sourcing still has to finalize: each planned
     * part nobody holds, and each held one not finalized nor cancelled — as
     * sourcingParts() gives them. What All RFQs' "Not finalized by Sourcing"
     * notes on each row.
     *
     * @return Collection<int, array{part: int, number: string, assignee: ?User}>
     */
    public function partsToFinalizeBySourcing(): Collection
    {
        return $this->sourcingParts()
            ->filter(fn (array $part) => $part['assignee'] === null
                || ($part['assignee']->pivot->finalized_at === null && ! $part['assignee']->pivot->isCancelled()))
            ->values();
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
        static::whereAwaitingAt($query, 'senior_ops_review', ['senior_ops_review'], RfqAssignment::whereAwaitingSeniorOpsReview(...));
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
        static::whereAwaitingAt($query, 'head_of_bd_review', ['senior_ops_review', 'head_of_bd_review'], RfqAssignment::whereAwaitingHeadOfBdReview(...));
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
        static::whereAwaitingAt($query, 'gm_assistant', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant'], RfqAssignment::whereAwaitingGmAssistant(...));
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
        static::whereAwaitingAt($query, 'gm_review', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review'], RfqAssignment::whereAwaitingGmApproval(...));
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
        static::whereAwaitingAt($query, 'bd_closing', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing'], RfqAssignment::whereAwaitingBdClosing(...));
    }

    /**
     * The condition behind each review or closing queue's scope above: an RFQ
     * still open with a part waiting at the step ($awaiting, an RfqAssignment
     * whereAwaiting…() check) and going ahead — on its way there (stage null
     * or one of $partStages) — listed part by part; or one at the step's own
     * $stage with no part waiting there at all, listed whole. A part stopped on
     * its own (changePartStatus()) isn't waiting on anyone — an RFQ whose only
     * parts waiting at the step are stopped isn't listed, part by part or
     * whole, until they're set going again.
     *
     * @param  Builder<self>  $query
     * @param  array<int, string>  $partStages
     */
    private static function whereAwaitingAt(Builder $query, string $stage, array $partStages, Closure $awaiting): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where(fn (Builder $whole) => $whole
                ->where('rfqs.stage', $stage)
                ->whereDoesntHave('assignees', fn (Builder $parts) => $awaiting($parts)))
            ->orWhere(fn (Builder $byPart) => $byPart
                ->where(fn (Builder $at) => $at->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', $partStages))
                ->whereHas('assignees', function (Builder $parts) use ($awaiting) {
                    RfqAssignment::whereActive($parts);
                    $awaiting($parts);
                })));
    }

    /**
     * What Senior Operations' On Hold or Cancelled page lists ($status): the
     * RFQs stopped whole (changeStatus()), and the ones still in progress
     * with a part stopped that way on its own (changePartStatus()).
     *
     * @param  Builder<self>  $query
     */
    public function scopeStoppedAs(Builder $query, string $status): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('rfqs.status', $status)
            ->orWhere(fn (Builder $withPart) => $withPart
                ->where('rfqs.status', 'Pending')
                ->whereHas('assignees', fn (Builder $parts) => $parts->where('rfq_user.status', $status))));
    }

    /**
     * The RFQs with one of ALL_RFQS_STATUS_FILTERS ($filter): pending, overdue
     * or due soon — pending, with $targetStates (DashboardController::
     * targetStatesByRfq(), by RFQ id) saying it's that — on hold or
     * cancelled, as their own pages list them (scopeStoppedAs()), closed, or
     * deadline exceeded: overdue now, or one of $finishedLate
     * (idsWithAStepFinishedLate()). All RFQs' Status filter and Senior
     * Operations' dashboard cards both count through here, so the two always
     * agree.
     *
     * @param  Builder<self>  $query
     * @param  Collection<int, array{state: string, tasks: array<int, string>}>  $targetStates
     * @param  array<int, int>  $finishedLate
     */
    public function scopeInStatusFilter(Builder $query, string $filter, Collection $targetStates, array $finishedLate = []): void
    {
        match ($filter) {
            'exceeded' => $query->whereIn('rfqs.id', [...$targetStates->where('state', 'overdue')->keys()->all(), ...$finishedLate]),
            'pending' => $query->where('rfqs.status', 'Pending'),
            'overdue', 'due_soon' => $query->where('rfqs.status', 'Pending')
                ->whereIn('rfqs.id', $targetStates->where('state', $filter)->keys()->all()),
            'on_hold' => $query->stoppedAs(self::ON_HOLD),
            'cancelled' => $query->stoppedAs(self::CANCELLED),
            'closed' => $query->where('rfqs.status', 'Completed'),
        };
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
     * clearedRejectRecord()). And, since 2026-10-28, one sent back to
     * Business Development that they've fixed (bd_fixed_at), back for
     * Senior Operations to assign — while a part is still unassigned, and
     * not while it's been sent back to Business Development again.
     */
    public function scopeReturnedToSeniorOperations(Builder $query): void
    {
        $query->where('rfqs.status', 'Pending')->where(fn (Builder $q) => $q
            ->where(fn (Builder $toAssignment) => $toAssignment
                ->where('rfqs.reject_target_stage', 'operations')
                ->needingSourcing())
            ->orWhere(fn (Builder $toReview) => $toReview
                ->where('rfqs.reject_target_stage', 'senior_ops_review')
                ->awaitingSeniorOpsReview())
            ->orWhere(fn (Builder $fixed) => $fixed
                ->whereNotNull('rfqs.bd_fixed_at')
                ->where(fn (Builder $notBackWithThem) => $notBackWithThem
                    ->whereNull('rfqs.reject_target_stage')
                    ->orWhere('rfqs.reject_target_stage', '<>', 'business_development'))
                ->needingSourcing()));
    }

    /**
     * Whether this RFQ is back from Business Development, fixed, for Senior
     * Operations to assign again (bd_fixed_at), with nothing sent back since.
     */
    public function isFixedByBusinessDevelopment(): bool
    {
        return $this->bd_fixed_at !== null && $this->reject_target_stage === null;
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
     * The RFQs sent back by a reviewer's Reject (reject_from_stage one of
     * REJECTABLE_STAGES, not a Return) — the receiving role's Rejected page
     * — or, with $rejected false, the rest: their Returns. That's a
     * co-worker's send-back (a Sourcing part sent back to Senior Operations,
     * Senior Operations' Get Details Again to Business Development) or, since
     * 2026-10-30, a reviewer's Return (reject_is_return, RETURNABLE_STAGES).
     */
    public function scopeRejectedByAReviewer(Builder $query, bool $rejected = true): void
    {
        $rejected
            ? $query->whereIn('rfqs.reject_from_stage', self::REJECTABLE_STAGES)->where('rfqs.reject_is_return', false)
            : $query->where(fn (Builder $q) => $q->whereNull('rfqs.reject_from_stage')
                ->orWhereNotIn('rfqs.reject_from_stage', self::REJECTABLE_STAGES)
                ->orWhere('rfqs.reject_is_return', true));
    }

    /**
     * Whether this RFQ's latest send-back was a reviewer's Return, not a
     * Reject (RETURNABLE_STAGES) — "Returned by …" rather than "Rejected by …".
     */
    public function wasReturnedNotRejected(): bool
    {
        return $this->reject_from_stage !== null && $this->reject_is_return;
    }

    /**
     * Narrows what's waiting on the General Manager (scopeAwaitingGmApproval())
     * to their Rejected page — an RFQ with a part Business Development
     * rejected back to them, waiting on them — or, with $rejected false, to
     * their Review: the rest, an RFQ with any other part waiting on them, or
     * none of its own (listed whole).
     */
    public function scopeRejectedToGm(Builder $query, bool $rejected = true): void
    {
        $waiting = fn (Builder $parts) => $parts->whereNull('rfq_user.status')->tap(fn ($active) => RfqAssignment::whereAwaitingGmApproval($active));

        $rejected
            ? $query->whereHas('assignees', fn (Builder $parts) => $waiting($parts)->tap(fn ($own) => RfqAssignment::whereRejectedTo($own, 'gm_review')))
            : $query->where(fn (Builder $q) => $q
                ->whereHas('assignees', fn (Builder $parts) => $waiting($parts)->tap(fn ($own) => RfqAssignment::whereNotRejectedTo($own, 'gm_review')))
                ->orWhereDoesntHave('assignees', $waiting));
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
     * Leaves out what's been sent back to Senior Operations' second review —
     * Returned or Rejected there by the Head of Business Development or the
     * General Manager: it's on their Returns or Rejected page
     * (scopeReturnedToSeniorOperations()), approved or sent on back from
     * there, and not on their Review page as well (since 2026-10-30, at the
     * user's request).
     */
    public function scopeNotSentBackToSeniorOpsReview(Builder $query): void
    {
        static::excludeSentBackToSeniorOpsReview($query);
    }

    /**
     * The condition behind scopeNotSentBackToSeniorOpsReview(), for
     * seniorOpsReviewCount()'s plain rfq_user query too.
     */
    private static function excludeSentBackToSeniorOpsReview(Builder|QueryBuilder $query): void
    {
        $query->where(fn ($q) => $q->whereNull('rfqs.reject_target_stage')->orWhere('rfqs.reject_target_stage', '<>', 'senior_ops_review'));
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
     * The Sourcing member who last held each part nobody holds now — part
     * number => User, by part — as the step log has it (RfqStep::assignee_id,
     * who held the part at the time): who to pass a part freed back to Senior
     * Operations straight back to. A part nobody's ever held, or whose member
     * no longer holds the Sourcing role, isn't in it.
     *
     * @return array<int, User>
     */
    public function previousHoldersOfOpenParts(): array
    {
        $openParts = $this->sourcingParts()->whereNull('assignee')->pluck('part');

        if ($openParts->isEmpty()) {
            return [];
        }

        // Oldest first, so each part ends up keyed to its latest holder.
        $lastHolderIds = RfqStep::query()
            ->where('rfq_id', $this->id)
            ->whereIn('part_number', $openParts)
            ->whereNotNull('assignee_id')
            ->oldest('started_at')
            ->oldest('id')
            ->get()
            ->keyBy('part_number')
            ->map(fn (RfqStep $stretch) => $stretch->assignee_id);

        $sourcingMembers = User::role('Sourcing')->whereKey($lastHolderIds->unique()->values())->get()->keyBy('id');

        return $lastHolderIds
            ->map(fn (int $userId) => $sourcingMembers->get($userId))
            ->filter()
            ->sortKeys()
            ->all();
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
    public function assignSourcingParts(array $userIdsByPart, ?User $by = null): void
    {
        $heldParts = $this->assignees->pluck('pivot.part_number');
        $total = $this->splitTotal();
        $given = [];

        foreach ($userIdsByPart as $part => $userId) {
            $part = (int) $part;

            if (! $userId || $part < 1 || $part > $total || $heldParts->contains($part)) {
                continue;
            }

            $this->assignees()->attach($userId, ['part_number' => $part]);
            $heldParts->push($part);
            $given[(int) $userId][] = $part;
        }

        $this->load('assignees');

        // Back from Business Development and every part assigned again: done
        // with on Senior Operations' Returns (scopeReturnedToSeniorOperations()).
        if ($this->bd_fixed_at !== null && $this->allPartsAssigned()) {
            $this->update(['bd_fixed_at' => null, 'bd_fixed_by' => null]);
        }

        $this->syncSteps();

        // Each member hears of the parts that are newly theirs, from whoever assigned them.
        foreach ($given as $userId => $parts) {
            TaskMessages::send($this, $by, [$this->assignees->firstWhere('id', $userId)],
                "Assigned you {$this->partsLabel($parts)} in Sourcing".($this->priority_level ? " ({$this->priorityLabel()} priority)" : ''));
        }
    }

    /**
     * Whether $part's Sourcing member can be replaced by another
     * (replaceSourcingPart()): someone holds it, it isn't closed or
     * cancelled, and the RFQ is open or on hold — at whatever step the part
     * has got to.
     */
    public function canReplaceSourcing(int $part): bool
    {
        $holder = $this->assigneeForPart($part)?->pivot;

        return in_array($this->status, ['Pending', self::ON_HOLD], true)
            && $holder !== null
            && $holder->bd_closed_at === null
            && ! $holder->isCancelled();
    }

    /**
     * Senior Operations replaces $part's Sourcing member with $to, wherever
     * the part has got to: it's theirs from now — on their lists, theirs to
     * finalize or rework, and theirs to send straight back if it's waiting
     * on that (RfqReturn) — and everything done on it so far stands. The time
     * the member who had it put in stays theirs; a Sourcing countdown starts
     * afresh with $to, while Data Entry's or GM Assistant's carries on as it
     * was. It's assigned to $to from now (rfq_user.created_at — their
     * "Assigned At"); the thread says who it went from and to (and why, if
     * given), naming the part, and keeps who had it and since when
     * (meta from_id, from_assigned_at), so the timeline still shows that
     * assignment (activityTimeline()). A no-op if nobody holds it, or $to
     * already does.
     *
     * Caller is responsible for verifying it's allowed (canReplaceSourcing()),
     * and that $to holds the Sourcing role.
     */
    public function replaceSourcingPart(int $part, User $to, User $by, ?string $reason = null): void
    {
        $from = $this->assigneeForPart($part);

        if (! $from || $from->is($to)) {
            return;
        }

        $openStretches = fn () => RfqStep::query()->where('rfq_id', $this->id)->where('part_number', $part)->whereNull('ended_at');

        // Their stretch at Sourcing's own steps ends here, credited to them;
        // anyone else's on the part goes on, the part's holder now $to.
        $openStretches()->whereIn('step', RfqStep::ROLE_STEPS['Sourcing'])->whereNull('worked_by')->update(['worked_by' => $from->id]);
        $openStretches()->whereNotIn('step', RfqStep::ROLE_STEPS['Sourcing'])->update(['assignee_id' => $to->id]);

        $fromAssignedAt = $from->pivot->created_at;

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->where('part_number', $part)
            ->update(self::CLEARED_PENDING_REASON + ['user_id' => $to->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->returns()->where('part_number', $part)->whereNull('forwarded_at')->where('user_id', $from->id)->update(['user_id' => $to->id]);

        $this->load('assignees');
        $this->unsetRelation('returns');

        $comment = $this->postActionComment($by, 'reassigned', trim("Sourcing replaced: from {$from->name} to {$to->name}. ".($reason ?? '')), $this->partContext($part) + [
            'from_id' => $from->id,
            'from_assigned_at' => $fromAssignedAt?->toIso8601String(),
        ]);

        $this->syncSteps();

        TaskMessages::send($this, $by, [$to], "Gave you {$this->partNumberLabel($part)} in Sourcing — it was {$from->name}'s", $reason, $comment);
        TaskMessages::send($this, $by, [$from], "Gave {$this->partNumberLabel($part)} to {$to->name} — it's no longer yours", $reason, $comment);
    }

    /**
     * Whether $user can say why $part is still pending: they're its Sourcing
     * member, the RFQ is Pending, and the part is on their Pending for
     * Sourcing list (RfqAssignment::isPendingWithSourcing()).
     */
    public function canGivePendingReason(User $user, int $part): bool
    {
        $assignee = $this->assigneeForPart($part);

        return $this->status === 'Pending'
            && $assignee?->is($user) === true
            && $user->hasRole('Sourcing')
            && $assignee->pivot->isPendingWithSourcing();
    }

    /**
     * $by says why $part is still pending with them ($reason): kept on the
     * part — the latest only — for hovering the RFQ's Pending status
     * (pendingReasons()), and posted to the RFQ's thread as theirs. Cleared
     * once the part moves on (CLEARED_PENDING_REASON). The same reason sent
     * again (a double click, a retry) is saved once. Returns whether it was
     * saved now.
     *
     * Caller is responsible for verifying it's allowed (canGivePendingReason()).
     */
    public function recordPendingReason(int $part, User $by, string $reason): bool
    {
        $assignee = $this->assigneeForPart($part);
        $reason = trim($reason);

        if (! $assignee?->pivot->isPendingWithSourcing()
            || ($assignee->pivot->pending_reason === $reason && $assignee->pivot->pending_reason_by === $by->id)) {
            return false;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'pending_reason' => $reason,
            'pending_reason_by' => $by->id,
            'pending_reason_at' => now(),
        ]);
        $this->load('assignees');

        $this->postActionComment($by, 'pending_reason', $reason, $this->partContext($part));

        return true;
    }

    /**
     * Why each of its parts still with Sourcing is pending, as last said
     * (RfqAssignment::pendingReason()), part by part: the part, its number,
     * the reason, who gave it and when. What hovering the RFQ's Pending
     * status shows (_pending_reason_badge).
     *
     * @return Collection<int, array{part: int, label: string, reason: string, by: ?User, at: ?CarbonInterface}>
     */
    public function pendingReasons(): Collection
    {
        return $this->assignees
            ->filter(fn (User $assignee) => $assignee->pivot->pendingReason() !== null)
            ->map(fn (User $assignee) => [
                'part' => $assignee->pivot->part_number,
                'label' => $this->partNumberLabel($assignee->pivot->part_number),
                'reason' => $assignee->pivot->pendingReason(),
                'by' => $assignee->pivot->pending_reason_by === $assignee->id ? $assignee : $assignee->pivot->pendingReasonBy,
                'at' => $assignee->pivot->pending_reason_at,
            ])
            ->values();
    }

    /**
     * Marks one Sourcing part done — assigned to Data Entry: to $dataEntry,
     * if they named someone there, else to anyone in Data Entry, whoever
     * starts it first (rfq_user.data_entry_assigned_to) — with how many line
     * items Data Entry has to add ($lineItems). Idempotent —
     * completing an already-completed part is a no-op. Once every part is
     * assigned and completed, the RFQ itself is marked handed off to Data
     * Entry, recording this part's assignee as the closing completion. A
     * $comment, if given, is posted to the RFQ's thread as the assignee's.
     * Returns whether it was completed now — false when it already had been,
     * so the same Assign to Data Entry sent twice (a double click, a retry)
     * is done, and told of, once.
     *
     * Caller is responsible for verifying the user completing it is the
     * part's assignee, and that $dataEntry holds Data Entry.
     */
    public function completeSourcingPart(int $part, ?string $comment = null, ?User $dataEntry = null, ?int $lineItems = null): bool
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee || $assignee->pivot->completed_at !== null || $assignee->pivot->isStopped()) {
            return false;
        }

        // Only while it's still not completed — a second request at the same
        // moment finds it done, and leaves it be.
        $completed = $this->assignees()->wherePivot('part_number', $part)->wherePivotNull('completed_at')->updateExistingPivot($assignee->id, self::CLEARED_PENDING_REASON + [
            'completed_at' => now(),
            'returned_at' => null,
            'return_reason' => null,
            'return_line_items' => null,
            'returned_by' => null,
            'data_entry_assigned_to' => $dataEntry?->id,
            'line_items_to_add' => $lineItems,
        ]);
        $this->load('assignees');

        if ($completed === 0) {
            return false;
        }

        // The completion comment goes into the RFQ's thread as the
        // assignee's own, so Data Entry reads it with everything else —
        // marked as a completion, naming the part on a split, since a thread
        // covers the whole RFQ, and whom in Data Entry it went to.
        $posted = filled($comment)
            ? $this->postActionComment($assignee, 'sourcing_completed', $comment, $this->partContext($part)
                + ($dataEntry ? ['assigned_to' => $dataEntry->name] : [])
                + ($lineItems !== null ? ['line_items' => $lineItems] : []))
            : null;

        if ($this->allSourcingPartsCompleted() && ! $this->isWithDataEntry()) {
            $this->update([
                'sourcing_completed_by' => $assignee->id,
                'sourcing_completed_at' => now(),
            ]);
        }

        $this->syncSteps();

        // Whom in Data Entry it's for hears of it — everyone there, when it's anyone's.
        $toAdd = $lineItems !== null ? " ({$lineItems} line items to add)" : '';
        TaskMessages::send($this, $assignee, $dataEntry !== null ? [$dataEntry] : User::query()->holdingRole('Data Entry')->get(),
            $dataEntry !== null
                ? "Assigned you {$this->partNumberLabel($part)} for Data Entry{$toAdd}"
                : "Assigned {$this->partNumberLabel($part)} to Data Entry — anyone there can start it{$toAdd}",
            $comment, $posted);

        return true;
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
     * $lineItemsAdded — how many line items Data Entry had added when they
     * sent it back (asked in their Return to Sourcing popup) — is kept with
     * the return, and with the comment; a reviewer's send-back has none.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function returnSourcingPart(int $part, string $reason, User $returnedBy, ?int $lineItemsAdded = null): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, self::CLEARED_PART_REJECTION + self::CLEARED_PENDING_REASON + [
            'completed_at' => null,
            'returned_at' => now(),
            'return_reason' => $reason,
            'return_line_items' => $lineItemsAdded,
            'returned_by' => $returnedBy->id,
            'data_entry_completed_at' => null,
            'data_entry_completed_by' => null,
            'data_entry_started_at' => null,
            'data_entry_started_by' => null,
            'data_entry_paused_at' => null,
            'data_entry_pause_reason' => null,
            'data_entry_pause_reference' => null,
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
        ] + self::GM_ASSISTANT_START_COLUMNS);
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

        $comment = $this->postActionComment($returnedBy, 'returned_to_sourcing', $reason, $this->partContext($part) + ['who' => $assignee->name]
            + ($lineItemsAdded !== null ? ['entries' => $lineItemsAdded] : []));

        $this->syncSteps();

        TaskMessages::send($this, $returnedBy, [$assignee], "Returned {$this->partNumberLabel($part)} to you in Sourcing"
            .($lineItemsAdded !== null ? ' ('.trans_choice('1 line item added|:count line items added', $lineItemsAdded, ['count' => number_format($lineItemsAdded)]).' so far)' : ''), $reason, $comment);
    }

    /**
     * Whether Data Entry can start on $part: it's with them — Sourcing has
     * assigned it to them, they haven't sent it to finalize — going ahead,
     * on an RFQ in progress, and not started yet. (Whether it's working
     * hours is the controller's call — see RfqController::startDataEntry().)
     */
    /**
     * Whether $user, in Data Entry, can take $part: it was assigned to them,
     * or to anyone in Data Entry (rfq_user.data_entry_assigned_to null).
     * Admin can take any.
     */
    public function partIsForDataEntryUser(int $part, User $user): bool
    {
        $assignedTo = $this->activePart($part)?->data_entry_assigned_to;

        return $user->hasRole('Admin') || $assignedTo === null || (int) $assignedTo === $user->id;
    }

    public function partAwaitsDataEntryStart(int $part): bool
    {
        $assignment = $this->activePart($part);

        return $this->status === 'Pending'
            && $assignment !== null
            && $assignment->completed_at !== null
            && $assignment->data_entry_completed_at === null
            && $assignment->data_entry_started_at === null;
    }

    /**
     * Data Entry starts on one part with them: from now, their time on it
     * counts (syncSteps() — see timedStepFor()), credited to $startedBy.
     * Idempotent — a part that isn't waiting to be started is left alone.
     */
    public function startDataEntryPart(int $part, User $startedBy): void
    {
        if (! $this->partAwaitsDataEntryStart($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'data_entry_started_at' => now(),
            'data_entry_started_by' => $startedBy->id,
        ]);
        $this->load('assignees');

        $this->syncSteps();
    }

    /**
     * Data Entry pauses $part, which they'd started — saying why ($reason),
     * and the document it's waiting on ($reference), if any: its time stops —
     * the countdown keeps what's left — and with nothing running they can
     * start another (RfqStep::runningDataEntryOf()). The reason goes on the
     * part while it's paused, and to the RFQ's thread as $pausedBy's.
     * Idempotent: a part that isn't running is left alone.
     */
    public function pauseDataEntryPart(int $part, User $pausedBy, string $reason, ?string $reference = null): void
    {
        if ($this->activePart($part)?->isDataEntryRunning() !== true) {
            return;
        }

        $reference = filled($reference) ? trim($reference) : null;

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'data_entry_paused_at' => now(),
            'data_entry_pause_reason' => trim($reason),
            'data_entry_pause_reference' => $reference,
        ]);

        $this->postActionComment($pausedBy, 'data_entry_paused', $reason, $this->partContext($part) + ($reference !== null ? ['reference' => $reference] : []));
        $this->syncSteps();
    }

    /**
     * Data Entry resumes $part, which they'd paused: its time counts again,
     * carrying on the same round — the countdown goes on from where it
     * stopped. Idempotent: a part that isn't paused is left alone. The caller
     * checks they've nothing else running.
     */
    public function resumeDataEntryPart(int $part): void
    {
        if ($this->activePart($part)?->isDataEntryPaused() !== true) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'data_entry_paused_at' => null,
            'data_entry_pause_reason' => null,
            'data_entry_pause_reference' => null,
        ]);

        $this->syncSteps(resuming: true);
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
     * given, is posted to the RFQ's thread as $completedBy's. $entries —
     * how many entries they made on it — is kept with the part, and named
     * on that comment.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function completeDataEntryPart(int $part, User $completedBy, ?string $comment = null, ?int $entries = null): void
    {
        $assignee = $this->assigneeForPart($part);

        if (! $assignee || $assignee->pivot->data_entry_completed_at !== null || $assignee->pivot->isStopped()) {
            return;
        }

        // Sending it to finalize again puts any return from its Sourcing
        // member (returnToDataEntry()) behind it.
        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, [
            'data_entry_completed_at' => now(),
            'data_entry_completed_by' => $completedBy->id,
            'data_entry_entries' => $entries,
            'data_entry_returned_at' => null,
            'data_entry_return_reason' => null,
        ]);
        $this->load('assignees');

        // Marked as Data Entry's completion, naming whose part and — on a
        // split — which, since a thread covers the whole RFQ. The line items
        // Sourcing gave them to add go with it when the count differs, so the
        // thread keeps the discrepancy (RfqAssignment::lineItemsDiscrepancy()).
        $posted = null;

        if (filled($comment)) {
            $toAdd = RfqAssignment::lineItemsDiscrepancyBetween($entries, $assignee->pivot->line_items_to_add) !== null ? ['to_add' => $assignee->pivot->line_items_to_add] : [];

            $posted = $this->postActionComment($completedBy, 'data_entry_completed', $comment, $this->partContext($part) + ['who' => $assignee->name] + ($entries !== null ? ['entries' => $entries] + $toAdd : []));
        }

        if ($this->allDataEntryPartsCompleted()) {
            $this->update([
                'data_entry_completed_by' => $completedBy->id,
                'data_entry_completed_at' => now(),
            ]);
        }

        $this->syncSteps();

        // Back with its Sourcing member, to finalize.
        TaskMessages::send($this, $completedBy, [$assignee],
            "Sent {$this->partNumberLabel($part)} to you to finalize".($entries !== null ? " ({$entries} line items added)" : ''), $comment, $posted);
    }

    /**
     * Whether $part is waiting on its Sourcing member's Finalize: the RFQ is
     * still open, and Data Entry has sent the part to finalize.
     */
    public function partAwaitsFinalize(int $part): bool
    {
        return $this->status === 'Pending'
            && $this->activePart($part)?->isAwaitingFinalize() === true;
    }

    /**
     * The Sourcing member finalizes one part Data Entry has sent to finalize
     * — on its own, and it goes straight on to Senior Operations' review,
     * without waiting for the rest of a split. Once every part has been
     * finalized the RFQ as a whole moves on to that review (stage
     * senior_ops_review). Recorded as the part's own assignee, whoever
     * clicks it (Admin can, on their behalf), with the line items they
     * submitted ($submittedLineItems — rfq_user.submitted_line_items, asked
     * in _finalize_modal). Idempotent — a part that isn't waiting on it is
     * left alone.
     *
     * Caller is responsible for verifying who's finalizing it.
     */
    public function finalizePart(int $part, ?int $submittedLineItems = null): void
    {
        if (! $this->partAwaitsFinalize($part)) {
            return;
        }

        $assignee = $this->assigneeForPart($part);

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, self::CLEARED_PENDING_REASON + [
            'finalized_at' => now(),
            'finalized_by' => $assignee->id,
            'submitted_line_items' => $submittedLineItems,
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
        // Whoever did its Data Entry — it's back with them.
        $dataEntry = $this->dataEntryPeopleOf(collect([$assignee]));

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($assignee->id, self::CLEARED_PART_REJECTION + self::CLEARED_PENDING_REASON + [
            'data_entry_completed_at' => null,
            'data_entry_completed_by' => null,
            'data_entry_started_at' => null,
            'data_entry_started_by' => null,
            'data_entry_paused_at' => null,
            'data_entry_pause_reason' => null,
            'data_entry_pause_reference' => null,
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

        $comment = $this->postActionComment($assignee, 'returned_to_data_entry', $reason, $this->partContext($part));

        $this->syncSteps();

        TaskMessages::send($this, $assignee, $dataEntry, "Returned {$this->partNumberLabel($part)} to you in Data Entry", $reason, $comment);
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
            && $this->activePart($part)?->isAwaitingSeniorOpsReview() === true;
    }

    /**
     * Whether every planned part has been approved by Senior Operations —
     * what lets the RFQ as a whole move on. False for an RFQ with no
     * Sourcing assignees at all, and false while any planned part is still
     * unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allSeniorOpsPartsReviewed(): bool
    {
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->isSeniorOpsApproved());
    }

    /**
     * Senior Operations approves one Sourcing part that has been through
     * Sourcing and Data Entry — on its own, without waiting for the rest of
     * a split. Once every part has been approved the RFQ as a whole moves
     * on, exactly as completeSeniorOpsReview() does. Idempotent — a part
     * that isn't waiting on review (not through Data Entry yet, or already
     * approved) is left alone. $verifiedLineItems — the line items Senior
     * Operations counted on it as they approved it — is kept with the part.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function approveSeniorOpsPart(int $part, User $reviewer, ?int $verifiedLineItems = null): void
    {
        if (! $this->partAwaitsSeniorOpsReview($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'senior_ops_reviewed_at' => now(),
            'senior_ops_reviewed_by' => $reviewer->id,
            'verified_line_items' => $verifiedLineItems,
        ]);
        $this->load('assignees');

        if ($this->allSeniorOpsPartsReviewed()) {
            $this->completeSeniorOpsReview($reviewer);

            return;
        }

        // What was sent back to their review, approved: off their Returns,
        // whatever else of it comes to their Review later.
        $this->resolveReturnOnceDealtWith('senior_ops_review');
    }

    /**
     * Senior Operations gives the second-stage review of the whole RFQ, after
     * Data Entry has finished every assignee's split — approving whatever
     * parts haven't been approved one by one along the way
     * (partsAwaitingSeniorOpsApproval()), each with the line items Senior
     * Operations counted on it ($verifiedLineItems, by part number).
     * Idempotent. Escalates the RFQ on to Head of Business Development.
     *
     * Caller is responsible for verifying stage === 'senior_ops_review'.
     *
     * @param  array<int, int>  $verifiedLineItems
     */
    public function completeSeniorOpsReview(User $reviewer, array $verifiedLineItems = []): void
    {
        if ($this->senior_ops_reviewed_at !== null) {
            return;
        }

        $this->load('assignees');

        foreach ($this->partsAwaitingSeniorOpsApproval() as $assignee) {
            DB::table('rfq_user')
                ->where('rfq_id', $this->id)
                ->where('part_number', $assignee->pivot->part_number)
                ->update([
                    'senior_ops_reviewed_at' => now(),
                    'senior_ops_reviewed_by' => $reviewer->id,
                    'verified_line_items' => $verifiedLineItems[$assignee->pivot->part_number] ?? null,
                ]);
        }
        $this->load('assignees');

        $this->update($this->clearedRejectRecord() + [
            'senior_ops_reviewed_by' => $reviewer->id,
            'senior_ops_reviewed_at' => now(),
            'stage' => 'head_of_bd_review',
        ]);
    }

    /**
     * The parts the Head of Business Development's approval of the whole RFQ
     * (approveByHeadOfBd()) approves: approved by Senior Operations, not yet
     * by them, and not stopped on their own.
     *
     * @return Collection<int, User>
     */
    public function partsAwaitingHeadOfBdApproval(): Collection
    {
        return $this->assignees
            ->filter(fn (User $assignee) => $assignee->pivot->senior_ops_reviewed_at !== null
                && $assignee->pivot->head_of_bd_approved_at === null
                && $assignee->pivot->status === null)
            ->sortBy('pivot.part_number')
            ->values();
    }

    /**
     * The parts Senior Operations' approval of the whole RFQ
     * (completeSeniorOpsReview()) approves: finalized, not yet approved, and
     * not stopped on their own.
     *
     * @return Collection<int, User>
     */
    public function partsAwaitingSeniorOpsApproval(): Collection
    {
        return $this->assignees
            ->filter(fn (User $assignee) => $assignee->pivot->finalized_at !== null
                && $assignee->pivot->senior_ops_reviewed_at === null
                && $assignee->pivot->status === null)
            ->sortBy('pivot.part_number')
            ->values();
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
            && $this->activePart($part)?->isAwaitingHeadOfBdReview() === true;
    }

    /**
     * Whether every planned part has been approved by the Head of Business
     * Development — what lets the RFQ as a whole move on. False for an RFQ
     * with no Sourcing assignees at all, and false while any planned part is
     * still unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allHeadOfBdPartsApproved(): bool
    {
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->isHeadOfBdApproved());
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
    public function approveHeadOfBdPart(int $part, User $approver, ?int $finalizedLineItems = null): void
    {
        if (! $this->partAwaitsHeadOfBdReview($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'head_of_bd_approved_at' => now(),
            'head_of_bd_approved_by' => $approver->id,
            'finalized_line_items' => $finalizedLineItems,
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
     * part not yet approved on its own (partsAwaitingHeadOfBdApproval()),
     * each with the line items they finalized on it ($finalizedLineItems, by
     * part number) — and escalates it on to GM Assistant. Idempotent.
     * Retires any prior rejection record, since an approval supersedes it.
     *
     * Caller is responsible for verifying stage === 'head_of_bd_review'.
     *
     * @param  array<int, int>  $finalizedLineItems
     */
    public function approveByHeadOfBd(User $approver, array $finalizedLineItems = []): void
    {
        if ($this->head_of_bd_approved_at !== null) {
            return;
        }

        $this->load('assignees');

        foreach ($this->partsAwaitingHeadOfBdApproval() as $assignee) {
            DB::table('rfq_user')
                ->where('rfq_id', $this->id)
                ->where('part_number', $assignee->pivot->part_number)
                ->update([
                    'head_of_bd_approved_at' => now(),
                    'head_of_bd_approved_by' => $approver->id,
                    'finalized_line_items' => $finalizedLineItems[$assignee->pivot->part_number] ?? null,
                ]);
        }
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
    /**
     * A part's Data Entry start (startDataEntryPart()), cleared — kept on the
     * part only (rfq_user), unlike the markers below, which the RFQ mirrors.
     * Cleared whenever the part comes back to Data Entry to be done again.
     *
     * @var array<string, null>
     */
    private const DATA_ENTRY_START_COLUMNS = [
        'data_entry_started_at' => null, 'data_entry_started_by' => null,
        'data_entry_paused_at' => null, 'data_entry_pause_reason' => null, 'data_entry_pause_reference' => null,
    ];

    /**
     * A part's GM Assistant start (startGmAssistantPart()) and pause,
     * cleared — kept on the part only (rfq_user). Cleared whenever the part
     * is sent back to GM Assistant's step or before it, so it's started
     * afresh when it comes back to them.
     *
     * @var array<string, null>
     */
    private const GM_ASSISTANT_START_COLUMNS = [
        'gm_assistant_started_at' => null, 'gm_assistant_started_by' => null,
        'gm_assistant_paused_at' => null, 'gm_assistant_pause_reason' => null, 'gm_assistant_pause_reference' => null,
    ];

    private const REJECT_MARKER_COLUMNS = [
        // Redoing Data Entry means finalizing it again too.
        'data_entry' => ['data_entry_completed_at', 'data_entry_completed_by', 'finalized_at', 'finalized_by'],
        'senior_ops_review' => ['senior_ops_reviewed_at', 'senior_ops_reviewed_by'],
        'head_of_bd_review' => ['head_of_bd_approved_at', 'head_of_bd_approved_by'],
        'gm_assistant' => ['gm_assistant_completed_at', 'gm_assistant_completed_by'],
        'gm_review' => ['gm_approved_at', 'gm_approved_by'],
    ];

    /**
     * The rfq_user start columns a reject to $targetStage clears, as well as
     * its markers: Data Entry's start when it's sent back to Data Entry
     * (DATA_ENTRY_START_COLUMNS), and GM Assistant's when it's sent back to
     * their step or before it (GM_ASSISTANT_START_COLUMNS).
     *
     * @return array<string, null>
     */
    private static function startColumnsClearedBy(string $targetStage): array
    {
        $order = array_flip(self::REJECT_STAGE_ORDER);

        return ($targetStage === 'data_entry' ? self::DATA_ENTRY_START_COLUMNS : [])
            + (($order[$targetStage] ?? PHP_INT_MAX) <= $order['gm_assistant'] ? self::GM_ASSISTANT_START_COLUMNS : []);
    }

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
     * @return array<string, null|false>
     */
    private function clearedRejectRecord(): array
    {
        return [
            'rejected_by' => null,
            'rejected_at' => null,
            'reject_reason' => null,
            'reject_from_stage' => null,
            'reject_target_stage' => null,
            'reject_is_return' => false,
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
     * left as it is throughout; so is one cancelled on its own
     * (changePartStatus()), unless the assignment itself is being redone —
     * then it's freed with the rest. Also posts a comment recording the
     * rejection, same convention as returnSourcingPart().
     *
     * $asReturn — a Return rather than a Reject (RETURNABLE_STAGES): the
     * same send-back, recorded as one (reject_is_return), so it's on the
     * receiving role's Returns page rather than its Rejected page.
     *
     * Caller is responsible for verifying $fromStage is one of
     * REJECTABLE_STAGES (RETURNABLE_STAGES for a Return), stage ===
     * $fromStage, and $targetStage is one of rejectTargetStages($fromStage).
     */
    public function rejectToStage(string $targetStage, string $reason, User $rejectedBy, string $fromStage = 'head_of_bd_review', bool $asReturn = false): void
    {
        // Who it's going back to, as they are before it goes — Sourcing hear
        // of it part by part (returnSourcingPart()).
        $tell = $targetStage === 'sourcing' ? collect() : $this->peopleAtStage($targetStage);

        $this->recordReturn($fromStage, $targetStage, $rejectedBy);

        $columns = array_fill_keys(self::markerColumnsFrom($targetStage), null);

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNull('bd_closed_at')
            ->where(fn ($parts) => $parts->whereNull('status')->orWhere('status', '<>', self::CANCELLED))
            ->update($columns + self::startColumnsClearedBy($targetStage));
        $this->load('assignees');

        $this->update($columns + [
            'rejected_by' => $rejectedBy->id,
            'rejected_at' => now(),
            'reject_reason' => $reason,
            'reject_from_stage' => $fromStage,
            'reject_target_stage' => $targetStage,
            'reject_is_return' => $asReturn,
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
            foreach ($openParts->reject(fn (User $assignee) => $assignee->pivot->isCancelled()) as $assignee) {
                $this->returnSourcingPart($assignee->pivot->part_number, $reason, $rejectedBy);
            }
        }

        $this->recordPartRejections(
            $openParts->reject(fn (User $assignee) => $assignee->pivot->isCancelled())->map(fn (User $assignee) => $assignee->pivot->part_number)->all(),
            $targetStage, $fromStage, $rejectedBy, $reason, $asReturn,
        );

        $comment = $this->postActionComment($rejectedBy, 'rejected', $reason, ['stage' => self::stageLabel($targetStage)] + ($asReturn ? ['returned' => true] : []));

        $this->syncSteps();

        TaskMessages::send($this, $rejectedBy, $tell, "Sent {$this->rfq_number} back to you — ".self::stageLabel($targetStage), $reason, $comment);
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
     * $asReturn — a Return rather than a Reject, as for rejectToStage().
     *
     * Caller is responsible for verifying $fromStage is one of
     * REJECTABLE_STAGES (RETURNABLE_STAGES for a Return), the part actually
     * awaits $fromStage's review, and $targetStage is one of
     * rejectTargetStages($fromStage).
     *
     * 'business_development' behaves the same as 'operations' here — just
     * this part is freed. Unlike the whole-RFQ rejectToStage(), it doesn't
     * clear who in Operations is routing the RFQ: that's a whole-RFQ fact,
     * and any other part of a split may still legitimately be theirs.
     */
    public function rejectPartToStage(int $part, string $targetStage, string $reason, User $rejectedBy, string $fromStage = 'head_of_bd_review', bool $asReturn = false): void
    {
        // Who it's going back to, as they are before it goes — Sourcing hear
        // of it from returnSourcingPart().
        $tell = $targetStage === 'sourcing' ? collect() : $this->peopleAtStage($targetStage, $part);

        if ($this->assigneeForPart($part)) {
            $this->recordReturn($fromStage, $targetStage, $rejectedBy, $part);
        }

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
                array_fill_keys(self::markerColumnsFrom($targetStage), null) + self::startColumnsClearedBy($targetStage)
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
            'reject_is_return' => $asReturn,
            'stage' => $this->stageEveryPartHasReached(),
        ]);

        $this->recordPartRejections([$part], $targetStage, $fromStage, $rejectedBy, $reason, $asReturn);

        $comment = $this->postActionComment($rejectedBy, 'rejected', $reason, ['stage' => self::stageLabel($targetStage)] + $this->partContext($part) + ($asReturn ? ['returned' => true] : []));

        $this->syncSteps();

        TaskMessages::send($this, $rejectedBy, $tell, "Sent {$this->partNumberLabel($part)} back to you — ".self::stageLabel($targetStage), $reason, $comment);
    }

    /**
     * The part's own rejection record cleared (part_rejected_*) — merged into
     * a co-worker's send-back of it (returnSourcingPart(), returnToDataEntry()),
     * which isn't a rejection: it goes on the step's Returns, or its queue,
     * not its Rejected page.
     *
     * @var array<string, null|false>
     */
    private const CLEARED_PART_REJECTION = [
        'part_rejected_to' => null,
        'part_rejected_from' => null,
        'part_rejected_by' => null,
        'part_rejected_at' => null,
        'part_reject_reason' => null,
        'part_reject_is_return' => false,
    ];

    /**
     * A part's pending reason cleared, once it's off its Sourcing member's
     * Pending list — assigned to Data Entry, finalized, returned to Data
     * Entry, sent back to Sourcing, or given to someone else — so it's said
     * afresh the next time it's pending (recordPendingReason()).
     *
     * @var array<string, null>
     */
    private const CLEARED_PENDING_REASON = [
        'pending_reason' => null,
        'pending_reason_by' => null,
        'pending_reason_at' => null,
    ];

    /**
     * Records on $parts that a reviewer's Reject & Return ($fromStage, one of
     * REJECTABLE_STAGES) sent them back to $targetStage — where to, from
     * which review, by whom, when and why (part_rejected_*): what lists each
     * on that step's Rejected page while it's waiting there — or, a Return
     * ($asReturn, part_reject_is_return), on its Returns. Each part keeps
     * its own, as a split's parts can each be rejected to a different step.
     * Nothing for anyone else's send-back (Senior Operations' Get Details
     * Again, a Sourcing part sent back to them), nor for a part that's been
     * freed.
     *
     * @param  array<int, int>  $parts
     */
    private function recordPartRejections(array $parts, string $targetStage, string $fromStage, User $rejectedBy, string $reason, bool $asReturn = false): void
    {
        if ($parts === [] || ! in_array($fromStage, self::REJECTABLE_STAGES, true)) {
            return;
        }

        DB::table('rfq_user')->where('rfq_id', $this->id)->whereIn('part_number', $parts)->update([
            'part_rejected_to' => $targetStage,
            'part_rejected_from' => $fromStage,
            'part_rejected_by' => $rejectedBy->id,
            'part_rejected_at' => now(),
            'part_reject_reason' => $reason,
            'part_reject_is_return' => $asReturn,
        ]);
        $this->load('assignees');
    }

    /**
     * Saves what's about to be sent back from $fromStage to $targetStage —
     * $part, or every part not yet closed — as it is now, with the RFQ's own
     * workflow columns: one RfqReturn per part, a whole-RFQ reject's sharing
     * a batch. What forwardBack() restores.
     */
    private function recordReturn(string $fromStage, string $targetStage, User $returnedBy, ?int $part = null): void
    {
        $rfqBefore = (array) DB::table('rfqs')->where('id', $this->id)->first(self::RETURN_RFQ_COLUMNS);
        $batch = (string) Str::uuid();

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->when($part !== null, fn ($parts) => $parts->where('part_number', $part))
            ->whereNull('bd_closed_at')
            ->get()
            ->each(fn (object $row) => $this->returns()->create([
                'batch' => $batch,
                'part_number' => $row->part_number,
                'user_id' => $row->user_id,
                'whole' => $part === null,
                'from_stage' => $fromStage,
                'target_stage' => $targetStage,
                'returned_by' => $returnedBy->id,
                'part_before' => collect((array) $row)->except(['id', 'rfq_id', 'user_id', 'part_number'])->all(),
                'rfq_before' => $rfqBefore,
            ]));

        $this->unsetRelation('returns');
    }

    /**
     * The steps strictly between $from and $to in the workflow
     * (FORWARD_BACK_ORDER) — what sending something sent back to $from
     * straight back to $to skips. Nothing when they're next to each other.
     *
     * @return array<int, string>
     */
    public static function stagesBetween(string $from, string $to): array
    {
        $start = array_search($from, self::FORWARD_BACK_ORDER, true);
        $end = array_search($to, self::FORWARD_BACK_ORDER, true);

        return $start === false || $end === false || $end <= $start + 1
            ? []
            : array_slice(self::FORWARD_BACK_ORDER, $start + 1, $end - $start - 1);
    }

    /**
     * What sent back can be sent straight back right now, by part: each
     * part's latest return (RfqReturn) not sent straight back already — once
     * one has been, the part's back where the one before it left it, so
     * that one's next — that would skip a step, with the part still just
     * where it was sent: nobody's moved it on, stopped it, or given it to
     * someone else since. Empty unless the RFQ is Pending.
     *
     * @return Collection<int, RfqReturn> keyed by part number
     */
    public function openReturns(): Collection
    {
        if ($this->status !== 'Pending') {
            return collect();
        }

        return $this->returns
            ->sortByDesc('id')
            ->groupBy('part_number')
            ->map(fn (Collection $partReturns) => $partReturns->first(fn (RfqReturn $return) => $return->forwarded_at === null))
            ->filter(fn (?RfqReturn $return) => $return !== null
                && $return->skippedStages() !== []
                && $this->isWhereReturnLeftIt($return));
    }

    /**
     * $part's return that can be sent straight back now, if it has one —
     * see openReturns().
     */
    public function openReturnFor(int $part): ?RfqReturn
    {
        return $this->openReturns()->get($part);
    }

    /**
     * The returns that can be sent straight back now to $target, grouped by
     * where they came from — for a whole-RFQ row on a Returns page.
     *
     * @return Collection<string, Collection<int, RfqReturn>>
     */
    public function openReturnsTo(string $target): Collection
    {
        return $this->openReturns()->where('target_stage', $target)->groupBy('from_stage');
    }

    /**
     * Whether $return's part is still where it was sent: freed, for the
     * assignment or Business Development, with the RFQ still sitting there;
     * otherwise held by the same person, going ahead, and waiting at that
     * stage.
     */
    private function isWhereReturnLeftIt(RfqReturn $return): bool
    {
        $holder = $this->assigneeForPart($return->part_number);

        if (in_array($return->target_stage, ['business_development', 'operations'], true)) {
            return $holder === null && $return->user_id !== null && $this->reject_target_stage === $return->target_stage;
        }

        $part = $holder?->pivot;

        if ($part === null || $holder->id !== $return->user_id || $part->isStopped()) {
            return false;
        }

        return match ($return->target_stage) {
            'sourcing' => $part->completed_at === null,
            'data_entry' => $part->completed_at !== null && $part->data_entry_completed_at === null,
            'senior_ops_review' => $part->finalized_at !== null && $part->senior_ops_reviewed_at === null,
            'head_of_bd_review' => $part->senior_ops_reviewed_at !== null && $part->head_of_bd_approved_at === null,
            'gm_assistant' => $part->head_of_bd_approved_at !== null && $part->gm_assistant_completed_at === null,
            'gm_review' => $part->gm_assistant_completed_at !== null && $part->gm_approved_at === null,
            default => false,
        };
    }

    /**
     * Sends what $returns sent back (openReturns()) straight back to whoever
     * sent it, skipping the steps in between: each part is put back just as
     * it was before it was sent back — the work done on it since, at the
     * steps skipped, standing — and held by whoever had it then. Once a
     * whole-RFQ reject's every part is back, so is the RFQ, as it was;
     * until then — or for a part sent back on its own — the RFQ moves to
     * wherever its parts now leave it, its own records only standing while
     * every part is through. Recorded as $by's, and posted to the thread.
     *
     * Caller is responsible for verifying $by may, and that $returns are open.
     *
     * @param  Collection<int, RfqReturn>  $returns
     */
    public function forwardBack(Collection $returns, User $by): void
    {
        foreach ($returns as $return) {
            $columns = $return->part_before;

            if ($this->assigneeForPart($return->part_number)) {
                DB::table('rfq_user')
                    ->where('rfq_id', $this->id)
                    ->where('part_number', $return->part_number)
                    ->update(collect($columns)->except(self::RETURN_PART_KEPT_COLUMNS)->all() + ['updated_at' => now()]);
            } else {
                DB::table('rfq_user')->insert($columns + [
                    'rfq_id' => $this->id,
                    'user_id' => $return->user_id,
                    'part_number' => $return->part_number,
                    'updated_at' => now(),
                ]);
            }

            $return->update(['forwarded_at' => now(), 'forwarded_by' => $by->id]);
            $this->load('assignees');
        }

        $first = $returns->first();
        $before = $first->rfq_before;
        $batchLeft = $this->returns()->where('batch', $first->batch)->whereNull('forwarded_at')->exists();
        $isItsReject = $this->reject_from_stage === $first->from_stage && $this->reject_target_stage === $first->target_stage;

        if ($first->whole && ! $batchLeft) {
            $this->update($before);
        } else {
            $this->update($this->recordsEveryPartStandsBy($before) + ['stage' => $this->stageEveryPartHasReached()]
                + ($isItsReject && ! $batchLeft ? collect($before)->only(['rejected_by', 'rejected_at', 'reject_reason', 'reject_from_stage', 'reject_target_stage', 'reject_is_return'])->all() : []));
        }

        $skipped = collect($first->skippedStages())->map(fn (string $stage) => self::stageLabel($stage))->join(', ', ' and ');
        $context = $returns->count() === 1 && $this->isSplit() ? $this->partContext($first->part_number) : [];

        $comment = $this->postActionComment($by, 'forwarded_back', 'Sent straight back to '.self::stageLabel($first->from_stage)." — skipping {$skipped}.", ['stage' => self::stageLabel($first->from_stage)] + $context);

        $this->unsetRelation('returns');
        $this->syncSteps();

        // Back with whoever sent it back.
        $part = $returns->count() === 1 ? $first->part_number : null;
        TaskMessages::send($this, $by, $this->peopleAtStage($first->from_stage, $part),
            'Sent '.($part !== null ? $this->partNumberLabel($part) : $this->rfq_number).' straight back to you — '.self::stageLabel($first->from_stage), "Skipped {$skipped}.", $comment);
    }

    /**
     * The RFQ's own step records as its parts now stand: each one kept — or,
     * if a reject cleared it, back from $before — only while every part is
     * through that step; cleared while one isn't.
     *
     * @param  array<string, mixed>  $before  RfqReturn::rfq_before
     * @return array<string, mixed>
     */
    private function recordsEveryPartStandsBy(array $before): array
    {
        $records = [
            'sourcing_completed' => $this->allSourcingPartsCompleted(),
            'data_entry_completed' => $this->allDataEntryPartsCompleted(),
            'finalized' => $this->allPartsFinalized(),
            'senior_ops_reviewed' => $this->allSeniorOpsPartsReviewed(),
            'head_of_bd_approved' => $this->allHeadOfBdPartsApproved(),
            'gm_assistant_completed' => $this->allGmAssistantPartsCompleted(),
            'gm_approved' => $this->allGmPartsApproved(),
        ];

        return collect($records)->flatMap(fn (bool $everyPartThrough, string $record) => [
            "{$record}_by" => $everyPartThrough ? ($this->getRawOriginal("{$record}_by") ?? $before["{$record}_by"] ?? null) : null,
            "{$record}_at" => $everyPartThrough ? ($this->getRawOriginal("{$record}_at") ?? $before["{$record}_at"] ?? null) : null,
        ])->all();
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
     * is left alone. $resuming: the RFQ's just been set going again after a
     * stop, so a part back at the step it was stopped at carries on that
     * round (RfqStep::resumed) — and once its role finishes it, whoever's
     * credited is credited with the stretches before the stop too.
     */
    public function syncSteps(bool $resuming = false): void
    {
        $this->load('assignees');

        $current = $this->status === 'Pending'
            ? $this->assignees
                ->mapWithKeys(fn (User $assignee) => [$assignee->pivot->part_number => [
                    'assignee_id' => $assignee->id,
                    'step' => $this->timedStepFor($assignee->pivot),
                    'started_by' => $assignee->pivot->data_entry_started_by,
                    'gm_started_by' => $assignee->pivot->gm_assistant_started_by,
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

                if ($endedByRole && $open->resumed) {
                    $this->creditRoundBefore($open);
                }
            }
        }

        foreach ($current as $part => $now) {
            $last = $resuming
                ? RfqStep::query()->where('rfq_id', $this->id)->where('part_number', $part)->latest('started_at')->latest('id')->first()
                : null;

            RfqStep::query()->create([
                'rfq_id' => $this->id,
                'part_number' => $part,
                'assignee_id' => $now['assignee_id'],
                // Sourcing's own time is the part's member's from the start,
                // Data Entry's whoever started it (startDataEntryPart()), and
                // GM Assistant's likewise (startGmAssistantPart()).
                'worked_by' => match ($now['step']) {
                    'sourcing', 'finalize' => $now['assignee_id'],
                    'data_entry' => $now['started_by'],
                    'gm_assistant' => $now['gm_started_by'],
                    default => null,
                },
                'step' => $now['step'],
                'started_at' => now(),
                'resumed' => $last !== null && $last->step === $now['step'] && $last->assignee_id === $now['assignee_id'],
            ]);
        }
    }

    /**
     * Credits the stretches of $finished's round from before it was stopped
     * — nobody's while they were waiting on it — to whoever's credited with
     * $finished.
     */
    private function creditRoundBefore(RfqStep $finished): void
    {
        $earlier = RfqStep::query()
            ->where('rfq_id', $this->id)
            ->where('part_number', $finished->part_number)
            ->where('step', $finished->step)
            ->whereKeyNot($finished->id)
            ->where('started_at', '<=', $finished->started_at)
            ->latest('started_at')->latest('id')
            ->get();

        $stretch = $finished;

        foreach ($earlier as $before) {
            if (! $stretch->resumed) {
                break;
            }

            if ($before->worked_by === null) {
                $before->update(['worked_by' => $finished->worked_by]);
            }

            $stretch = $before;
        }
    }

    /**
     * $stretches — of one step or several, one part or several — grouped
     * into rounds, oldest first: one resumed after a stop (RfqStep::resumed)
     * carries on its part's round at that step rather than starting one.
     * Expects them oldest first, as steps() has them.
     *
     * @param  Collection<int, RfqStep>  $stretches
     * @return Collection<int, Collection<int, RfqStep>>
     */
    public static function roundsOf(Collection $stretches): Collection
    {
        $rounds = collect();
        $latestRound = [];

        foreach ($stretches as $stretch) {
            $key = $stretch->part_number.'|'.$stretch->step;

            if ($stretch->resumed && isset($latestRound[$key])) {
                $rounds[$latestRound[$key]]->push($stretch);
            } else {
                $latestRound[$key] = $rounds->count();
                $rounds->push(collect([$stretch]));
            }
        }

        return $rounds;
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
     * Assistant can't act on it. Nor while it's stopped on its own
     * (changePartStatus()) — nor with Data Entry until they've started on it
     * (startDataEntryPart()), or while they've paused it (pauseDataEntryPart()),
     * and the same with GM Assistant (startGmAssistantPart(),
     * pauseGmAssistantPart()).
     */
    private function timedStepFor(RfqAssignment $part): ?string
    {
        return match (true) {
            $part->isStopped() => null,
            $part->completed_at === null => 'sourcing',
            // With Data Entry, but only timed once they've started on it —
            // and not while they've paused it.
            $part->data_entry_completed_at === null => $part->isDataEntryRunning() ? 'data_entry' : null,
            $part->finalized_at === null => 'finalize',
            // With GM Assistant, likewise only once they've started on it,
            // and not while they've paused it.
            $part->isAwaitingGmAssistant() && ! $this->isHeldOnAnotherReturnsPage('gm_assistant') => $part->isGmAssistantRunning() ? 'gm_assistant' : null,
            default => null,
        };
    }

    /**
     * Where $part's countdown stands with its Sourcing member: the working
     * time its priority allows them (Setting::sourcingTargets()) less all the
     * time they've had it so far — every round, a part sent back for rework
     * carrying on from where it was rather than starting again — less any
     * day they were absent (Attendance); negative once it's overdue. A part
     * given to someone else (Senior Operations' Reassign) is theirs afresh.
     * Null if the part isn't with Sourcing right now. Expects steps to be
     * loaded — all of the part's Sourcing ones — or loads them.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function sourcingCountdown(int $part, ?CarbonInterface $now = null): ?array
    {
        return $this->countdownAt('sourcing', $part, $this->sourcingTargetSeconds(), $now);
    }

    /**
     * When each part waiting for Data Entry to start came in — handed over by
     * Sourcing, sent back from Finalize, set going again, or rejected back —
     * on a Pending RFQ: what Data Entry's idle alert counts from
     * (User::dataEntryIdleness()). With $forUser, only the parts that are
     * theirs to start: assigned to them, or to anyone in Data Entry.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public static function dataEntryWaitingSince(?int $forUser = null): Collection
    {
        return DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->when($forUser !== null, fn ($theirs) => $theirs->where(fn ($forThem) => $forThem
                ->whereNull('rfq_user.data_entry_assigned_to')
                ->orWhere('rfq_user.data_entry_assigned_to', $forUser)))
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.status')
            ->whereNotNull('rfq_user.completed_at')
            ->whereNull('rfq_user.data_entry_completed_at')
            ->whereNull('rfq_user.data_entry_started_at')
            ->get(['rfq_user.completed_at', 'rfq_user.data_entry_returned_at', 'rfq_user.status_changed_at', 'rfqs.rejected_at'])
            ->map(fn (object $part) => collect((array) $part)->filter()->map(fn (string $at) => CarbonImmutable::parse($at))->max());
    }

    /**
     * When each part waiting for GM Assistant to start came in — approved on
     * to them by the Head of Business Development, set going again, or sent
     * back to them — on a Pending RFQ that's on its way through their step:
     * what GM Assistant's idle alert counts from (User::gmAssistantIdleness()).
     * Anyone in GM Assistant can take any of them.
     *
     * @return Collection<int, CarbonImmutable>
     */
    public static function gmAssistantWaitingSince(): Collection
    {
        return DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where(fn ($stages) => $stages->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant']))
            ->whereNull('rfq_user.status')
            ->whereNotNull('rfq_user.head_of_bd_approved_at')
            ->whereNull('rfq_user.gm_assistant_completed_at')
            ->whereNull('rfq_user.gm_assistant_started_at')
            ->get(['rfq_user.head_of_bd_approved_at', 'rfq_user.status_changed_at', 'rfqs.rejected_at'])
            ->map(fn (object $part) => collect((array) $part)->filter()->map(fn (string $at) => CarbonImmutable::parse($at))->max());
    }

    /**
     * Where $part's countdown stands with Data Entry: the working time it
     * allows them (dataEntryTargetSeconds() — per item, or by priority) less
     * what this round has taken since their Start, less any day whoever
     * started it was absent (Attendance) — negative once it's overdue.
     * Null if Data Entry isn't on it right now — not started yet, or sent on.
     * Each round starts its own countdown. Expects steps to be loaded, or
     * loads them.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function dataEntryCountdown(int $part, ?CarbonInterface $now = null): ?array
    {
        return $this->countdownAt('data_entry', $part, $this->dataEntryTargetSeconds($part), $now);
    }

    /**
     * Where $part's countdown stands with its Sourcing member to finalize it,
     * once Data Entry has sent it to them: its priority's Finalize target
     * (Setting::finalizeTargets()) less the working time this round has
     * taken. Null unless it's waiting on their Finalize now.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function finalizeCountdown(int $part, ?CarbonInterface $now = null): ?array
    {
        return $this->countdownAt('finalize', $part, $this->finalizeTargetSeconds(), $now);
    }

    /**
     * The working time Sourcing has to finalize a part of this RFQ, by its
     * priority — see Setting::finalizeTargets(). In seconds.
     */
    public function finalizeTargetSeconds(): int
    {
        return (Setting::finalizeTargets()[$this->priority_level] ?? Setting::DEFAULT_FINALIZE_TARGETS['Medium']) * 60;
    }

    /**
     * Where $part's countdown stands with GM Assistant, once the Head of
     * Business Development has approved it: its priority's GM Assistant
     * target (Setting::gmAssistantTargets()) less the working time this round
     * has taken. Null unless it's waiting on GM Assistant's Submit now.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function gmAssistantCountdown(int $part, ?CarbonInterface $now = null): ?array
    {
        return $this->countdownAt('gm_assistant', $part, $this->gmAssistantTargetSeconds($part), $now);
    }

    /**
     * The working time GM Assistant has to submit $part to the General
     * Manager: its line items times the time a line item takes — always for
     * the Finalized Line Items the Head of Business Development gave,
     * otherwise while per line item is on (gmAssistantTargetBreakdown()) —
     * else by its priority — see Setting::gmAssistantTargets(). In seconds.
     */
    public function gmAssistantTargetSeconds(?int $part = null): int
    {
        return $this->gmAssistantTargetBreakdown($part)['seconds']
            ?? (Setting::gmAssistantTargets()[$this->priority_level] ?? Setting::DEFAULT_GM_ASSISTANT_TARGETS['Medium']) * 60;
    }

    /**
     * How GM Assistant's target for $part comes about while it's worked out
     * per line item: its line items (gmAssistantLineItemsFor()), the working
     * seconds a line item takes (Setting::gmAssistantPerItem() — or the
     * person's own who started it, gmAssistantPersonFor()), and the two
     * multiplied — always for the Head of Business Development's Finalized
     * Line Items (source 'finalized'), and for the others while per line item
     * is on. Null by priority — per line item off without finalized ones, or
     * the part having no line items to go by.
     *
     * @return array{items: int, perItem: int, seconds: int, source: string}|null
     */
    public function gmAssistantTargetBreakdown(?int $part = null): ?array
    {
        $perItem = Setting::gmAssistantPerItemFor($part !== null ? $this->gmAssistantPersonFor($part) : null);
        $lineItems = $part !== null ? $this->gmAssistantLineItemsFor($part) : null;

        // The Head of Business Development's finalized line items always go by
        // the line item, as Sourcing's line items to add do for Data Entry.
        if ($lineItems === null || (! $perItem['enabled'] && $lineItems['source'] !== 'finalized')) {
            return null;
        }

        return ['items' => $lineItems['count'], 'perItem' => $perItem['seconds'], 'seconds' => $lineItems['count'] * $perItem['seconds'], 'source' => $lineItems['source']];
    }

    /**
     * The line items GM Assistant has on $part: the Finalized Line Items the
     * Head of Business Development gave as they approved it on to them
     * (RfqAssignment::finalizedLineItems()), else those Data Entry said they
     * added (rfq_user.data_entry_entries), else those Sourcing said to add
     * (line_items_to_add). Senior Operations' Actual Line Items don't count
     * here (since 2026-10-24, at the user's request). A count of 0 is passed
     * over for the next. Null with none of any.
     *
     * @return array{count: int, source: string}|null
     */
    public function gmAssistantLineItemsFor(int $part): ?array
    {
        $assignment = $this->assigneeForPart($part)?->pivot;

        return match (true) {
            $assignment === null => null,
            (int) $assignment->finalizedLineItems() > 0 => ['count' => (int) $assignment->finalizedLineItems(), 'source' => 'finalized'],
            (int) $assignment->data_entry_entries > 0 => ['count' => (int) $assignment->data_entry_entries, 'source' => 'added'],
            (int) $assignment->line_items_to_add > 0 => ['count' => (int) $assignment->line_items_to_add, 'source' => 'to_add'],
            default => null,
        };
    }

    /**
     * How $part's time left with GM Assistant comes about — what the popup on
     * it explains (rfqs/_gm_assistant_time_card): the line items it goes by
     * (gmAssistantLineItemsFor()) — which count they are, who gave it, in
     * which role and when — the time a line item takes, the target they make
     * (or the priority's, with nothing to go by per line item), and where it
     * stands now: waiting for GM Assistant's Start (the whole target),
     * running from it, or paused, with the working time used and left.
     *
     * @return array{lineItems: ?array{count: int, label: string, by: ?string, role: string, at: ?CarbonInterface}, perItem: ?int, perItemOwnOf: ?string, target: int, byPriority: bool, state: string, startedAt: ?CarbonInterface, startedBy: ?string, pausedAt: ?CarbonInterface, pauseReason: ?string, remaining: int, used: int}
     */
    public function gmAssistantTimeExplained(int $part): array
    {
        $holder = $this->assigneeForPart($part);
        $assignment = $holder?->pivot;
        $breakdown = $this->gmAssistantTargetBreakdown($part);
        $lineItems = $this->gmAssistantLineItemsFor($part);
        $target = $this->gmAssistantTargetSeconds($part);
        $countdown = $this->gmAssistantCountdown($part) ?? $this->pausedGmAssistantCountdown($part);

        $from = $lineItems === null || $assignment === null ? null : match ($lineItems['source']) {
            'finalized' => ['label' => 'Finalized Line Items', 'by' => $assignment->headOfBdApprovedBy?->name, 'role' => 'Head of Business Development', 'at' => $assignment->head_of_bd_approved_at],
            'added' => ['label' => 'Line Items Added', 'by' => $assignment->dataEntryCompletedBy?->name, 'role' => 'Data Entry', 'at' => $assignment->data_entry_completed_at],
            default => ['label' => 'Line Items to Add', 'by' => $holder->name, 'role' => 'Sourcing', 'at' => $assignment->completed_at],
        };

        return [
            'lineItems' => $from === null ? null : ['count' => $lineItems['count']] + $from,
            'perItem' => $breakdown['perItem'] ?? null,
            // Whose own time a line item it is, when it's someone's (Settings → GM Assistant Time).
            'perItemOwnOf' => $breakdown !== null && Setting::gmAssistantPerItemFor($this->gmAssistantPersonFor($part))['own']
                ? User::query()->whereKey($this->gmAssistantPersonFor($part))->value('name')
                : null,
            'target' => $target,
            'byPriority' => $breakdown === null,
            'state' => match (true) {
                $assignment?->isGmAssistantPaused() === true => 'paused',
                $assignment?->hasGmAssistantStarted() === true => 'running',
                default => 'waiting',
            },
            'startedAt' => $assignment?->gm_assistant_started_at,
            'startedBy' => $assignment?->gmAssistantStartedBy?->name,
            'pausedAt' => $assignment?->gm_assistant_paused_at,
            'pauseReason' => $assignment?->gm_assistant_pause_reason,
            'remaining' => $countdown['remaining'] ?? $target,
            'used' => $countdown !== null ? max(0, $countdown['target'] - $countdown['remaining']) : 0,
        ];
    }

    /**
     * Where $part's GM Assistant countdown stood when they paused it — what's
     * left carries on from there once it's resumed. Null unless it's paused.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function pausedGmAssistantCountdown(int $part): ?array
    {
        if ($this->activePart($part)?->isGmAssistantPaused() !== true) {
            return null;
        }

        return $this->countdownAt('gm_assistant', $part, $this->gmAssistantTargetSeconds($part), null, stillOpen: false);
    }

    /**
     * Where $part's countdown stood when Data Entry paused it — what's left
     * when they resume. Null unless it's paused.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function pausedDataEntryCountdown(int $part): ?array
    {
        if ($this->activePart($part)?->isDataEntryPaused() !== true) {
            return null;
        }

        return $this->countdownAt('data_entry', $part, $this->dataEntryTargetSeconds($part), null, stillOpen: false);
    }

    /**
     * $part's countdown at $step: $target less the working time its latest
     * round there has taken — see sourcingCountdown() / dataEntryCountdown() /
     * finalizeCountdown() / gmAssistantCountdown().
     * Null unless that round is still open — or, with ! $stillOpen, for the
     * round as it stood when it last stopped (pausedDataEntryCountdown()).
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    private function countdownAt(string $step, int $part, int $target, ?CarbonInterface $now, bool $stillOpen = true): ?array
    {
        $stretches = $this->steps->filter(fn (RfqStep $stretch) => $stretch->part_number === $part && $stretch->step === $step);
        $round = self::roundsOf($stretches)->last();

        if ($round === null || ($stillOpen && $round->last()->ended_at !== null) || $this->status !== 'Pending') {
            return null;
        }

        // Sourcing's time on a part runs on across its rounds with the same
        // member (sourcingTimeOf()); the other steps' each round afresh.
        $counted = $step === 'sourcing'
            ? self::sourcingTimeOf($stretches, $round->last())
            : $round;
        $book = Attendance::book($counted->pluck('worked_by'));

        // Every stretch counted — time on hold aside — less any day its
        // member was absent.
        return [
            'target' => $target,
            'remaining' => $target - (int) $counted->sum(fn (RfqStep $stretch) => $stretch->secondsExcludingAbsence($book, $now)),
        ];
    }

    /**
     * The Sourcing stretches that count against $upTo's part's target, from
     * $stretches: that part's, with the same member, up to and including
     * $upTo — every round they've had it, a return for rework carrying on
     * rather than starting again. A part reassigned to someone else starts
     * afresh with them.
     *
     * @param  Collection<int, RfqStep>  $stretches
     * @return Collection<int, RfqStep>
     */
    public static function sourcingTimeOf(Collection $stretches, RfqStep $upTo): Collection
    {
        return $stretches->filter(fn (RfqStep $stretch) => $stretch->step === 'sourcing'
            && $stretch->part_number === $upTo->part_number
            && $stretch->assignee_id === $upTo->assignee_id
            && $stretch->started_at->lte($upTo->started_at))->values();
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
     * The working time Data Entry has for a round on $part, from their Start:
     * the line items to add times the time per item — or, without those,
     * the RFQ's items times it while per item is on (dataEntryTargetBreakdown())
     * — else by its priority (Setting::dataEntryTargets()). In seconds.
     */
    public function dataEntryTargetSeconds(?int $part = null): int
    {
        return $this->dataEntryTargetBreakdown($part)['seconds']
            ?? (Setting::dataEntryTargets()[$this->priority_level] ?? Setting::DEFAULT_DATA_ENTRY_TARGETS['Medium']) * 60;
    }

    /**
     * How Data Entry's target for $part comes about when it's worked out per
     * item: how many, the working seconds an item takes — the company's
     * (Setting::dataEntryPerItem()), or the part's Data Entry person's own
     * (dataEntryPersonFor(), Setting::dataEntryPerItemFor()) — and the two
     * multiplied. The line items
     * its Sourcing member said to add (rfq_user.line_items_to_add), whenever
     * they did — 'source' line_items; else, while per item is on, the RFQ's
     * own items (dataEntryItemsFor()) — 'source' items. Null by priority:
     * no line items given, and per item off or the RFQ having no item count.
     *
     * @return array{items: int, perItem: int, seconds: int, source: string}|null
     */
    public function dataEntryTargetBreakdown(?int $part = null): ?array
    {
        $perItem = Setting::dataEntryPerItemFor($part !== null ? $this->dataEntryPersonFor($part) : null);
        $lineItems = $part !== null ? $this->assigneeForPart($part)?->pivot->line_items_to_add : null;

        if ($lineItems) {
            return ['items' => $lineItems, 'perItem' => $perItem['seconds'], 'seconds' => $lineItems * $perItem['seconds'], 'source' => 'line_items'];
        }

        $items = $this->dataEntryItemsFor($part);

        if (! $perItem['enabled'] || $items === null) {
            return null;
        }

        return ['items' => $items, 'perItem' => $perItem['seconds'], 'seconds' => $items * $perItem['seconds'], 'source' => 'items'];
    }

    /**
     * Whose time per item $part's Data Entry goes by: whoever's doing it (or
     * did it), else whoever it was given to by name — null while it's left
     * to anyone in Data Entry, so it takes the company's till someone starts.
     */
    public function dataEntryPersonFor(int $part): ?int
    {
        $assignment = $this->assigneeForPart($part)?->pivot;

        return $assignment?->data_entry_started_by ?? $assignment?->data_entry_completed_by ?? $assignment?->data_entry_assigned_to;
    }

    /**
     * Whose time per line item $part's GM Assistant step goes by: whoever
     * started it (or submitted it) — null till someone in GM Assistant does,
     * so it takes the company's till then.
     */
    public function gmAssistantPersonFor(int $part): ?int
    {
        $assignment = $this->assigneeForPart($part)?->pivot;

        return $assignment?->gm_assistant_started_by ?? $assignment?->gm_assistant_completed_by;
    }

    /**
     * The items Data Entry has on $part: the RFQ's number of items — shared
     * evenly across a split's parts, rounded up. Null when the RFQ has no
     * item count (one created before it was asked).
     */
    public function dataEntryItemsFor(?int $part = null): ?int
    {
        if (! $this->number_of_items) {
            return null;
        }

        return $part !== null && $this->isSplit()
            ? (int) ceil($this->number_of_items / $this->splitTotal())
            : (int) $this->number_of_items;
    }

    /**
     * How this RFQ stands against every timed step's target (RfqStep::LABELS:
     * Sourcing, Data Entry, Finalize, GM Assistant), as the dashboards judge
     * it. Each task someone's on now, with its countdown (countdownFor())
     * and where that stands (timeAgainstTarget()), overdue first, as on
     * Admin's ongoing tasks. And, of the rounds each step's role finished
     * (RfqStep::ended_by_role), as the team's on-time figures judge them:
     * those past their target (stepTargetSeconds()), by step, with how many
     * and by how much in all, and how many were within it. Sourcing's time on
     * a part runs on across its rounds with the same member
     * (sourcingTimeOf()), judged as it stood when they last finished it; the
     * other steps' each round on its own. A day its worker was absent doesn't
     * count (Attendance). Overdue when a task's past its target now — the
     * dashboards' Overdue — and exceeded when that, or anything finished
     * late. Null if no timed step has had it. Expects steps to be loaded, or
     * loads them.
     *
     * @return array{running: list<array{step: string, part: ?int, seconds: int, target: int, remaining: int, state: string}>, late: array<string, array{rounds: int, late_by: int}>, on_time: int, overdue: bool, exceeded: bool}|null in seconds
     */
    public function deadlines(?CarbonInterface $now = null): ?array
    {
        $timed = $this->steps->filter(fn (RfqStep $stretch) => array_key_exists($stretch->step, RfqStep::LABELS))->values();

        if ($timed->isEmpty()) {
            return null;
        }

        $now ??= now();
        $book = Attendance::book($timed->pluck('worked_by'));
        $taken = fn (Collection $stretches) => (int) $stretches->sum(fn (RfqStep $stretch) => $stretch->secondsExcludingAbsence($book, $now));
        $states = ['overdue', 'due_soon', 'on_track'];

        $running = $timed->whereNull('ended_at')
            ->map(function (RfqStep $open) use ($now) {
                $countdown = $this->countdownFor($open->step, (int) $open->part_number, $now);

                return $countdown === null ? null : ['step' => $open->step, 'part' => $open->part_number] + self::timeAgainstTarget($countdown);
            })
            ->filter()
            ->sortBy(fn (array $task) => [array_search($task['state'], $states, true), $task['remaining']])
            ->values();

        // Sourcing's: each part with its member, every round up to the last
        // they finished, unless they've it again now (it's running, above).
        $finished = $timed->where('step', 'sourcing')
            ->groupBy(fn (RfqStep $stretch) => $stretch->part_number.'|'.$stretch->assignee_id)
            ->reject(fn (Collection $theirs) => $theirs->contains(fn (RfqStep $stretch) => $stretch->ended_at === null))
            ->map(fn (Collection $theirs) => $theirs->last(fn (RfqStep $stretch) => $stretch->ended_by_role))
            ->filter()
            ->map(fn (RfqStep $lastFinish) => [
                'step' => 'sourcing',
                'seconds' => $taken(self::sourcingTimeOf($timed, $lastFinish)),
                'target' => $this->sourcingTargetSeconds(),
            ])
            ->values()
            // The other steps': each round on its own.
            ->concat(self::roundsOf($timed->where('step', '!=', 'sourcing'))
                ->filter(fn (Collection $round) => $round->last()->ended_at !== null && $round->last()->ended_by_role)
                ->map(fn (Collection $round) => [
                    'step' => $round->first()->step,
                    'seconds' => $taken($round),
                    'target' => $this->stepTargetSeconds($round->first()->step, $round->first()->part_number),
                ]));

        $late = $finished->filter(fn (array $round) => $round['seconds'] > $round['target'])->groupBy('step');
        $overdue = $running->contains('state', 'overdue');

        return [
            'running' => $running->all(),
            // In workflow order (FORWARD_BACK_ORDER).
            'late' => collect(self::FORWARD_BACK_ORDER)
                ->filter(fn (string $step) => $late->has($step))
                ->mapWithKeys(fn (string $step) => [$step => [
                    'rounds' => $late[$step]->count(),
                    'late_by' => (int) $late[$step]->sum(fn (array $round) => $round['seconds'] - $round['target']),
                ]])
                ->all(),
            'on_time' => $finished->filter(fn (array $round) => $round['seconds'] <= $round['target'])->count(),
            'overdue' => $overdue,
            'exceeded' => $overdue || $late->isNotEmpty(),
        ];
    }

    /**
     * The ids of the RFQs with a step its role finished past its target —
     * deadlines()'s late — whatever they're at now: with those overdue now,
     * what the Time Spent report lists under Deadline exceeded, and All
     * RFQs' Status filter of that name (scopeInStatusFilter()).
     *
     * @return array<int, int>
     */
    public static function idsWithAStepFinishedLate(?CarbonInterface $now = null): array
    {
        return static::query()
            ->whereHas('steps', fn (Builder $steps) => $steps->where('ended_by_role', true))
            ->with(['steps', 'assignees'])
            ->get()
            ->filter(fn (self $rfq) => $rfq->deadlines($now)['late'] !== [])
            ->modelKeys();
    }

    /**
     * Where $part's countdown stands at $step, one of RfqStep::LABELS — see
     * sourcingCountdown(), dataEntryCountdown(), finalizeCountdown() and
     * gmAssistantCountdown(). Null unless it's at $step now.
     *
     * @return array{target: int, remaining: int}|null in seconds
     */
    public function countdownFor(string $step, int $part, ?CarbonInterface $now = null): ?array
    {
        return match ($step) {
            'sourcing' => $this->sourcingCountdown($part, $now),
            'data_entry' => $this->dataEntryCountdown($part, $now),
            'finalize' => $this->finalizeCountdown($part, $now),
            'gm_assistant' => $this->gmAssistantCountdown($part, $now),
            default => null,
        };
    }

    /**
     * The working time $step, one of RfqStep::LABELS, has for a round on
     * $part — the target its countdown runs against (countdownFor()). In
     * seconds.
     */
    public function stepTargetSeconds(string $step, ?int $part = null): int
    {
        return match ($step) {
            'sourcing' => $this->sourcingTargetSeconds(),
            'data_entry' => $this->dataEntryTargetSeconds($part),
            'finalize' => $this->finalizeTargetSeconds(),
            'gm_assistant' => $this->gmAssistantTargetSeconds($part),
        };
    }

    /**
     * Where a countdown (countdownFor()) stands, as the dashboards' "Time vs
     * target" (dashboard/_time_vs_target) and the Time Spent report show it:
     * the working time spent, the target, what's left (below 0 once past
     * it), and so whether it's overdue, due soon — under a quarter of the
     * target left — or on track. The one place these thresholds are kept.
     *
     * @param  array{target: int, remaining: int}  $countdown
     * @return array{seconds: int, target: int, remaining: int, state: string}
     */
    public static function timeAgainstTarget(array $countdown): array
    {
        return [
            'seconds' => max(0, $countdown['target'] - $countdown['remaining']),
            'target' => $countdown['target'],
            'remaining' => $countdown['remaining'],
            'state' => match (true) {
                $countdown['remaining'] < 0 => 'overdue',
                $countdown['remaining'] < $countdown['target'] / 4 => 'due_soon',
                default => 'on_track',
            },
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
     * Attendance::book(), loaded for this RFQ's people if not given. With
     * $part, only that part of a split's (the RFQ's page focused on it).
     * Expects steps to be loaded, or loads them.
     *
     * @param  array<int, array<string, string>>|null  $book
     * @return array{roles: array<string, array{parts: array<int, int>, minutes: int, seconds: int, awaiting: int, absent: int, elapsed: int, out_of_hours: int, out_of_hours_count: int, rounds: int, reworks: int, ongoing: bool}>, minutes: int, seconds: int, awaiting: int, absent: int, elapsed: int, out_of_hours: int, out_of_hours_count: int}
     */
    public function timeSpent(?CarbonInterface $now = null, ?array $book = null, ?int $part = null): array
    {
        $now ??= now();
        $book ??= Attendance::book($this->steps->pluck('worked_by'));
        $roles = [];

        foreach (RfqStep::ROLE_STEPS as $role => $roleSteps) {
            $stretches = $this->steps->filter(fn (RfqStep $step) => in_array($step->step, $roleSteps, true)
                && ($part === null || $step->part_number === $part));
            $splits = $stretches->mapWithKeys(fn (RfqStep $step) => [$step->id => $step->secondsByAttendance($book, $now)]);

            $partSeconds = $stretches->groupBy('part_number')
                ->map(fn ($partStretches) => $partStretches->sum(fn (RfqStep $step) => $splits[$step->id]['present']))
                ->sortKeys();
            $seconds = (int) $partSeconds->sum();

            // A round is a time a part reached the role's own work — reaching
            // Finalize doesn't make a new one for Sourcing, nor does resuming
            // after a stop.
            $rounds = $stretches->where('step', $roleSteps[0])->reject(fn (RfqStep $step) => $step->resumed);

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
     * How timeSpent() came to each role's figure, stretch by stretch — for
     * the Time Spent report's breakdown. Each stretch of the role's steps, in
     * order: which step and part, whose time it is (RfqStep::workerId()) and
     * whose working week it ran on (Setting::weekSourceOn()), when it ran —
     * up to $now while it's open — its clock time, its working time, and how
     * that splits by attendance (RfqStep::secondsByAttendance()): counted
     * (present), on leave, awaiting the day's sheet, or credited to nobody;
     * whether it was a rework or carried on after a stop; and how much fell
     * out of hours (RfqStep::outOfHoursSeconds()). $names: user id => name,
     * looked up if not given. Expects steps to be loaded, or loads them.
     *
     * @param  array<int, array<string, string>>|null  $book
     * @param  Collection<int, string>|null  $names
     * @return array<string, array<int, array{step: string, part: ?string, worker: ?string, hours: string, from: CarbonInterface, to: ?CarbonInterface, elapsed: int, working: int, counted: int, absent: int, awaiting: int, unattributed: int, outOfHours: int, rework: bool, resumed: bool}>>
     */
    public function timeBreakdown(?CarbonInterface $now = null, ?array $book = null, ?Collection $names = null): array
    {
        $now ??= now();
        $book ??= Attendance::book($this->steps->pluck('worked_by'));
        $names ??= User::query()->whereIn('id', $this->steps->map(fn (RfqStep $step) => $step->workerId())->filter()->unique())->pluck('name', 'id');
        $seen = [];

        return collect(RfqStep::ROLE_STEPS)->map(fn (array $roleSteps) => $this->steps
            ->filter(fn (RfqStep $step) => in_array($step->step, $roleSteps, true))
            ->sortBy(fn (RfqStep $step) => [$step->started_at->getTimestamp(), $step->id])
            ->values()
            ->map(function (RfqStep $step) use ($now, $book, $names, $roleSteps, &$seen) {
                $split = $step->secondsByAttendance($book, $now);
                $workerId = $step->workerId();
                $source = $workerId !== null ? Setting::weekSourceOn($step->started_at, $workerId) : ['source' => 'company', 'role' => null];

                // A round is a part reaching the role's own work; any after
                // its first is rework. Carrying on after a stop isn't one.
                $isRound = $step->step === $roleSteps[0] && ! $step->resumed;
                $roundKey = $roleSteps[0].'|'.$step->part_number;
                $rework = $isRound && isset($seen[$roundKey]);
                if ($isRound) {
                    $seen[$roundKey] = true;
                }

                return [
                    'step' => RfqStep::LABELS[$step->step] ?? $step->step,
                    'part' => $this->isSplit() && $step->part_number !== null ? $this->partNumberLabel($step->part_number) : null,
                    'worker' => $workerId !== null ? $names->get($workerId) : null,
                    'hours' => match ($source['source']) {
                        'person' => 'their own hours',
                        'role' => $source['role'].'\'s hours',
                        default => 'the company\'s working hours',
                    }.($workerId !== null && ExtraWorkingHour::forUser($workerId) !== [] ? ' + their extra hours' : ''),
                    'from' => $step->started_at,
                    'to' => $step->ended_at,
                    'elapsed' => $step->elapsedSeconds($now),
                    'working' => array_sum($split),
                    'counted' => $split['present'],
                    'absent' => $split['absent'],
                    'awaiting' => $split['unmarked'],
                    'unattributed' => $split['unattributed'],
                    'outOfHours' => $step->outOfHoursSeconds(),
                    'rework' => $rework,
                    'resumed' => $step->resumed,
                ];
            })
            ->all())
            ->all();
    }

    /**
     * Posts a comment that goes with an action — its text as written, marked
     * with what was done and the context that went with it (see
     * RfqComment::ACTIONS), so the thread can show that beside the author's
     * name instead of it being written into the text.
     *
     * @param  array<string, mixed>  $meta
     */
    private function postActionComment(User $author, string $action, string $text, array $meta = []): RfqComment
    {
        $comment = $this->comments()->create([
            'user_id' => $author->id,
            'action' => $action,
            'body' => trim($text),
            'meta' => $meta === [] ? null : $meta,
        ]);
        $this->load(['comments.author', 'comments.replies.author']);

        return $comment;
    }

    /**
     * Who works $stage on this RFQ — for $part, or every part still open —
     * and so whom something sent there is for (App\TaskMessages): its
     * creator for Business Development; whoever's routing it for Senior
     * Operations, else all of them; the part's member for Sourcing; whoever
     * did (or is doing, or was given) its Data Entry, else all of Data
     * Entry; and everyone in the role for the Head of Business Development,
     * GM Assistant and the General Manager. Active people only.
     *
     * @return Collection<int, User>
     */
    public function peopleAtStage(string $stage, ?int $part = null): Collection
    {
        $parts = $this->assignees->filter(fn (User $assignee) => $part !== null
            ? $assignee->pivot->part_number === $part
            : ! $assignee->pivot->isBdClosed() && ! $assignee->pivot->isCancelled());

        $people = match ($stage) {
            'business_development', 'bd_closing' => collect([$this->creator]),
            'operations', 'senior_ops_review' => $this->operationsAssignee !== null
                ? collect([$this->operationsAssignee])
                : User::query()->holdingRole('Senior Operations')->get(),
            'sourcing', 'finalize' => $parts,
            'data_entry' => $this->dataEntryPeopleOf($parts),
            default => isset(self::RETURN_TARGET_ROLES[$stage])
                ? User::query()->holdingRole(self::RETURN_TARGET_ROLES[$stage])->get()
                : collect(),
        };

        return collect($people)->filter(fn (?User $person) => $person !== null && ! $person->isDeactivated())->unique('id')->values();
    }

    /**
     * Everyone who's had a hand in this RFQ, and so is told of what's said
     * on it (RfqComment::messagePeopleInvolved()): its creator, whoever
     * routed it, each part's Sourcing member, whoever was given or did a
     * part's Data Entry, finalized, reviewed, approved, returned, held or
     * closed it (PEOPLE_COLUMNS), everyone who's commented or acted on it —
     * and whoever it's waiting with now: the review stage's people
     * (peopleAtStage()), or Senior Operations' while it's yet to be
     * assigned. Active people only.
     *
     * @return Collection<int, User>
     */
    public function peopleInvolved(): Collection
    {
        $ids = collect(self::PEOPLE_COLUMNS['rfqs'])->map(fn (string $column) => $this->getAttribute($column))
            ->merge(DB::table('rfq_user')->where('rfq_id', $this->id)->get(self::PEOPLE_COLUMNS['rfq_user'])
                ->flatMap(fn (object $part) => array_values((array) $part)))
            ->merge(RfqComment::query()->where('rfq_id', $this->id)->distinct()->pluck('user_id'))
            ->filter()
            ->unique();

        $waitingWith = match (true) {
            in_array($this->stage, [...self::REVIEW_STAGES, 'bd_closing'], true) => $this->peopleAtStage($this->stage),
            $this->stage === null && ! DB::table('rfq_user')->where('rfq_id', $this->id)->exists() => $this->peopleAtStage('operations'),
            default => collect(),
        };

        return User::query()->whereKey($ids->all())->orderBy('id')->get()
            ->merge($waitingWith)
            ->reject(fn (User $person) => $person->isDeactivated())
            ->unique('id')
            ->values();
    }

    /**
     * Whoever did, is doing, or was given the Data Entry of $parts — else
     * everyone in Data Entry.
     *
     * @param  Collection<int, User>  $parts
     * @return Collection<int, User>
     */
    private function dataEntryPeopleOf(Collection $parts): Collection
    {
        $ids = $parts->map(fn (User $assignee) => $assignee->pivot->data_entry_completed_by
            ?? $assignee->pivot->data_entry_started_by
            ?? $assignee->pivot->data_entry_assigned_to)
            ->filter()
            ->unique();

        return $ids->isNotEmpty()
            ? User::query()->whereKey($ids->all())->get()
            : User::query()->holdingRole('Data Entry')->get();
    }

    /**
     * The actions whose comments carry, or bear on, a part's line items —
     * what lineItemsHistory() reads: Sourcing's Assign to Data Entry (the
     * line items to add), Data Entry's Send to Finalize (the line items
     * added) and Return to Sourcing (added so far), and Sourcing's Return to
     * Data Entry.
     *
     * @var array<int, string>
     */
    private const LINE_ITEMS_ACTIONS = ['sourcing_completed', 'data_entry_completed', 'returned_to_sourcing', 'returned_to_data_entry'];

    /**
     * $part's line items as they've gone, oldest first — for Senior
     * Operations to see how the count came about as they verify it, and the
     * Head of Business Development as they finalize it: each time Sourcing
     * set the line items to add, each stretch Sourcing and Data Entry worked
     * on it — sourcing it, entering it, checking it to finalize — (from when
     * to when, and the working time), each time Data Entry
     * sent it to finalize with the line items added — against what was
     * asked, and what they'd sent before — or sent it back to Sourcing with
     * those added so far, each time Sourcing returned it to Data Entry, the
     * line items Sourcing submitted as they finalized it, and Senior
     * Operations' verified count once there is one. Each with who,
     * in which role, and any note, ready for the popup (admin.js).
     *
     * "tone" says whether a change is a difference ("off") or a match ("ok").
     *
     * @return array<int, array{kind: string, at: string, until: ?string, duration: ?string, who: string, role: string, title: string, count: ?int, change: ?string, tone: ?string, note: ?string}>
     */
    public function lineItemsHistory(int $part, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $stamp = fn (?CarbonInterface $at) => $at?->toBusinessTimezone()->format('M d, Y g:i A');
        $ofPart = fn (RfqComment $comment) => (int) ($comment->meta['part'] ?? 1) === $part;
        $entries = [];

        $comments = RfqComment::query()
            ->where('rfq_id', $this->id)
            ->whereNull('parent_id')
            ->whereIn('action', self::LINE_ITEMS_ACTIONS)
            ->with('author')
            ->oldest()
            ->oldest('id')
            ->get()
            ->filter($ofPart);

        $stretches = RfqStep::query()
            ->where('rfq_id', $this->id)
            ->where('part_number', $part)
            ->whereIn('step', ['sourcing', 'data_entry', 'finalize'])
            ->oldest('started_at')
            ->get();
        $workers = User::query()->whereIn('id', $stretches->map(fn (RfqStep $stretch) => $stretch->workerId())->filter()->unique())->pluck('name', 'id');

        foreach ($stretches as $stretch) {
            $entries[] = ['sort' => $stretch->started_at, 'row' => [
                'kind' => 'worked',
                'at' => $stamp($stretch->started_at),
                'until' => $stretch->ended_at !== null ? $stamp($stretch->ended_at) : 'now',
                'duration' => Setting::durationLabel($stretch->workingSeconds($now)).' working time',
                'who' => $workers[$stretch->workerId()] ?? ($stretch->step === 'data_entry' ? 'Data Entry' : 'Sourcing'),
                'role' => $stretch->step === 'data_entry' ? 'Data Entry' : 'Sourcing',
                'title' => match ($stretch->step) {
                    'data_entry' => 'Worked on the data entry',
                    'finalize' => 'Checked it to finalize',
                    default => 'Worked on sourcing',
                }.($stretch->resumed ? ' (after a stop)' : ''),
                'count' => null,
                'change' => null,
                'tone' => null,
                'note' => null,
            ]];
        }

        $toAdd = null;
        $added = null;
        $count = fn (int $n) => number_format($n);

        foreach ($comments as $comment) {
            $meta = $comment->meta ?? [];
            $row = [
                'kind' => $comment->action,
                'at' => $stamp($comment->created_at),
                'until' => null,
                'duration' => null,
                'who' => $comment->author?->name ?? 'Someone',
                'role' => in_array($comment->action, ['sourcing_completed', 'returned_to_data_entry'], true) ? 'Sourcing' : 'Data Entry',
                'count' => null,
                'change' => null,
                'tone' => null,
                'note' => filled($comment->body) ? Str::limit((string) $comment->body, 160) : null,
            ];

            switch ($comment->action) {
                case 'sourcing_completed':
                    $given = isset($meta['line_items']) ? (int) $meta['line_items'] : null;
                    $row['title'] = 'Line items to add'.(isset($meta['assigned_to']) ? " — assigned to {$meta['assigned_to']}" : ' — assigned to Data Entry');
                    $row['count'] = $given;
                    $row['change'] = $given !== null && $toAdd !== null && $given !== $toAdd
                        ? 'Was '.$count($toAdd).' — '.($given > $toAdd ? '+' : '−').$count(abs($given - $toAdd))
                        : null;
                    $row['tone'] = $row['change'] !== null ? 'off' : null;
                    $toAdd = $given ?? $toAdd;
                    break;

                case 'data_entry_completed':
                case 'returned_to_sourcing':
                    $n = isset($meta['entries']) ? (int) $meta['entries'] : null;
                    $sentBack = $comment->action === 'returned_to_sourcing';
                    $row['title'] = $sentBack ? 'Line items added so far — returned to Sourcing' : 'Line items added — sent to finalize';
                    $row['count'] = $n;
                    $row['change'] = $n === null ? null : (collect([
                        $toAdd !== null ? (RfqAssignment::lineItemsDiscrepancyText($n, $toAdd) ?? 'Matches the '.$count($toAdd).' requested') : null,
                        $added !== null && $added !== $n ? 'was '.$count($added).' — '.($n > $added ? '+' : '−').$count(abs($n - $added)) : null,
                    ])->filter()->map(fn (string $line) => Str::ucfirst($line))->join(' · ') ?: null);
                    $row['tone'] = $n === null ? null
                        : (($toAdd !== null && $n !== $toAdd) || ($added !== null && $n !== $added) ? 'off' : 'ok');
                    $added = $n ?? $added;
                    break;

                default:
                    $row['title'] = 'Returned to Data Entry';
            }

            $entries[] = ['sort' => $comment->created_at, 'row' => $row];
        }

        $holder = $this->assigneeForPart($part)?->pivot;

        // What Sourcing submitted finalizing it — the latest Finalize's.
        $submitted = $holder?->submittedLineItems();
        if ($submitted !== null) {
            $entries[] = ['sort' => $holder->finalized_at, 'row' => [
                'kind' => 'submitted',
                'at' => $stamp($holder->finalized_at),
                'until' => null,
                'duration' => null,
                'who' => $holder->finalizedBy?->name ?? $this->assigneeForPart($part)?->name ?? 'Sourcing',
                'role' => 'Sourcing',
                'title' => 'Submitted line items — finalized',
                'count' => $submitted,
                'change' => $added !== null && $added !== $submitted
                    ? $count(abs($submitted - $added)).($submitted < $added ? ' fewer' : ' more').' than Data Entry added'
                    : ($added !== null ? 'Matches what Data Entry added' : null),
                'tone' => $added === null ? null : ($added !== $submitted ? 'off' : 'ok'),
                'note' => null,
            ]];
        }

        // Senior Operations' own count, once they've verified it — against
        // what Sourcing submitted, else what Data Entry added.
        [$before, $beforeBy] = $submitted !== null ? [$submitted, 'Sourcing submitted'] : [$added, 'Data Entry added'];
        if ($holder?->senior_ops_reviewed_at !== null && $holder->verified_line_items !== null) {
            $entries[] = ['sort' => $holder->senior_ops_reviewed_at, 'row' => [
                'kind' => 'verified',
                'at' => $stamp($holder->senior_ops_reviewed_at),
                'until' => null,
                'duration' => null,
                'who' => $holder->seniorOpsReviewedBy?->name ?? 'Senior Operations',
                'role' => 'Senior Operations',
                'title' => 'Actual line items — verified',
                'count' => (int) $holder->verified_line_items,
                'change' => $before !== null && $before !== (int) $holder->verified_line_items
                    ? $count(abs((int) $holder->verified_line_items - $before)).((int) $holder->verified_line_items < $before ? ' fewer' : ' more').' than '.$beforeBy
                    : ($before !== null ? 'Matches what '.$beforeBy : null),
                'tone' => $before === null ? null : ($before !== (int) $holder->verified_line_items ? 'off' : 'ok'),
                'note' => null,
            ]];
        }

        // By when; an action before the stretch it set going, at the same moment.
        return collect($entries)
            ->sortBy(fn (array $entry) => [$entry['sort']->getTimestamp(), $entry['row']['kind'] === 'worked' ? 1 : 0])
            ->pluck('row')
            ->values()
            ->all();
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
            && $this->activePart($part)?->isAwaitingGmAssistant() === true;
    }

    /**
     * The parts GM Assistant's Submit of the whole RFQ (recordGmAssistantDetails())
     * submits: approved by the Head of Business Development, not yet
     * submitted, and not stopped on their own.
     *
     * @return Collection<int, User>
     */
    public function partsAwaitingGmAssistant(): Collection
    {
        return $this->assignees
            ->filter(fn (User $assignee) => $assignee->pivot->head_of_bd_approved_at !== null
                && $assignee->pivot->gm_assistant_completed_at === null
                && $assignee->pivot->status === null)
            ->sortBy('pivot.part_number')
            ->values();
    }

    /**
     * Whether $part is waiting on GM Assistant to start on it: with them
     * (partAwaitsGmAssistant()), not started yet.
     */
    public function partAwaitsGmAssistantStart(int $part): bool
    {
        return $this->partAwaitsGmAssistant($part) && $this->activePart($part)?->hasGmAssistantStarted() === false;
    }

    /**
     * GM Assistant starts on one part with them: from now, their time on it
     * counts (syncSteps() — see timedStepFor()), credited to $startedBy.
     * Idempotent — a part that isn't waiting to be started is left alone.
     */
    public function startGmAssistantPart(int $part, User $startedBy): void
    {
        if (! $this->partAwaitsGmAssistantStart($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'gm_assistant_started_at' => now(),
            'gm_assistant_started_by' => $startedBy->id,
        ]);
        $this->load('assignees');

        $this->syncSteps();
    }

    /**
     * GM Assistant pauses $part, which they'd started — saying why ($reason),
     * and the document it's waiting on ($reference), if any: its time stops —
     * the countdown keeps what's left — and with nothing running they can
     * start another (RfqStep::runningGmAssistantOf()). The reason goes on the
     * part while it's paused, and to the RFQ's thread as $pausedBy's.
     * Idempotent: a part that isn't running is left alone.
     */
    public function pauseGmAssistantPart(int $part, User $pausedBy, string $reason, ?string $reference = null): void
    {
        if ($this->activePart($part)?->isGmAssistantRunning() !== true || ! $this->partAwaitsGmAssistant($part)) {
            return;
        }

        $reference = filled($reference) ? trim($reference) : null;

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'gm_assistant_paused_at' => now(),
            'gm_assistant_pause_reason' => trim($reason),
            'gm_assistant_pause_reference' => $reference,
        ]);

        $this->postActionComment($pausedBy, 'gm_assistant_paused', $reason, $this->partContext($part) + ($reference !== null ? ['reference' => $reference] : []));
        $this->syncSteps();
    }

    /**
     * GM Assistant resumes $part, which they'd paused: its time counts
     * again, carrying on the same round — the countdown goes on from where it
     * stopped. Idempotent: a part that isn't paused is left alone. The caller
     * checks they've nothing else running.
     */
    public function resumeGmAssistantPart(int $part): void
    {
        if ($this->activePart($part)?->isGmAssistantPaused() !== true) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'gm_assistant_paused_at' => null,
            'gm_assistant_pause_reason' => null,
            'gm_assistant_pause_reference' => null,
        ]);

        $this->syncSteps(resuming: true);
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
            && $this->activePart($part)?->isAwaitingGmApproval() === true;
    }

    /**
     * Whether GM Assistant has completed every planned part — what lets the RFQ
     * as a whole move on to the General Manager. False for an RFQ with no
     * Sourcing assignees at all, and false while any planned part is still
     * unassigned (same reasoning as allSourcingPartsCompleted()).
     */
    public function allGmAssistantPartsCompleted(): bool
    {
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->isGmAssistantCompleted());
    }

    /**
     * Whether the General Manager has approved every planned part — what lets
     * the RFQ as a whole move on to Business Development. Same conditions as
     * allGmAssistantPartsCompleted().
     */
    public function allGmPartsApproved(): bool
    {
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->isGmApproved());
    }

    /**
     * GM Assistant submits one Sourcing part the Head of Business Development
     * has approved — on its own, without waiting for the rest of a split —
     * and it goes on to the General Manager. Once every part has been
     * submitted the RFQ as a whole moves on to the General Manager.
     * Idempotent — a part that isn't waiting on GM Assistant (not approved by
     * the Head yet, or already submitted) is left alone. A $comment, if
     * given, is posted to the RFQ's thread as $completedBy's, naming the
     * part. (GM Assistant no longer gives client details or payment terms:
     * an older RFQ keeps what was given.)
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function recordGmAssistantPart(int $part, User $completedBy, ?string $comment = null): void
    {
        if (! $this->partAwaitsGmAssistant($part)) {
            return;
        }

        if ($comment !== null) {
            $this->postActionComment($completedBy, 'gm_assistant_submitted', $comment, $this->partContext($part));
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'gm_assistant_completed_at' => now(),
            'gm_assistant_completed_by' => $completedBy->id,
        ]);
        $this->load('assignees');

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
     * GM Assistant submits this RFQ for every part at once — completing any
     * not yet submitted on its own — and forwards it on to the General
     * Manager. A $comment, if given, is posted to the RFQ's thread as
     * $completedBy's.
     *
     * Caller is responsible for verifying stage === 'gm_assistant'.
     */
    public function recordGmAssistantDetails(User $completedBy, ?string $comment = null): void
    {
        if ($comment !== null) {
            $this->postActionComment($completedBy, 'gm_assistant_submitted', $comment);
        }

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNotNull('head_of_bd_approved_at')
            ->whereNull('gm_assistant_completed_at')
            ->whereNull('status')
            ->update([
                'gm_assistant_completed_at' => now(),
                'gm_assistant_completed_by' => $completedBy->id,
            ]);
        $this->load('assignees');

        $this->update([
            'gm_assistant_completed_by' => $completedBy->id,
            'gm_assistant_completed_at' => now(),
            'stage' => 'gm_review',
        ]);

        $this->resolveReturnOnceDealtWith('gm_assistant');

        $this->syncSteps();
    }

    /**
     * An RFQ held on $stage's Returns page (RETURNS_PAGE_STAGES, and Senior
     * Operations' second review's — scopeNotSentBackToSeniorOpsReview()) is
     * resolved once that stage has nothing of it left to deal with — every part it
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
            'senior_ops_review' => fn (User $assignee) => $assignee->pivot->isAwaitingSeniorOpsReview() && ! $assignee->pivot->isStopped(),
            'head_of_bd_review' => fn (User $assignee) => $assignee->pivot->isAwaitingHeadOfBdReview() && ! $assignee->pivot->isStopped(),
            'gm_assistant' => fn (User $assignee) => $assignee->pivot->isAwaitingGmAssistant() && ! $assignee->pivot->isStopped(),
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
            ->whereNull('status')
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
            && $this->activePart($part)?->isAwaitingBdClosing() === true;
    }

    /**
     * Whether Business Development has closed every planned part — what
     * closes the RFQ as a whole. False for an RFQ with no Sourcing assignees
     * at all, and false while any planned part is still unassigned (same
     * reasoning as allSourcingPartsCompleted()).
     */
    public function allBdPartsClosed(): bool
    {
        return $this->everyLivePart(fn (User $assignee) => $assignee->pivot->isBdClosed());
    }

    /**
     * Business Development closes one Sourcing part the General Manager has
     * approved — on its own, without waiting for the rest of a split. Once
     * every part has been closed the RFQ as a whole is, exactly as closeOut()
     * does. Idempotent — a part that isn't ready to close (not approved by the
     * General Manager yet, or already closed) is left alone. $referenceCode —
     * which Business Development must give to close anything, see
     * RfqController::closePart() — is kept with the part, and with the RFQ
     * when this is the part that closes it.
     *
     * Caller is responsible for verifying the part is actually assigned.
     */
    public function closePart(int $part, User $closedBy, ?string $referenceCode = null): void
    {
        if (! $this->partAwaitsBdClosing($part)) {
            return;
        }

        $this->assignees()->wherePivot('part_number', $part)->updateExistingPivot($this->assigneeForPart($part)->id, [
            'bd_closed_at' => now(),
            'bd_closed_by' => $closedBy->id,
            'bd_reference_code' => $referenceCode,
        ]);
        $this->load('assignees');

        if ($this->allBdPartsClosed()) {
            $this->closeOut($closedBy, $referenceCode);
        }
    }

    /**
     * Business Development formally closes this whole RFQ out — closing any
     * part not yet closed on its own — the true end of the lifecycle.
     * Idempotent. Reuses the existing 'Completed' status value (see
     * statusLabel() for why the display text says "Closed"). $referenceCode is
     * kept with the RFQ, and with every part closed along with it.
     *
     * Caller is responsible for verifying stage === 'bd_closing'.
     */
    public function closeOut(User $closedBy, ?string $referenceCode = null): void
    {
        if ($this->stage === 'closed') {
            return;
        }

        DB::table('rfq_user')
            ->where('rfq_id', $this->id)
            ->whereNotNull('gm_approved_at')
            ->whereNull('bd_closed_at')
            ->whereNull('status')
            ->update([
                'bd_closed_at' => now(),
                'bd_closed_by' => $closedBy->id,
                'bd_reference_code' => $referenceCode,
            ]);
        $this->load('assignees');

        $this->update([
            'bd_closed_by' => $closedBy->id,
            'bd_closed_at' => now(),
            'bd_reference_code' => $referenceCode,
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
     * Alerts sent about it (sentAlerts()) are entries too, of type
     * notification, with their title, tone, icon and recipients as well. A
     * closing (bd_closed, bd_part_closed) carries the quotation number
     * Business Development closed it with (quotation).
     *
     * @return array<int, array{
     *     type: string,
     *     at: Carbon,
     *     actor: ?User,
     *     related: ?User,
     *     detail: ?string,
     *     comment: ?RfqComment,
     *     title?: string,
     *     tone?: string,
     *     icon?: string,
     *     recipients?: Collection<int, User>,
     *     reason?: ?string,
     *     quotation?: ?string,
     *     part?: ?int,
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

        // Whoever had a part before it was given to someone else
        // (replaceSourcingPart()), and since when: the part's own assigned
        // time moved on to whoever has it now.
        $replacements = $this->comments->filter(fn (RfqComment $comment) => $comment->action === 'reassigned'
            && isset($comment->meta['from_id'], $comment->meta['from_assigned_at']));
        $previousHolders = $replacements->isEmpty() ? collect() : User::query()->whereIn('id', $replacements->pluck('meta.from_id'))->get()->keyBy('id');

        foreach ($replacements as $replacement) {
            $previous = $previousHolders->get($replacement->meta['from_id']);

            if ($previous === null) {
                continue;
            }

            $entries[] = [
                'type' => 'sourcing_assigned',
                'at' => Carbon::parse($replacement->meta['from_assigned_at']),
                'actor' => $previous,
                'related' => $previous,
                'detail' => $this->partNumberLabel((int) ($replacement->meta['part'] ?? 1)),
                'comment' => null,
                'part' => isset($replacement->meta['part']) ? (int) $replacement->meta['part'] : null,
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
                    'quotation' => $assignee->pivot->bd_reference_code,
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
            // Which part it was about, when it was one (the rejection's own
            // comment, posted with it), so a part's page shows only its own.
            $recordedBy = $this->comments->last(fn (RfqComment $comment) => $comment->action === 'rejected'
                && $comment->user_id === $this->rejected_by
                && trim((string) $comment->body) === trim((string) $this->reject_reason)
                && $comment->created_at->diffInSeconds($this->rejected_at, true) <= RfqComment::SAME_ACTION_SECONDS);

            $entries[] = [
                'type' => 'rejected',
                'at' => $this->rejected_at,
                'actor' => $this->rejectedBy,
                'related' => null,
                'detail' => 'By '.self::stageLabel($this->reject_from_stage).' — returned to '.self::stageLabel($this->reject_target_stage).': '.$this->reject_reason,
                'comment' => null,
                'reason' => $this->reject_reason,
                'part' => isset($recordedBy?->meta['part']) ? (int) $recordedBy->meta['part'] : null,
                // A Return rather than a Reject (RETURNABLE_STAGES), told as one.
                'returned' => $this->wasReturnedNotRejected(),
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
            // Its quotation number — each part's, on a split closed part by
            // part with different ones.
            $quotations = $this->assignees->pluck('pivot.bd_reference_code')->filter()->unique()->values();

            $entries[] = [
                'type' => 'bd_closed',
                'at' => $this->bd_closed_at,
                'actor' => $this->bdClosedBy,
                'related' => null,
                'detail' => null,
                'comment' => null,
                'quotation' => $quotations->count() > 1 ? $quotations->join(', ') : ($this->bd_reference_code ?? $quotations->first()),
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

        // The alerts sent about it — each send once, with whom it went to.
        foreach ($this->sentAlerts() as $alert) {
            $entries[] = [
                'type' => 'notification',
                'at' => $alert['at'],
                'actor' => null,
                'related' => null,
                'detail' => $alert['detail'],
                'comment' => null,
                'title' => $alert['title'],
                'tone' => $alert['tone'],
                'icon' => $alert['icon'],
                'recipients' => $alert['recipients'],
                'part' => $alert['part'] ?? null,
            ];
        }

        usort($entries, fn (array $a, array $b) => $a['at'] <=> $b['at']);

        return $entries;
    }

    /**
     * activityTimeline() as $viewer sees it: the whole of it, except for
     * someone in Sourcing or Data Entry alone
     * (User::seesOnlySourcingAndDataEntryTimeline()), who sees only Sourcing's
     * and Data Entry's part in it — their lifecycle events
     * (SOURCING_AND_DATA_ENTRY_TIMELINE_TYPES), the discussion and their own
     * actions (RfqComment::isForSourcingAndDataEntry()), and the alerts that
     * went to one of them — never another role's reviews, approvals,
     * rejections, holds or alerts. With $part, only what's about that part of
     * a split, or the RFQ as a whole (timelineEntryPart()). Each action is
     * told once (toldOnce()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function activityTimelineFor(User $viewer, ?int $part = null): array
    {
        $timeline = $this->activityTimeline();

        // On one part of a split (the RFQ's page focused on it): what's about
        // that part, and what's about the RFQ as a whole — not the other parts'.
        if ($part !== null) {
            $timeline = array_values(array_filter($timeline, fn (array $entry) => in_array(self::timelineEntryPart($entry), [null, $part], true)));
        }

        if ($viewer->seesOnlySourcingAndDataEntryTimeline()) {
            $timeline = array_values(array_filter($timeline, fn (array $entry) => match ($entry['type']) {
                'comment' => $entry['comment']->isForSourcingAndDataEntry(),
                'notification' => $entry['recipients']->contains(fn (User $recipient) => $recipient->isSourcingOrDataEntry()),
                default => in_array($entry['type'], self::SOURCING_AND_DATA_ENTRY_TIMELINE_TYPES, true),
            }));
        }

        return self::toldOnce($timeline);
    }

    /**
     * $timeline with each rejection and return told once. Sending something
     * back records it several ways at the one moment, with the one reason:
     * the action's comment ('rejected', and each part's 'returned_to_sourcing'
     * when it goes back to Sourcing — returnSourcingPart()) and the steps read
     * from the RFQ's columns ('rejected', 'sourcing_returned'). The comment
     * stands for them all — it has the photos and the Reply. A rejection's
     * comment takes in the parts' returns that went with it, naming them
     * (parts_sent_back); a step is left out where the comment recording it is
     * there. Run on what the viewer sees, so whoever isn't shown the comment
     * (Sourcing, of another role's action) still has the step.
     *
     * @param  array<int, array<string, mixed>>  $timeline
     * @return array<int, array<string, mixed>>
     */
    private static function toldOnce(array $timeline): array
    {
        $actions = collect($timeline)->where('type', 'comment')->pluck('comment')
            ->filter(fn (RfqComment $comment) => $comment->action !== null)
            ->values();

        $recorded = fn (array $entry, string $action, ?string $reason, ?int $part = null) => $actions->contains(fn (RfqComment $comment) => $comment->action === $action
            && $comment->user_id === $entry['actor']?->id
            && trim((string) $comment->body) === trim((string) $reason)
            && $comment->created_at->diffInSeconds($entry['at'], true) <= RfqComment::SAME_ACTION_SECONDS
            && ($part === null || ! isset($comment->meta['part']) || (int) $comment->meta['part'] === $part));

        $told = [];

        foreach ($timeline as $entry) {
            $isRepeat = match ($entry['type']) {
                'comment' => $entry['comment']->rejectionItBelongsTo($actions) !== null,
                'rejected' => $recorded($entry, 'rejected', $entry['reason'] ?? null),
                'sourcing_returned' => $recorded($entry, 'returned_to_sourcing', $entry['detail'], $entry['related']?->pivot?->part_number),
                default => false,
            };

            if ($isRepeat) {
                continue;
            }

            if ($entry['type'] === 'comment' && $entry['comment']->action === 'rejected') {
                $rejection = $entry['comment'];
                $entry['parts_sent_back'] = $actions
                    ->filter(fn (RfqComment $comment) => $comment->rejectionItBelongsTo(collect([$rejection])) !== null)
                    ->map(fn (RfqComment $comment) => $comment->sentBackPartContext(withLabel: ! isset($rejection->meta['part'])))
                    ->filter()
                    ->values()
                    ->all();
            }

            $told[] = $entry;
        }

        return $told;
    }

    /**
     * Which part of a split a timeline entry (activityTimeline()) is about — a
     * part's own step (its holder's), an action on one part (its comment's
     * meta part), an alert about one, or the RFQ's latest rejection of one
     * (its part) — or null for the RFQ as a whole: its own steps, the
     * discussion, actions on every part.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function timelineEntryPart(array $entry): ?int
    {
        $part = match ($entry['type']) {
            'comment' => $entry['comment']->meta['part'] ?? null,
            'notification', 'rejected' => $entry['part'] ?? null,
            // An earlier holder's assignment carries its part (activityTimeline()).
            default => $entry['part'] ?? $entry['related']?->pivot?->part_number,
        };

        return $part !== null ? (int) $part : null;
    }

    /**
     * The alerts sent about this RFQ (database notifications whose data names
     * it — rfq_id: LineItemsDiscrepancy, DataEntryPaused, DataEntryAssigned,
     * and any like them), oldest first. One alert to several people is one
     * send: its title and tone on the timeline (the class's TIMELINE_TITLE and
     * TIMELINE_TONE), what it said (its message — or the class's
     * timelineDetail()), its icon, and who it went to. The same alert sent
     * again within ALERT_REPEAT_SECONDS — the same action sent twice, a
     * double click or a retry — is the one send, too.
     *
     * @return Collection<int, array{at: Carbon, title: string, tone: string, icon: string, detail: string, recipients: Collection<int, User>}>
     */
    public function sentAlerts(): Collection
    {
        // Each alert joins the send the same alert last went into, if that
        // was moments ago — one alert to several people is sent in the one
        // moment — else starts a send of its own.
        $sends = [];
        $latest = [];

        foreach (DatabaseNotification::query()->where('data->rfq_id', $this->id)->with('notifiable')->oldest()->get() as $alert) {
            $same = $alert->type.'|'.($alert->data['part'] ?? '').'|'.($alert->data['message'] ?? '');
            $previous = $latest[$same] ?? null;
            $index = $previous !== null && $alert->created_at->diffInSeconds($previous['at'], true) <= self::ALERT_REPEAT_SECONDS
                ? $previous['index']
                : count($sends);

            $sends[$index][] = $alert;
            $latest[$same] = ['index' => $index, 'at' => $alert->created_at];
        }

        return collect($sends)
            ->map(fn (array $alerts) => collect($alerts))
            ->map(function (Collection $sent) {
                $first = $sent->first();
                $type = $first->type;
                $recipients = $sent->pluck('notifiable')->filter()->unique('id')->values();
                $names = $recipients->pluck('name')->join(', ', ' and ');

                return [
                    'at' => $first->created_at,
                    'title' => defined("{$type}::TIMELINE_TITLE") ? $type::TIMELINE_TITLE : 'Alert sent',
                    'tone' => defined("{$type}::TIMELINE_TONE") ? $type::TIMELINE_TONE : 'info',
                    'icon' => $first->data['icon'] ?? 'bi-bell',
                    'detail' => method_exists($type, 'timelineDetail') ? $type::timelineDetail($first->data, $names, $this) : (string) ($first->data['message'] ?? ''),
                    'recipients' => $recipients,
                    'part' => isset($first->data['part']) ? (int) $first->data['part'] : null,
                ];
            })
            ->values();
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
            ->whereNull('rfq_user.status')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhere('rfqs.stage', 'senior_ops_review'))
            ->tap(fn ($query) => static::excludeHeldOnAReturnsPage($query))
            ->tap(fn ($query) => static::excludeSentBackToSeniorOpsReview($query))
            ->whereNotNull('rfq_user.completed_at')
            ->whereNotNull('rfq_user.data_entry_completed_at')
            ->whereNotNull('rfq_user.finalized_at')
            ->whereNull('rfq_user.senior_ops_reviewed_at')
            ->count();

        $wholeRfqs = static::where('status', 'Pending')
            ->where('stage', 'senior_ops_review')
            ->notHeldOnAReturnsPage()
            ->notSentBackToSeniorOpsReview()
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
            ->whereNull('rfq_user.status')
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
            ->whereNull('rfq_user.status')
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
            ->whereNull('rfq_user.status')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review']))
            ->tap(fn ($query) => static::excludeHeldOnAReturnsPage($query))
            ->whereNotNull('rfq_user.gm_assistant_completed_at')
            ->whereNull('rfq_user.gm_approved_at')
            ->tap(fn ($parts) => RfqAssignment::whereNotRejectedTo($parts, 'gm_review'))
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
     * the badge on their sidebar's Ready to Close link counts — of the RFQs
     * $createdBy sees, for someone who sees only their own (scopeOwnedBy()).
     */
    public static function bdClosingCount(?int $createdBy = null): int
    {
        $parts = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->when($createdBy !== null, fn ($mine) => $mine->where(fn ($theirs) => $theirs->where('rfqs.created_by', $createdBy)->orWhereNull('rfqs.created_by')))
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.status')
            ->where(fn ($query) => $query->whereNull('rfqs.stage')->orWhereIn('rfqs.stage', ['senior_ops_review', 'head_of_bd_review', 'gm_assistant', 'gm_review', 'bd_closing']))
            ->whereNotNull('rfq_user.gm_approved_at')
            ->whereNull('rfq_user.bd_closed_at')
            ->count();

        $wholeRfqs = static::query()->ownedBy($createdBy)->where('status', 'Pending')
            ->where('stage', 'bd_closing')
            ->whereDoesntHave('assignees', fn (Builder $assignees) => RfqAssignment::whereAwaitingBdClosing($assignees))
            ->count();

        return $parts + $wholeRfqs;
    }

    /**
     * How many parts are on the General Manager's Rejected page — the ones
     * Business Development rejected back to them, waiting on them (see
     * scopeRejectedToGm()). What the badge on their sidebar's Rejected link
     * counts.
     */
    public static function gmRejectedCount(): int
    {
        return DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.status')
            ->tap(fn ($query) => static::excludeHeldOnAReturnsPage($query))
            ->whereNotNull('rfq_user.gm_assistant_completed_at')
            ->whereNull('rfq_user.gm_approved_at')
            ->tap(fn ($parts) => RfqAssignment::whereRejectedTo($parts, 'gm_review'))
            ->count();
    }

    /**
     * How many parts are on Data Entry's Rejected page — rejected back to
     * them by a reviewer and not yet sent on — of those for $userId (given
     * to them by name, or to anyone in Data Entry; null: everyone's, Admin's
     * view). What the badge on their sidebar's Rejected link counts.
     */
    public static function dataEntryRejectedCount(?int $userId): int
    {
        return DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.status')
            ->whereNotNull('rfq_user.completed_at')
            ->whereNull('rfq_user.data_entry_completed_at')
            ->tap(fn ($parts) => RfqAssignment::whereForDataEntryUser($parts, $userId))
            ->tap(fn ($parts) => RfqAssignment::whereRejectedTo($parts, 'data_entry'))
            ->count();
    }

    /**
     * How many parts are on Data Entry's Returns page — Returned to them by a
     * reviewer (RfqAssignment::whereReturnedTo()) and not yet sent on — of
     * those for $userId, as dataEntryRejectedCount(). What the badge on their
     * sidebar's Returns link counts.
     */
    public static function dataEntryReturnsCount(?int $userId): int
    {
        return DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.status')
            ->whereNotNull('rfq_user.completed_at')
            ->whereNull('rfq_user.data_entry_completed_at')
            ->tap(fn ($parts) => RfqAssignment::whereForDataEntryUser($parts, $userId))
            ->tap(fn ($parts) => RfqAssignment::whereReturnedTo($parts, 'data_entry'))
            ->count();
    }

    /**
     * How many parts are on Sourcing's Rejected page — rejected back to them
     * by a reviewer and not yet completed again — of $userId's (null:
     * everyone's, Admin's view). What the badge on their sidebar's Rejected
     * link counts.
     */
    public static function sourcingRejectedCount(?int $userId): int
    {
        return DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->when($userId !== null, fn ($mine) => $mine->where('rfq_user.user_id', $userId))
            ->whereNull('rfq_user.status')
            ->whereNull('rfq_user.completed_at')
            ->whereNotNull('rfq_user.returned_at')
            ->tap(fn ($parts) => RfqAssignment::whereRejectedTo($parts, 'sourcing'))
            ->count();
    }

    /**
     * How many RFQs are sitting on Business Development's Returns page —
     * sent back to them by Senior Operations' Get Details Again — or, with
     * $rejected, their Rejected page: rejected back to them by Senior
     * Operations' second review, the Head of Business Development, or the
     * General Manager (rejectToStage()/rejectPartToStage() targeting
     * 'business_development', scopeRejectedByAReviewer()), and not yet fixed
     * by them or carried past Senior Operations' review again. What the badge
     * on their sidebar's link counts. Unlike the other queue counts above,
     * this isn't a per-part state — reject_target_stage lives on the RFQ
     * itself — so it's just a flat count. Of the RFQs $createdBy sees, for
     * someone who sees only their own (scopeOwnedBy()).
     */
    public static function bdReturnsCount(?int $createdBy = null, bool $rejected = false): int
    {
        return static::query()->ownedBy($createdBy)->where('status', 'Pending')->where('reject_target_stage', 'business_development')->rejectedByAReviewer($rejected)->count();
    }

    /**
     * How many RFQs are sitting on Senior Operations' Returns page — a
     * Sourcing part sent back to them — or, with $rejected, their Rejected
     * page: what a reviewer rejected back to them (scopeReturnedToSeniorOperations(),
     * scopeRejectedByAReviewer()). What the badge on their sidebar's link
     * counts. A flat count, same as bdReturnsCount().
     */
    public static function seniorOpsReturnsCount(bool $rejected = false): int
    {
        return static::returnedToSeniorOperations()->rejectedByAReviewer($rejected)->count();
    }

    /**
     * How many RFQs are sitting on the Head of Business Development's Returns
     * page — what the General Manager Returned to them — or, with $rejected,
     * their Rejected page (scopeReturnedToHeadOfBd(), scopeRejectedByAReviewer()).
     * What the badge on their sidebar's link counts. A flat count, same as
     * bdReturnsCount().
     */
    public static function headOfBdReturnsCount(bool $rejected = false): int
    {
        return static::returnedToHeadOfBd()->rejectedByAReviewer($rejected)->count();
    }

    /**
     * How many RFQs are sitting on GM Assistant's Returns page — what the
     * General Manager Returned to them — or, with $rejected, their Rejected
     * page (scopeReturnedToGmAssistant()). What the badge on their sidebar's
     * link counts. A flat count, same as bdReturnsCount().
     */
    public static function gmAssistantReturnsCount(bool $rejected = false): int
    {
        return static::returnedToGmAssistant()->rejectedByAReviewer($rejected)->count();
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
            ->whereNull('rfq_user.status')
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
            ->whereNull('rfq_user.status')
            ->whereNull('rfq_user.completed_at');

        return [
            'closing' => static::bdClosingCount(),
            'bd_returns' => static::bdReturnsCount(),
            'bd_rejected' => static::bdReturnsCount(rejected: true),
            'unassigned' => static::awaitingAssignment()->count(),
            'ops_review' => static::seniorOpsReviewCount(),
            'ops_returns' => static::seniorOpsReturnsCount(),
            'ops_rejected' => static::seniorOpsReturnsCount(rejected: true),
            'sourcing_pending' => $sourcingParts()->whereNull('rfq_user.returned_at')->count() + static::awaitingFinalizeCount(),
            'sourcing_returns' => $sourcingParts()->whereNotNull('rfq_user.returned_at')->tap(fn ($parts) => RfqAssignment::whereNotRejectedTo($parts, 'sourcing'))->count(),
            'sourcing_rejected' => static::sourcingRejectedCount(null),
            'data_entry' => DB::table('rfq_user')->whereNull('status')->whereNotNull('completed_at')->whereNull('data_entry_completed_at')->tap(fn ($parts) => RfqAssignment::whereNotSentBackTo($parts, 'data_entry'))->count(),
            'data_entry_returns' => static::dataEntryReturnsCount(null),
            'data_entry_rejected' => static::dataEntryRejectedCount(null),
            'head_of_bd' => static::headOfBdReviewCount(),
            'head_of_bd_returns' => static::headOfBdReturnsCount(),
            'head_of_bd_rejected' => static::headOfBdReturnsCount(rejected: true),
            'gm_assistant' => static::gmAssistantReviewCount(),
            'gm_assistant_returns' => static::gmAssistantReturnsCount(),
            'gm_assistant_rejected' => static::gmAssistantReturnsCount(rejected: true),
            'gm_review' => static::gmReviewCount(),
            'gm_rejected' => static::gmRejectedCount(),
            // Senior Operations' stopped RFQs, and RFQs with a part stopped —
            // not waiting on anyone, so not in any heading's badge.
            'on_hold' => static::stoppedAs(self::ON_HOLD)->count(),
            'cancelled' => static::stoppedAs(self::CANCELLED)->count(),
        ];
    }

    /**
     * The next auto-generated RFQ number, e.g. "RFQ1670", "RFQ1671" — where
     * Settings → RFQ Numbers says they start (Setting::rfqNumberStart()), or
     * one past the highest existing one if that's further on: a number
     * already given is never given again. Older/manually-entered RFQ
     * numbers (including the old "WP####" ones) don't match the pattern
     * and are ignored rather than breaking the sequence.
     */
    public static function nextRfqNumber(): string
    {
        return static::formatRfqNumber(max(static::lastRfqSequence() + 1, Setting::rfqNumberStart()));
    }

    /**
     * The highest number among the RFQ numbers following the format —
     * 1669 for RFQ1669 — or 0 when there are none.
     */
    public static function lastRfqSequence(): int
    {
        $prefix = self::RFQ_NUMBER_PREFIX;

        return static::query()
            ->where('rfq_number', 'like', "{$prefix}%")
            ->pluck('rfq_number')
            ->map(fn (string $rfqNumber) => preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $rfqNumber, $matches) ? (int) $matches[1] : 0)
            ->max() ?? 0;
    }

    /**
     * $sequence as an RFQ number: 1670 is "RFQ1670", 7 is "RFQ0007".
     */
    public static function formatRfqNumber(int $sequence): string
    {
        return self::RFQ_NUMBER_PREFIX.str_pad((string) $sequence, self::RFQ_NUMBER_DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Its page (RfqController::show()) — focused on $part when it's split:
     * the progress chart then shows that part's flow alone (an Admin can
     * switch to the whole RFQ's). $status is the list it's opened from,
     * for the way back.
     */
    public function detailsUrl(?int $part = null, ?string $status = null): string
    {
        return route('admin.rfqs.show', array_filter([
            'rfq' => $this,
            'status' => $status,
            'part' => $part !== null && $this->isSplit() ? $part : null,
        ]));
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
