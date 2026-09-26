<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\RfqAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RfqController extends Controller implements HasMiddleware
{
    /**
     * The "created within" presets on Senior Operations' Unassigned and
     * Assigned tabs, in days — today counts as the first day, as it does on
     * the dashboard.
     *
     * @var array<string, int>
     */
    public const OPERATIONS_RANGES = ['today' => 1, '3d' => 3, '7d' => 7, '30d' => 30];

    /**
     * How those two tabs can be sorted.
     *
     * @var array<string, string>
     */
    public const OPERATIONS_SORTS = [
        'newest' => 'Newest first',
        'oldest' => 'Oldest first',
        'priority' => 'Highest priority first',
    ];

    public static function middleware(): array
    {
        return [
            new Middleware('permission:rfqs.view', only: ['index', 'show']),
            new Middleware('permission:rfqs.create', only: ['store']),
            new Middleware('permission:rfqs.edit', only: ['update', 'assign', 'assignOperations', 'completeSourcing', 'returnSourcing', 'completeDataEntry', 'completeSeniorOpsReview', 'approveSeniorOpsPart', 'rejectSeniorOps', 'approveHeadOfBd', 'approveHeadOfBdPart', 'rejectHeadOfBd', 'submitGmAssistantDetails', 'approveGm', 'approveGmPart', 'rejectGm', 'close', 'closePart']),
        ];
    }

    public function index(Request $request): View
    {
        $status = $request->query('status');

        if (! in_array($status, Rfq::STATUSES, true)) {
            $status = null;
        }

        $user = $request->user();

        // Admin's sidebar groups every workflow role's pages together
        // (layouts/_sidebar_admin_groups.blade.php); ?role=<slug> opens one
        // of them, and the scoping below then treats Admin as that role for
        // the page. Anyone else's ?role= is ignored.
        $lensRole = $user->hasRole('Admin') ? Rfq::workflowRoleForSlug($request->query('role')) : null;
        $actsAs = fn (string $role): bool => $user->hasRole($role) || $lensRole === $role;

        // Sourcing's queues are scoped to the signed-in member's own parts —
        // Admin, with none of their own, gets everyone's instead.
        $sourcingOverview = $lensRole === 'Sourcing' && ! $user->hasRole('Sourcing');

        // A Sourcing member's Pending list is their own queue, not the whole
        // company's backlog — scoped to RFQs they're actually assigned to.
        // Completed and the unfiltered list stay company-wide.
        //
        // When Operations splits an RFQ across several Sourcing members,
        // one of them marking it complete hands the whole RFQ to Data
        // Entry — but it must stay visible to the others too (as their own
        // split reference, see the "Handed off" note in the view), not
        // vanish from their queue just because a teammate finished first.
        //
        // "Returns" (below) is a separate lens on the same Pending status, so
        // the two are mutually exclusive rather than double-counting: a part
        // Data Entry sent back is listed there, and only there, until it's
        // completed again.
        $scopedToReturns = $actsAs('Sourcing') && $request->query('view') === 'returns';
        $scopedToMe = $status === 'Pending' && $actsAs('Sourcing') && ! $scopedToReturns;

        // Data Entry's Pending list is only what Sourcing has actually
        // handed off — RFQs still with Operations or in progress with
        // Sourcing aren't theirs to act on yet.
        $scopedToDataEntry = $status === 'Pending' && $actsAs('Data Entry');

        // Senior Operations' second review — one row per part that is both
        // Sourcing- and Data-Entry-complete, whether that's every part of an
        // RFQ or just some of a split's, each approved on its own; the RFQ
        // escalates to Head of Business Development once all of them have
        // been. A second lens on Senior Operations' own Pending status, alongside
        // "Unassigned" below — mutually exclusive via ?view=review, same
        // pattern as Sourcing's "Returns".
        $scopedToSeniorOpsReview = $status === 'Pending' && $actsAs('Senior Operations') && $request->query('view') === 'review';

        // Operations' Pending list is just their actionable backlog — RFQs
        // nobody's assigned to Sourcing yet — not every Pending RFQ in the
        // company regardless of stage. Matches the red count badge in the
        // sidebar (layouts/app.blade.php).
        $scopedToUnassigned = $status === 'Pending' && $actsAs('Senior Operations') && ! $scopedToSeniorOpsReview;

        // Head of Business Development's Pending list — one row per part
        // Senior Operations has approved, waiting on their own approve/reject
        // decision, each part as it comes rather than once the whole RFQ has
        // been approved. Unlike Senior Operations (which also has
        // "Unassigned"), this is Head of BD's only queue, so it's their whole
        // Pending page rather than a ?view= toggle.
        $scopedToHeadOfBdReview = $status === 'Pending' && $actsAs('Head of Business Development');

        // GM Assistant's Pending list — one row per part Head of Business
        // Development has approved, waiting on client details and payment
        // terms before going on to the General Manager, each part as it comes.
        // Their only queue, same as Head of Business Development above.
        $scopedToGmAssistant = $status === 'Pending' && $actsAs('GM Assistant');

        // General Manager's Pending list — one row per part GM Assistant has
        // finished adding client details/payment terms to, waiting on final
        // executive approval, each part as it comes. Their only queue, same as
        // Head of Business Development/GM Assistant above.
        $scopedToGmReview = $status === 'Pending' && $actsAs('General Manager');

        // Business Development's own reference — one row per part the General
        // Manager has approved, each as it comes, ready for BD to send to the
        // client and close out. BD also sees the full company-wide Pending list by
        // default (they may be tracking RFQs at any stage), so this is a
        // second lens via ?view=closing, same pattern as Sourcing's
        // "Returns" and Senior Operations' "Review".
        $scopedToBdClosing = $status === 'Pending' && $actsAs('Business Development') && $request->query('view') === 'closing';

        $search = $request->string('search')->trim()->toString();

        $applyCommonFilters = function ($query) use ($status, $search) {
            // The Closed list is the RFQs that have been closed and, beside
            // them, the closed parts of split RFQs still open — a part shows
            // there as soon as Business Development closes it.
            $query->when($status === 'Completed', fn ($query) => $query->closedOrWithClosedParts())
                ->when($status && $status !== 'Completed', fn ($query) => $query->where('status', $status))
                ->when($search, function ($query, $search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('wc_number', 'like', "%{$search}%")
                            ->orWhere('rfq_number', 'like', "%{$search}%")
                            ->orWhere('subject', 'like', "%{$search}%");
                    });
                });
        };

        $jobCategories = JobCategory::orderBy('name')->get();
        $sourcingUsers = User::role('Sourcing')->withSourcingWorkloadCounts()->orderBy('name')->get();

        // Senior Operations' Unassigned and Assigned tabs can be narrowed —
        // created range, priority, category, Sourcing member, part status —
        // and sorted; both tabs answer to the same filters.
        $opsFilters = $scopedToUnassigned
            ? $this->operationsFilters($request, $jobCategories->pluck('name')->all(), $sourcingUsers->pluck('id')->all())
            : null;

        $rfqs = Rfq::query()
            ->with(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy', 'seniorOpsReviewedBy', 'headOfBdApprovedBy', 'gmAssistantCompletedBy', 'gmApprovedBy', 'bdClosedBy'])
            // The quick-detail modal (Data Entry's "By Sourcing" list and
            // Sourcing's own "My Pending RFQs") shows a comment thread
            // scoped to one assignee — only worth the extra eager load on
            // those two views.
            ->when($scopedToDataEntry || (($scopedToMe || $scopedToReturns) && ! $sourcingOverview), fn ($query) => $query->with(['comments.author.roles', 'comments.replies.author.roles']))
            ->tap($applyCommonFilters)
            ->when($scopedToMe, function ($query) use ($user, $sourcingOverview) {
                // Not the parts Data Entry has sent back: those are on the
                // Returns list (below) until they're completed again.
                $query->whereHas('assignees', fn ($q) => $sourcingOverview
                    ? $q->whereNull('rfq_user.completed_at')->whereNull('rfq_user.returned_at')
                    : $q->whereKey($user->id)->tap(fn ($q) => RfqAssignment::whereNotReturned($q)));
            })
            ->when($scopedToReturns, function ($query) use ($user, $sourcingOverview) {
                $query->whereHas('assignees', function ($q) use ($user, $sourcingOverview) {
                    $q->when(! $sourcingOverview, fn ($q) => $q->whereKey($user->id))
                        ->whereNotNull('rfq_user.returned_at')
                        ->whereNull('rfq_user.completed_at');
                });
            })
            ->when($scopedToDataEntry, fn ($query) => $query->whereNotNull('sourcing_completed_at'))
            ->when($scopedToUnassigned, fn ($query) => $query->needingSourcing())
            ->when($scopedToHeadOfBdReview, fn ($query) => $query->awaitingHeadOfBdReview())
            ->when($scopedToGmAssistant, fn ($query) => $query->awaitingGmAssistant())
            ->when($scopedToGmReview, fn ($query) => $query->awaitingGmApproval())
            ->when($scopedToBdClosing, fn ($query) => $query->awaitingBdClosing())
            ->latest()
            ->when($opsFilters, fn ($query) => $this->applyOperationsFilters($query, $opsFilters))
            ->paginate(10)
            ->withQueryString();

        // Data Entry's "By Sourcing" tab shows each Sourcing assignee's
        // completed part as soon as *they* finish it — each by each —
        // rather than waiting for every assignee on a split RFQ to be
        // done, like the Detailed view (above) does. An RFQ can show up
        // here before it's fully handed off and appears there.
        $bySourcingRfqs = $scopedToDataEntry
            ? Rfq::query()
                ->with(['assignees', 'comments.author.roles', 'comments.replies.author.roles'])
                // Only assignees Sourcing has finished but Data Entry
                // hasn't processed yet — once Data Entry completes one
                // assignee's split, it drops out of this queue on its own,
                // without touching any other assignee's split on the same
                // RFQ. See Rfq::completeDataEntryPartFor().
                ->whereHas('assignees', fn ($q) => $q->whereNotNull('rfq_user.completed_at')->whereNull('rfq_user.data_entry_completed_at'))
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(10, ['*'], 'sourcing_page')
                ->withQueryString()
            : null;

        // Operations' "Assigned" tab — a reference view alongside
        // "Unassigned" (above) of what's already been routed to Sourcing
        // but is still Pending overall.
        $assignedRfqs = $scopedToUnassigned
            ? Rfq::query()
                ->with(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy'])
                ->fullySourced()
                ->tap($applyCommonFilters)
                ->latest()
                ->when($opsFilters, fn ($query) => $this->applyOperationsFilters($query, $opsFilters))
                ->paginate(10, ['*'], 'assigned_page')
                ->withQueryString()
            : null;

        // Each tab's page links keep you on that tab, whichever one you
        // asked for.
        if ($opsFilters) {
            $rfqs->appends(['tab' => 'unassigned']);
            $assignedRfqs->appends(['tab' => 'assigned']);
        }

        $seniorOpsReviewRfqs = $scopedToSeniorOpsReview
            ? Rfq::query()
                ->with(['assignees', 'creator', 'operationsAssignee', 'dataEntryCompletedBy'])
                ->awaitingSeniorOpsReview()
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(10, ['*'], 'review_page')
                ->withQueryString()
            : null;

        // Who did the step before on each part listed (id => name), for its
        // row: Data Entry on Senior Operations' page, Senior Operations on the
        // Head's, the Head on GM Assistant's, GM Assistant on the General
        // Manager's.
        $namesOfWhoDid = fn ($rfqs, string $column) => $rfqs
            ? User::whereIn('id', $rfqs->getCollection()
                ->flatMap(fn (Rfq $rfq) => $rfq->assignees->pluck("pivot.{$column}"))
                ->filter()
                ->unique())
                ->pluck('name', 'id')
            : collect();

        $dataEntryNames = $namesOfWhoDid($seniorOpsReviewRfqs, 'data_entry_completed_by');
        $seniorOpsNames = $namesOfWhoDid($scopedToHeadOfBdReview ? $rfqs : null, 'senior_ops_reviewed_by');
        $headOfBdNames = $namesOfWhoDid($scopedToGmAssistant ? $rfqs : null, 'head_of_bd_approved_by');
        $gmAssistantNames = $namesOfWhoDid($scopedToGmReview ? $rfqs : null, 'gm_assistant_completed_by');
        $gmNames = $namesOfWhoDid($scopedToBdClosing ? $rfqs : null, 'gm_approved_by');
        $bdClosedNames = $namesOfWhoDid($status === 'Completed' ? $rfqs : null, 'bd_closed_by');

        return view('admin.rfqs.index', [
            'rfqs' => $rfqs,
            'seniorOpsNames' => $seniorOpsNames,
            'headOfBdNames' => $headOfBdNames,
            'gmAssistantNames' => $gmAssistantNames,
            'gmNames' => $gmNames,
            'bdClosedNames' => $bdClosedNames,
            'bySourcingRfqs' => $bySourcingRfqs,
            'assignedRfqs' => $assignedRfqs,
            'seniorOpsReviewRfqs' => $seniorOpsReviewRfqs,
            'dataEntryNames' => $dataEntryNames,
            'search' => $search,
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'scopedToMe' => $scopedToMe,
            'scopedToReturns' => $scopedToReturns,
            'scopedToSeniorOpsReview' => $scopedToSeniorOpsReview,
            'scopedToDataEntry' => $scopedToDataEntry,
            'scopedToUnassigned' => $scopedToUnassigned,
            'scopedToHeadOfBdReview' => $scopedToHeadOfBdReview,
            'scopedToGmAssistant' => $scopedToGmAssistant,
            'scopedToGmReview' => $scopedToGmReview,
            'scopedToBdClosing' => $scopedToBdClosing,
            'lensRole' => $lensRole,
            'sourcingOverview' => $sourcingOverview,
            'opsFilters' => $opsFilters,
            'sourcingUsers' => $sourcingUsers,
            'jobCategories' => $jobCategories,
            'operationsUsers' => User::role('Senior Operations')->orderBy('name')->get(),
            'nextRfqNumber' => Rfq::nextRfqNumber(),
        ]);
    }

    /**
     * The filters on Senior Operations' Unassigned and Assigned tabs, read
     * from the query string and cleaned up — anything that isn't a known
     * value is dropped rather than trusted.
     *
     * @param  array<int, string>  $categories  the job category names that exist
     * @param  array<int, int>  $sourcingIds  the ids of the Sourcing members
     * @return array{
     *     range: string, from: ?string, to: ?string, priority: array<int, string>,
     *     category: ?string, member: ?int, part_status: ?string, sort: string,
     *     tab: string, count: int
     * }
     */
    protected function operationsFilters(Request $request, array $categories, array $sourcingIds): array
    {
        $range = (string) $request->query('range');
        $from = $this->dateFromQuery($request->query('from'));
        $to = $this->dateFromQuery($request->query('to'));

        // A custom range needs at least one end; anything unknown is "all".
        if ($range !== 'custom' || (! $from && ! $to)) {
            $range = array_key_exists($range, self::OPERATIONS_RANGES) ? $range : 'all';
        }

        if ($range !== 'custom') {
            $from = $to = null;
        } elseif ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $priority = array_values(array_intersect(Rfq::PRIORITIES, array_filter((array) $request->query('priority'), 'is_string')));

        $category = $request->query('category');
        $category = is_string($category) && in_array($category, $categories, true) ? $category : null;

        $member = $request->query('member');
        $member = is_numeric($member) && in_array((int) $member, $sourcingIds, true) ? (int) $member : null;

        $partStatus = $request->query('part_status');
        $partStatus = is_string($partStatus) && array_key_exists($partStatus, RfqAssignment::PROGRESS_LABELS) ? $partStatus : null;

        $sort = $request->query('sort');

        return [
            'range' => $range,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'priority' => $priority,
            'category' => $category,
            'member' => $member,
            'part_status' => $partStatus,
            'sort' => is_string($sort) && array_key_exists($sort, self::OPERATIONS_SORTS) ? $sort : 'newest',
            'tab' => $request->query('tab') === 'assigned' ? 'assigned' : 'unassigned',
            'count' => (int) ($range !== 'all') + (int) ($priority !== []) + (int) ($category !== null) + (int) ($member !== null) + (int) ($partStatus !== null),
        ];
    }

    /**
     * A Y-m-d date out of a query value, or null if it isn't one.
     */
    protected function dateFromQuery(mixed $value): ?Carbon
    {
        return is_string($value) && Carbon::canBeCreatedFromFormat($value, 'Y-m-d')
            ? Carbon::createFromFormat('Y-m-d', $value)->startOfDay()
            : null;
    }

    /**
     * Narrows and sorts a Senior Operations tab's RFQs by the cleaned-up
     * filters from operationsFilters().
     *
     * @param  Builder<Rfq>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyOperationsFilters(Builder $query, array $filters): void
    {
        $query
            ->when(self::OPERATIONS_RANGES[$filters['range']] ?? null, fn ($query, int $days) => $query->where('created_at', '>=', now()->subDays($days - 1)->startOfDay()))
            ->when($filters['from'], fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'], fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->when($filters['priority'], fn ($query, array $priorities) => $query->whereIn('priority_level', $priorities))
            ->when($filters['category'], fn ($query, string $category) => $query->where('category', $category))
            // Member and part status describe the same part, so they're
            // asked of one assignment together — "Riley's returned part",
            // not "Riley has a part and someone's is returned".
            ->when($filters['member'] || $filters['part_status'], fn ($query) => $query->whereHas('assignees', function ($assignees) use ($filters) {
                $assignees
                    ->when($filters['member'], fn ($assignees, int $member) => $assignees->whereKey($member))
                    ->when($filters['part_status'], fn ($assignees, string $state) => RfqAssignment::wherePartIs($assignees, $state));
            }));

        $query->reorder();

        match ($filters['sort']) {
            'oldest' => $query->oldest(),
            'priority' => $query->orderByRaw("case priority_level when 'Urgent' then 0 when 'High' then 1 when 'Medium' then 2 else 3 end")->latest(),
            default => $query->latest(),
        };
    }

    public function show(Request $request, Rfq $rfq): View
    {
        $status = $request->query('status');

        if (! in_array($status, Rfq::STATUSES, true)) {
            $status = null;
        }

        return view('admin.rfqs.show', [
            'rfq' => $rfq->load(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy', 'dataEntryCompletedBy', 'comments.author.roles', 'comments.replies.author.roles']),
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'sourcingUsers' => User::role('Sourcing')->withSourcingWorkloadCounts()->orderBy('name')->get(),
            'jobCategories' => JobCategory::orderBy('name')->get(),
            'operationsUsers' => User::role('Senior Operations')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // RFQ Number is auto-generated (WP0001, WP0002, ...), never taken
        // from user input — see Rfq::nextRfqNumber(). Nor is the status: a
        // new RFQ always starts Pending, whatever the request says.
        $validator = Validator::make($request->all(), Arr::except($this->rules(requireRfqNumber: false), 'status'));

        if ($validator->fails()) {
            return back()->withErrors($validator, 'create')->withInput();
        }

        Rfq::create([
            ...$validator->validated(),
            'rfq_number' => Rfq::nextRfqNumber(),
            'status' => 'Pending',
            'created_by' => $this->doneBy($request, 'Business Development')->id,
        ]);

        // Created from the Closed list, the new (Pending) RFQ would land out
        // of sight — show the Pending list instead.
        if ($request->input('redirect_status') === 'Completed') {
            $request->merge(['redirect_status' => 'Pending']);
        }

        return $this->redirectToIndex($request)->with('status', 'RFQ created successfully.');
    }

    public function update(Request $request, Rfq $rfq): RedirectResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return back()->withErrors($validator, 'edit')->withInput();
        }

        $rfq->update($validator->validated());

        return $this->redirectAfterSave($request, $rfq)->with('status', 'RFQ updated successfully.');
    }

    /**
     * The Assign Sourcing wizard's submit. Two passes over the same
     * endpoint:
     *
     * - First (no split planned yet): the job category, whether to split
     *   the task and into how many parts, and who takes which part. A
     *   category typed in by hand — with an optional description — is
     *   stored in job_categories so it's in the dropdown next time. Parts
     *   can be left empty.
     * - Later (split already planned): only fills parts still empty —
     *   category and split size are settled by then, and assigned parts
     *   are never reassigned here.
     *
     * Only users who actually hold the Sourcing role are ever assigned,
     * even if the request is tampered with. One person can take several
     * parts — each is its own assignment (rfq_user is one row per part),
     * shown, worked and completed separately. Sourcing itself never has
     * access to this — it's a receiving role, not an assigning one.
     *
     * An Operations member assigning Sourcing directly is implicitly the
     * one routing this RFQ — if nobody's recorded as the Operations
     * assignee yet, record them, so Operations doesn't need a separate
     * "Assign Operations" step just to name themselves.
     */
    public function assign(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_if($request->user()->hasRole('Sourcing'), 403, 'Sourcing cannot assign RFQs — that\'s Operations\' or a coordinator\'s call.');

        $planning = $rfq->split_count === null;

        $splitting = $planning && $request->boolean('split');
        $newCategory = $planning && $request->input('category') === JobCategory::NEW_OPTION;

        $validator = Validator::make($request->all(), [
            'category' => [Rule::requiredIf($planning), 'nullable', 'string', 'max:100'],
            'new_category' => [Rule::requiredIf($newCategory), 'nullable', 'string', 'max:100'],
            'new_category_description' => ['nullable', 'string', 'max:500'],
            'split' => ['nullable', 'boolean'],
            'parts' => [Rule::requiredIf($splitting), 'nullable', 'integer', 'min:2', 'max:'.Rfq::MAX_SPLIT_PARTS],
            'assignments' => ['nullable', 'array'],
            'assignments.*' => ['nullable', 'integer'],
        ]);

        $totalParts = $planning ? ($splitting ? (int) $request->input('parts') : 1) : $rfq->split_count;
        $assignments = collect($request->input('assignments', []))
            ->mapWithKeys(fn ($userId, $part) => [(int) $part => $userId ? (int) $userId : null]);

        $validator->after(function ($validator) use ($request, $planning, $splitting, $newCategory, $totalParts, $assignments) {
            if ($planning && ! $newCategory && $request->filled('category') && ! JobCategory::where('name', $request->input('category'))->exists()) {
                $validator->errors()->add('category', 'That job category doesn\'t exist.');
            }

            if ($planning && ! $splitting && ! $assignments->get(1)) {
                $validator->errors()->add('assignments', 'Pick a Sourcing member to take this task, or split it into parts.');
            }

            if ($assignments->keys()->contains(fn (int $part) => $part < 1 || $part > $totalParts)) {
                $validator->errors()->add('assignments', 'One of those parts doesn\'t exist on this RFQ.');
            }

            $pickedUserIds = $assignments->filter()->values()->unique();

            if ($pickedUserIds->diff(User::role('Sourcing')->pluck('id'))->isNotEmpty()) {
                $validator->errors()->add('assignments', 'Only users with the Sourcing role can be assigned.');
            }
        });

        if ($validator->fails()) {
            return back()->with('error', implode(' ', $validator->errors()->all()));
        }

        $user = $this->doneBy($request, 'Senior Operations');

        DB::transaction(function () use ($user, $request, $rfq, $planning, $newCategory, $totalParts, $assignments) {

            if ($planning) {
                $category = JobCategory::findOrCreateByName(
                    $newCategory ? $request->input('new_category') : $request->input('category'),
                    $user,
                    $newCategory ? $request->input('new_category_description') : null
                );

                $rfq->categorize($category->name, $user);
                $rfq->planSplit($totalParts);
            }

            $rfq->assignSourcingParts($assignments->all());

            if ($user->hasRole('Senior Operations') && ! $rfq->operations_assigned_by) {
                $rfq->update([
                    'operations_assigned_by' => $user->id,
                    'operations_assigned_at' => now(),
                ]);
            }
        });

        $remaining = $rfq->sourcingParts()->whereNull('assignee')->count();

        return $this->redirectAfterSave($request, $rfq)->with('status', $remaining === 0
            ? 'Sourcing assigned.'
            : 'Saved — '.($totalParts - $remaining).' of '.$totalParts.' parts assigned, '.$remaining.' still to assign.');
    }

    /**
     * Assign (or clear) the single Operations-team member who routed this
     * RFQ, ahead of Sourcing assignment. Only a user who actually holds the
     * Operations role is ever recorded, even if the request is tampered
     * with. Selecting "None" clears the assignment. Sourcing itself never
     * has access to this — it's a receiving role, not an assigning one.
     */
    public function assignOperations(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_if($request->user()->hasRole('Sourcing'), 403, 'Sourcing cannot assign RFQs — that\'s Operations\' or a coordinator\'s call.');

        $validated = $request->validate([
            'operations_user' => ['nullable', 'integer'],
        ]);

        $operationsUserId = $validated['operations_user'] ?? null;
        $isValidOperationsUser = $operationsUserId
            && User::role('Senior Operations')->whereKey($operationsUserId)->exists();

        $rfq->update([
            'operations_assigned_by' => $isValidOperationsUser ? $operationsUserId : null,
            'operations_assigned_at' => $isValidOperationsUser ? now() : null,
        ]);

        return $this->redirectAfterSave($request, $rfq)->with('status', 'Operations assignment updated.');
    }

    /**
     * A Sourcing member marks one of their own parts of this RFQ done, with
     * a comment for Data Entry that's posted to the RFQ's thread. Only the
     * part's assignee may do this — or Admin, on their behalf, naming that
     * member (acting_user_id) — and only once per part — the button
     * disappears once it's set. Someone holding several parts of a split
     * completes each on its own. Only once every part has been completed
     * does the RFQ actually hand off to Data Entry (see
     * Rfq::completeSourcingPart()).
     */
    public function completeSourcing(Request $request, Rfq $rfq): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $assignee = $rfq->assigneeForPart((int) $validated['part']);
        $isAdminActing = $user->hasRole('Admin') && $assignee !== null;

        abort_unless(
            ($user->hasRole('Sourcing') && $assignee?->id === $user->id) || $isAdminActing,
            403,
            'Only the Sourcing member assigned to this part can mark it complete.'
        );

        // Recorded as the assignee either way; Admin's pick just has to be them.
        abort_if(
            $isAdminActing && $request->filled('acting_user_id') && (int) $request->input('acting_user_id') !== $assignee->id,
            422,
            "That person isn't the Sourcing member assigned to this part."
        );

        $comment = $this->requiredComment($request, 'comment', 'Add a comment to mark this part complete.');

        if ($comment instanceof RedirectResponse) {
            return $comment;
        }

        $rfq->completeSourcingPart((int) $validated['part'], $comment);

        $status = $rfq->isWithDataEntry()
            ? 'Marked complete — handed off to Data Entry.'
            : ($rfq->isSplit()
                ? 'Part marked complete — waiting on the rest of the parts.'
                : 'Your part is marked complete — waiting on the rest of the Sourcing team.');

        return $this->redirectAfterSave($request, $rfq)->with('status', $status);
    }

    /**
     * Data Entry sends one Sourcing part of this RFQ back for rework, with
     * a required reason. Clears that part's completed_at (Mark Complete
     * becomes available to its assignee again, and it shows up in their
     * "Returns" list) and, if the RFQ had already fully handed off to Data
     * Entry, undoes that too — see Rfq::returnSourcingPart().
     */
    public function returnSourcing(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Data Entry', 'Admin']),
            403,
            'Only Data Entry can send an RFQ back to Sourcing.'
        );

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $assignee = $rfq->assigneeForPart((int) $validated['part']);

        abort_unless($assignee, 404, 'That part is not assigned on this RFQ.');

        $reason = $this->requiredComment($request, 'reason', 'Add a reason to send this part back.', 1000);

        if ($reason instanceof RedirectResponse) {
            return $reason;
        }

        $rfq->returnSourcingPart((int) $validated['part'], $reason, $this->doneBy($request, 'Data Entry'));

        return redirect()->back()->with('status', "Sent {$assignee->name}'s part back to Sourcing.");
    }

    /**
     * Data Entry finishes processing one Sourcing part — only that part,
     * never the others on the same RFQ (even other parts held by the same
     * person) — with a comment for Senior Operations that's posted to the
     * RFQ's thread. The RFQ as a whole only moves on to Senior Operations'
     * review once every part has been completed here. See
     * Rfq::completeDataEntryPart().
     */
    public function completeDataEntry(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Data Entry', 'Admin']),
            403,
            'Only Data Entry can complete an RFQ.'
        );

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $assignee = $rfq->assigneeForPart((int) $validated['part']);

        abort_unless($assignee, 404, 'That part is not assigned on this RFQ.');

        $comment = $this->requiredComment($request, 'comment', 'Add a comment to mark this part complete.');

        if ($comment instanceof RedirectResponse) {
            return $comment;
        }

        $rfq->completeDataEntryPart((int) $validated['part'], $this->doneBy($request, 'Data Entry'), $comment);

        return redirect()->back()->with('status', "Marked {$assignee->name}'s part complete.");
    }

    /**
     * Senior Operations approves one Sourcing part that has been through both
     * Sourcing and Data Entry — without waiting for the rest of a split. The
     * RFQ escalates to Head of Business Development once every part has been
     * approved. See Rfq::approveSeniorOpsPart().
     */
    public function approveSeniorOpsPart(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Senior Operations', 'Admin']),
            403,
            'Only Senior Operations can approve this review.'
        );

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];

        abort_unless($rfq->assigneeForPart($part), 404, 'That part is not assigned on this RFQ.');
        abort_unless($rfq->partAwaitsSeniorOpsReview($part), 422, 'This part is not awaiting Senior Operations review.');

        $rfq->approveSeniorOpsPart($part, $this->doneBy($request, 'Senior Operations'));

        return redirect()->back()->with('status', $rfq->stage === 'head_of_bd_review'
            ? 'Approved — every part is through, escalated to Head of Business Development.'
            : "Approved {$rfq->partNumberLabel($part)} — waiting on the rest of the parts.");
    }

    /**
     * Senior Operations' second review of the whole RFQ — every assignee's
     * split is both Sourcing- and Data-Entry-complete; approving here approves
     * any part not yet approved on its own and escalates the RFQ on to Head
     * of Business Development. See Rfq::completeSeniorOpsReview().
     */
    public function completeSeniorOpsReview(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Senior Operations', 'Admin']),
            403,
            'Only Senior Operations can approve this review.'
        );
        abort_unless($rfq->stage === 'senior_ops_review', 422, 'This RFQ is not awaiting Senior Operations review.');

        $rfq->completeSeniorOpsReview($this->doneBy($request, 'Senior Operations'));

        return redirect()->back()->with('status', 'Approved — escalated to Head of Business Development.');
    }

    /**
     * Senior Operations rejects from their own second review — sends the
     * RFQ (or, with a part, just that part) back to their own
     * assignment/split step. With a part, just that one part goes back —
     * see Rfq::rejectPartToStage() — otherwise the whole RFQ, which has to
     * be at their review stage — see Rfq::rejectToStage().
     */
    public function rejectSeniorOps(Request $request, Rfq $rfq): RedirectResponse
    {
        return $this->reject($request, $rfq, 'senior_ops_review', 'Senior Operations');
    }

    /**
     * Head of Business Development approves one Sourcing part Senior
     * Operations has approved — without waiting for the rest of a split. The
     * RFQ escalates to GM Assistant once every part has been approved. See
     * Rfq::approveHeadOfBdPart().
     */
    public function approveHeadOfBdPart(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Head of Business Development', 'Admin']),
            403,
            'Only Head of Business Development can approve here.'
        );

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];

        abort_unless($rfq->assigneeForPart($part), 404, 'That part is not assigned on this RFQ.');
        abort_unless($rfq->partAwaitsHeadOfBdReview($part), 422, 'This part is not awaiting Head of Business Development review.');

        $rfq->approveHeadOfBdPart($part, $this->doneBy($request, 'Head of Business Development'));

        return redirect()->back()->with('status', $rfq->stage === 'gm_assistant'
            ? 'Approved — every part is through, escalated to GM Assistant.'
            : "Approved {$rfq->partNumberLabel($part)} — waiting on the rest of the parts.");
    }

    /**
     * Head of Business Development approves the whole RFQ — any part not yet
     * approved on its own included — and escalates it on to GM Assistant. See
     * Rfq::approveByHeadOfBd().
     */
    public function approveHeadOfBd(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Head of Business Development', 'Admin']),
            403,
            'Only Head of Business Development can approve here.'
        );
        abort_unless($rfq->stage === 'head_of_bd_review', 422, 'This RFQ is not awaiting Head of Business Development review.');

        $rfq->approveByHeadOfBd($this->doneBy($request, 'Head of Business Development'));

        return redirect()->back()->with('status', 'Approved — escalated to GM Assistant.');
    }

    /**
     * Head of Business Development rejects — sends the RFQ (or, with a
     * part, just that part) back to an earlier stage with a reason. With a
     * part, just that one part goes back — see Rfq::rejectPartToStage() —
     * otherwise the whole RFQ, which has to be at their review stage — see
     * Rfq::rejectToStage().
     */
    public function rejectHeadOfBd(Request $request, Rfq $rfq): RedirectResponse
    {
        return $this->reject($request, $rfq, 'head_of_bd_review', 'Head of Business Development');
    }

    /**
     * General Manager rejects — sends the RFQ (or, with a part, just that
     * part) back to an earlier stage with a reason, all the way back
     * through GM Assistant. With a part, just that one part goes back —
     * see Rfq::rejectPartToStage() — otherwise the whole RFQ, which has to
     * be at their review stage — see Rfq::rejectToStage().
     */
    public function rejectGm(Request $request, Rfq $rfq): RedirectResponse
    {
        return $this->reject($request, $rfq, 'gm_review', 'General Manager');
    }

    /**
     * Who a stage's action is recorded as done by. Whoever does it — except
     * that Admin, who can act at every stage, may name one of the people who
     * hold that stage's role instead (the "Done by" dropdown on Admin's forms
     * and modals), so the record reads as the role's own person and not as
     * Admin. Nobody else can: the choice is ignored unless it comes from
     * Admin, and only someone who really holds $role is ever accepted.
     */
    private function doneBy(Request $request, string $role): User
    {
        $user = $request->user();

        if (! $user->hasRole('Admin') || ! $request->filled('acting_user_id')) {
            return $user;
        }

        $chosen = User::holdingRole($role)->find($request->input('acting_user_id'));

        abort_unless($chosen, 422, "That person doesn't hold the {$role} role.");

        return $chosen;
    }

    /**
     * Shared by rejectSeniorOps()/rejectHeadOfBd()/rejectGm(): the
     * three stages that can send an RFQ back to an earlier one (see
     * Rfq::REJECTABLE_STAGES) all work the same way, only $fromStage and
     * $roleName differ.
     */
    private function reject(Request $request, Rfq $rfq, string $fromStage, string $roleName): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole([$roleName, 'Admin']),
            403,
            "Only {$roleName} can reject here."
        );

        $part = $request->filled('part') ? (int) $request->input('part') : null;

        if ($part === null) {
            abort_unless($rfq->stage === $fromStage, 422, "This RFQ is not awaiting {$roleName} review.");
        } else {
            abort_unless($rfq->assigneeForPart($part), 404, 'That part is not assigned on this RFQ.');
            $awaits = match ($fromStage) {
                'senior_ops_review' => $rfq->partAwaitsSeniorOpsReview($part),
                'head_of_bd_review' => $rfq->partAwaitsHeadOfBdReview($part),
                'gm_review' => $rfq->partAwaitsGmApproval($part),
            };
            abort_unless($awaits, 422, "This part is not awaiting {$roleName} review.");
        }

        $validated = $request->validateWithBag('reject', [
            'target_stage' => ['required', 'in:'.implode(',', Rfq::rejectTargetStages($fromStage))],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ($part === null) {
            $rfq->rejectToStage($validated['target_stage'], $validated['reason'], $this->doneBy($request, $roleName), $fromStage);

            return redirect()->back()->with('status', 'Sent back to '.Rfq::stageLabel($validated['target_stage']).'.');
        }

        $rfq->rejectPartToStage($part, $validated['target_stage'], $validated['reason'], $this->doneBy($request, $roleName), $fromStage);

        return redirect()->back()->with('status', "Sent {$rfq->partNumberLabel($part)} back to ".Rfq::stageLabel($validated['target_stage']).'.');
    }

    /**
     * GM Assistant records client details and payment terms and forwards it on
     * to the General Manager. With a part, just that one part goes on — see
     * Rfq::recordGmAssistantPart() — otherwise the whole RFQ, which has to be
     * at their step — see Rfq::recordGmAssistantDetails().
     */
    public function submitGmAssistantDetails(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['GM Assistant', 'Admin']),
            403,
            'Only GM Assistant can add these details.'
        );

        $part = $request->filled('part') ? (int) $request->input('part') : null;

        if ($part === null) {
            abort_unless($rfq->stage === 'gm_assistant', 422, 'This RFQ is not awaiting GM Assistant details.');
        } else {
            abort_unless($rfq->assigneeForPart($part), 404, 'That part is not assigned on this RFQ.');
            abort_unless($rfq->partAwaitsGmAssistant($part), 422, 'This part is not awaiting GM Assistant details.');
        }

        $validated = $request->validateWithBag('gm_assistant', [
            'client_details' => ['required', 'string', 'max:2000'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($part === null) {
            $rfq->recordGmAssistantDetails($this->doneBy($request, 'GM Assistant'), $validated['client_details'], $validated['payment_terms'] ?? null);

            return redirect()->back()->with('status', 'Forwarded to General Manager.');
        }

        $rfq->recordGmAssistantPart($part, $this->doneBy($request, 'GM Assistant'), $validated['client_details'], $validated['payment_terms'] ?? null);

        return redirect()->back()->with('status', $rfq->stage === 'gm_review'
            ? 'Details added — every part is through, forwarded to General Manager.'
            : "Details added for {$rfq->partNumberLabel($part)} — forwarded to General Manager.");
    }

    /**
     * The General Manager approves one Sourcing part GM Assistant has
     * completed — without waiting for the rest of a split. The RFQ is ready for
     * Business Development to close once every part has been approved. See
     * Rfq::approveGmPart().
     */
    public function approveGmPart(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['General Manager', 'Admin']),
            403,
            'Only the General Manager can give final approval.'
        );

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];

        abort_unless($rfq->assigneeForPart($part), 404, 'That part is not assigned on this RFQ.');
        abort_unless($rfq->partAwaitsGmApproval($part), 422, 'This part is not awaiting General Manager approval.');

        $rfq->approveGmPart($part, $this->doneBy($request, 'General Manager'));

        return redirect()->back()->with('status', $rfq->stage === 'bd_closing'
            ? 'Approved — every part is through, ready for Business Development to close.'
            : "Approved {$rfq->partNumberLabel($part)} — waiting on the rest of the parts.");
    }

    /**
     * General Manager gives final approval to the whole RFQ — any part not yet
     * approved on its own included — and it's ready for Business Development
     * to close out. See Rfq::approveByGm().
     */
    public function approveGm(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['General Manager', 'Admin']),
            403,
            'Only the General Manager can give final approval.'
        );
        abort_unless($rfq->stage === 'gm_review', 422, 'This RFQ is not awaiting General Manager approval.');

        $rfq->approveByGm($this->doneBy($request, 'General Manager'));

        return redirect()->back()->with('status', 'Approved — ready for Business Development to close.');
    }

    /**
     * Business Development closes one Sourcing part the General Manager has
     * approved — without waiting for the rest of a split. The RFQ closes once
     * every part has been. See Rfq::closePart().
     */
    public function closePart(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Business Development', 'Admin']),
            403,
            'Only Business Development can close an RFQ.'
        );

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];

        abort_unless($rfq->assigneeForPart($part), 404, 'That part is not assigned on this RFQ.');
        abort_unless($rfq->partAwaitsBdClosing($part), 422, 'This part is not ready to close.');

        $rfq->closePart($part, $this->doneBy($request, 'Business Development'));

        // Fireworks: a modest show for a part, a grand one when that was the
        // last and the RFQ itself is closed.
        $celebration = match (true) {
            $rfq->stage === 'closed' && $rfq->isSplit() => $this->celebration($rfq->rfq_number, "All {$rfq->splitTotal()} parts are closed — the whole RFQ is done.", grand: true),
            $rfq->stage === 'closed' => $this->celebration($rfq->rfq_number, "It's now in Closed RFQs.", grand: true),
            default => $this->celebration(
                $rfq->partNumberLabel($part),
                "It's now in Closed RFQs — {$rfq->assignees->filter(fn (User $assignee) => $assignee->pivot->isBdClosed())->count()} of {$rfq->splitTotal()} parts closed.",
                grand: false,
            ),
        };

        return redirect()->back()->with('celebrate', $celebration)->with('status', match (true) {
            $rfq->stage === 'closed' && $rfq->isSplit() => "Closed {$rfq->partNumberLabel($part)} — every part is closed, so the RFQ is closed.",
            $rfq->stage === 'closed' => 'RFQ closed.',
            default => "Closed {$rfq->partNumberLabel($part)} — it's now in Closed RFQs.",
        });
    }

    /**
     * Business Development formally closes this whole RFQ out — any part not
     * yet closed on its own included — the true end of the lifecycle. See
     * Rfq::closeOut().
     */
    public function close(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Business Development', 'Admin']),
            403,
            'Only Business Development can close an RFQ.'
        );
        abort_unless($rfq->stage === 'bd_closing', 422, 'This RFQ is not ready to close.');

        $rfq->closeOut($this->doneBy($request, 'Business Development'));

        return redirect()->back()
            ->with('celebrate', $this->celebration($rfq->rfq_number, "It's now in Closed RFQs.", grand: true))
            ->with('status', 'RFQ closed.');
    }

    /**
     * What the fireworks overlay says when something has been closed — see
     * layouts/_celebration.blade.php and public/js/fireworks.js. $grand is the
     * bigger show, for a whole RFQ closing rather than one part of it.
     *
     * @return array{title: string, label: string, message: string, grand: bool, url: string}
     */
    private function celebration(string $label, string $message, bool $grand): array
    {
        return [
            'title' => $grand ? 'RFQ closed!' : 'Closed!',
            'label' => $label,
            'message' => $message,
            'grand' => $grand,
            'url' => route('admin.rfqs.index', ['status' => 'Completed']),
        ];
    }

    /**
     * The comment that has to go with completing a part (Sourcing's or Data
     * Entry's) or sending one back (its reason) — or, if it's missing or too
     * long, the redirect back saying so. Asked for by the one prompt modal on
     * whichever page this came from, so a miss is flashed like the Assign
     * Sourcing wizard's errors rather than shown against a field.
     *
     * @param  string  $field  the request field it arrives in
     * @param  string  $missing  what to say when it isn't there
     */
    protected function requiredComment(Request $request, string $field, string $missing, int $max = 2000): string|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            $field => ['required', 'string', 'max:'.$max],
        ], [
            "{$field}.required" => $missing,
            "{$field}.max" => "That comment is too long — keep it under {$max} characters.",
        ]);

        return $validator->fails()
            ? back()->with('error', $validator->errors()->first())
            : $validator->validated()[$field];
    }

    /**
     * After an edit or assignment, send the user back to the RFQ's detail
     * page or their dashboard if that's where they started, otherwise back
     * to the list.
     */
    protected function redirectAfterSave(Request $request, Rfq $rfq): RedirectResponse
    {
        if ($request->input('return_to') === 'show') {
            return redirect()->route('admin.rfqs.show', $rfq);
        }

        if ($request->input('return_to') === 'dashboard') {
            return redirect()->route('admin.dashboard');
        }

        return $this->redirectToIndex($request);
    }

    /**
     * Send the user back to whichever RFQ list (all / pending / completed)
     * they were on, rather than always dropping them on the unfiltered list —
     * and, for Admin, on whichever role's pages (?role=, ?view=) they were
     * looking at. See admin/rfqs/_redirect_fields.blade.php.
     */
    protected function redirectToIndex(Request $request): RedirectResponse
    {
        $status = $request->input('redirect_status');
        $parameters = in_array($status, Rfq::STATUSES, true) ? ['status' => $status] : [];

        $role = $request->user()->hasRole('Admin') ? Rfq::workflowRoleForSlug($request->input('redirect_role')) : null;

        if ($role) {
            $parameters['role'] = Str::slug($role);

            if (in_array($request->input('redirect_view'), Rfq::QUEUE_VIEWS, true)) {
                $parameters['view'] = $request->input('redirect_view');
            }
        } elseif ($request->user()->hasRole('Sourcing') && $request->input('redirect_view') === 'returns') {
            // Reworking a returned part from the Returns list: back there.
            $parameters['view'] = 'returns';
        }

        return redirect()->route('admin.rfqs.index', $parameters);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(bool $requireRfqNumber = true): array
    {
        return [
            'wc_number' => ['required', 'string', 'max:255'],
            'rfq_number' => [$requireRfqNumber ? 'required' : 'nullable', 'string', 'max:255'],
            'priority_level' => ['required', 'string', 'in:'.implode(',', Rfq::PRIORITIES)],
            'status' => ['required', 'string', 'in:'.implode(',', Rfq::STATUSES)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
