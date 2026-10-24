<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExtraWorkingHour;
use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\RfqAssignment;
use App\Models\RfqComment;
use App\Models\RfqReturn;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkingSchedule;
use App\Notifications\DataEntryAssigned;
use App\Notifications\DataEntryPaused;
use App\Notifications\LineItemsDiscrepancy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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
     * The "created within" presets on the bar above every other RFQ list
     * (_list_filters.blade.php) — some of OPERATIONS_RANGES, by what the bar
     * calls them. A month is the last 30 days.
     *
     * @var array<string, string>
     */
    public const LIST_RANGES = ['today' => 'Today', '3d' => '3 days', '30d' => 'Month'];

    /**
     * What a plain RFQ list — every one but a role's own queue, which has a
     * layout of its own — can be grouped by (?group=, the filter bar), and
     * what the bar calls each. See listGroupKey().
     *
     * @var array<string, string>
     */
    public const LIST_GROUPS = ['wc' => 'WC number', 'priority' => 'Priority', 'status' => 'Status'];

    /**
     * What All RFQs — the list with no status or role asked for
     * (User::canListAllRfqs()) — can be narrowed to (?show=, the filter bar):
     * where the General Manager's dashboard cards lead
     * (dashboard/_approver), each listing the RFQs its card counted, and
     * the open RFQs Sourcing hasn't finalized yet. The decisions and the
     * closed ones over the card's dates (?show_from, ?show_to). See
     * allRfqsView(), applyAllRfqsView().
     *
     * @var array<string, string>
     */
    public const ALL_RFQS_VIEWS = [
        'open' => 'Open RFQs',
        'not_finalized' => 'Not finalized by Sourcing',
        'awaiting_gm' => 'Awaiting the General Manager',
        'gm_approved' => 'Approved by the General Manager',
        'gm_returned' => 'Sent back by the General Manager',
        'closed' => 'Closed RFQs',
    ];

    /**
     * The views in ALL_RFQS_VIEWS that are over dates — the rest are as of now.
     *
     * @var array<int, string>
     */
    public const DATED_ALL_RFQS_VIEWS = ['gm_approved', 'gm_returned', 'closed'];

    /**
     * How many RFQs the dashboard's WC number search suggests at most.
     */
    public const SUGGEST_LIMIT = 8;

    /**
     * How many RFQs a list shows a page — All RFQs, every role's queue, and
     * each of their tabs.
     */
    public const PER_PAGE = 20;

    /**
     * The most entries Data Entry can say they made on one part
     * (completeDataEntry()).
     */
    public const MAX_DATA_ENTRIES = 100000;

    /**
     * The most line items Sourcing can say Data Entry has to add for one part
     * (completeSourcing()).
     */
    public const MAX_LINE_ITEMS = 100000;

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
            new Middleware('permission:rfqs.view', only: ['index', 'show', 'suggest']),
            new Middleware('permission:rfqs.create', only: ['store']),
            new Middleware('permission:rfqs.edit', only: ['update', 'assign', 'assignOperations', 'completeSourcing', 'returnSourcing', 'completeDataEntry', 'completeSeniorOpsReview', 'approveSeniorOpsPart', 'rejectSeniorOps', 'approveHeadOfBd', 'approveHeadOfBdPart', 'rejectHeadOfBd', 'submitGmAssistantDetails', 'approveGm', 'approveGmPart', 'rejectGm', 'rejectGmAssistant', 'rejectBd', 'returnSeniorOps', 'passBackToSourcing', 'reassignSourcing', 'startDataEntry', 'startGmAssistant', 'close', 'closePart', 'changeStatus']),
        ];
    }

    /**
     * Suggestions for the WC number search on the dashboards
     * (dashboard/_search.blade.php, public/js/wc-search.js): the RFQs whose
     * WC number has what's typed in it — spaces and case aside — those
     * starting with it first, then the newest; up to SUGGEST_LIMIT of them,
     * each with what to show and where it opens; how many match in all; and
     * the RFQ list searched for it, to see them all.
     */
    public function suggest(Request $request): JsonResponse
    {
        $term = strtoupper(trim($request->string('q')->toString()));

        if ($term === '') {
            return response()->json(['term' => '', 'total' => 0, 'results' => [], 'all' => route('admin.rfqs.index')]);
        }

        // "!" escapes LIKE's own wildcards in what was typed — the same on SQLite and MySQL.
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $wc = 'upper(trim(rfqs.wc_number))';
        // Only theirs, for whoever doesn't see every RFQ (Rfq::scopeVisibleTo()).
        $matching = Rfq::query()->visibleTo($request->user())->whereRaw("{$wc} like ? escape '!'", ['%'.$escaped.'%']);

        return response()->json([
            'term' => $term,
            'total' => (clone $matching)->count(),
            'results' => $matching
                ->orderByRaw("case when {$wc} like ? escape '!' then 0 else 1 end", [$escaped.'%'])
                ->latest()
                ->limit(self::SUGGEST_LIMIT)
                ->get()
                ->map(fn (Rfq $rfq) => [
                    'wc' => $rfq->wc_number,
                    'rfq' => $rfq->rfq_number,
                    'subject' => $rfq->subject,
                    'priority' => $rfq->priorityLabel(),
                    'priorityClass' => $rfq->priorityBadgeClass(),
                    'status' => $rfq->statusLabel(),
                    'statusClass' => $rfq->statusBadgeClass(),
                    'url' => route('admin.rfqs.show', $rfq),
                ]),
            'all' => route('admin.rfqs.index', ['search' => $term]),
        ]);
    }

    public function index(Request $request): View
    {
        $status = $request->query('status');

        if (! in_array($status, Rfq::LIST_STATUSES, true)) {
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
        // Only what's theirs to act on: a part still with them, and one Data
        // Entry has sent back for them to finalize. Once they've assigned it
        // to Data Entry it leaves the list till then — each part on its own,
        // whatever a teammate's done with theirs.
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

        // Senior Operations' Returns page — RFQs sent back to them, to their
        // assignment step or their second review, by that review itself, the
        // Head of Business Development, or the General Manager, while still
        // waiting on them (Rfq::scopeReturnedToSeniorOperations()). A third
        // lens on their Pending status, via ?view=returns, same pattern as
        // Business Development's Returns.
        $scopedToSeniorOpsReturns = $status === 'Pending' && $actsAs('Senior Operations') && $request->query('view') === 'returns';

        // Operations' Pending list is just their actionable backlog — RFQs
        // nobody's assigned to Sourcing yet, but not those sent back to them,
        // which are on their Returns page (Rfq::scopeAwaitingAssignment()) —
        // not every Pending RFQ in the company regardless of stage. Matches
        // the red count badge in the sidebar (layouts/app.blade.php).
        $scopedToUnassigned = $status === 'Pending' && $actsAs('Senior Operations') && ! $scopedToSeniorOpsReview && ! $scopedToSeniorOpsReturns;

        // Head of Business Development's Returns page — RFQs the General
        // Manager sent back to their review, while still waiting on it
        // (Rfq::scopeReturnedToHeadOfBd()), decided right there and listed
        // only there, not on their Review page as well. A second lens on
        // their Pending status via ?view=returns.
        $scopedToHeadOfBdReturns = $status === 'Pending' && $actsAs('Head of Business Development') && $request->query('view') === 'returns';

        // Head of Business Development's Pending list — one row per part
        // Senior Operations has approved, waiting on their own approve/reject
        // decision, each part as it comes rather than once the whole RFQ has
        // been approved. Their main queue — the whole Pending page, but for
        // their Returns (above).
        $scopedToHeadOfBdReview = $status === 'Pending' && $actsAs('Head of Business Development') && ! $scopedToHeadOfBdReturns;

        // GM Assistant's Returns page — RFQs the General Manager sent back to
        // their step, while still waiting on them (Rfq::scopeReturnedToGmAssistant()),
        // dealt with right there and listed only there. A second lens on their
        // Pending status via ?view=returns, same as the Head's.
        $scopedToGmAssistantReturns = $status === 'Pending' && $actsAs('GM Assistant') && $request->query('view') === 'returns';

        // GM Assistant's Pending list — one row per part Head of Business
        // Development has approved, waiting on their Submit before going on to
        // the General Manager, each part as it comes.
        // Their main queue — the whole Pending page, but for their Returns.
        $scopedToGmAssistant = $status === 'Pending' && $actsAs('GM Assistant') && ! $scopedToGmAssistantReturns;

        // General Manager's Pending list — one row per part GM Assistant has
        // submitted, waiting on final
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

        // Business Development's Returns page — RFQs Senior Operations'
        // second review, the Head of Business Development, or the General
        // Manager has sent all the way back to them (Rfq::rejectToStage()/
        // rejectPartToStage() targeting 'business_development'). A third
        // lens on their Pending status, same pattern as "Closing" above.
        $scopedToBdReturns = $status === 'Pending' && $actsAs('Business Development') && $request->query('view') === 'returns';

        // A Sourcing member's Closed RFQs are their own: the RFQs they hold a
        // part of that have been closed — their part, or the whole RFQ.
        // Admin's view of Sourcing's pages ($sourcingOverview) is everyone's.
        $scopedToMyClosed = $status === 'Completed' && $user->hasRole('Sourcing');

        $search = $request->string('search')->trim()->toString();

        // When the RFQs were created, and how urgent they are, from the bar
        // above the list — except on Senior Operations' Unassigned page,
        // whose own filters (below) ask both.
        $listRange = $scopedToUnassigned ? null : $this->createdRange($request, array_intersect_key(self::OPERATIONS_RANGES, self::LIST_RANGES));
        $listPriority = $scopedToUnassigned ? null : $this->prioritiesFromQuery($request);

        // A plain list — not one of the role queues below, each laid out its
        // own way — can be grouped (?group=): ordered by the group, the RFQs
        // of each together, with a heading for each group (listGroupKey()).
        $canGroup = ! (($sourcingOverview && ($scopedToMe || $scopedToReturns)) || $scopedToDataEntry || $scopedToReturns
            || $scopedToSeniorOpsReview || $scopedToHeadOfBdReview || $scopedToHeadOfBdReturns || $scopedToGmAssistant
            || $scopedToGmAssistantReturns || $scopedToGmReview || $scopedToSeniorOpsReturns || $scopedToBdReturns
            || $scopedToBdClosing || $scopedToMe || $scopedToUnassigned);
        $groupBy = $canGroup && array_key_exists((string) $request->query('group'), self::LIST_GROUPS) ? (string) $request->query('group') : null;

        // All RFQs, narrowed to what a General Manager's dashboard card
        // counted (ALL_RFQS_VIEWS) — on that page alone.
        $isAllRfqsPage = $status === null && $lensRole === null && $user->canListAllRfqs();
        $allRfqsView = $isAllRfqsPage ? $this->allRfqsView($request, $user) : null;

        $applySearch = function ($query) use ($search, $listRange, $listPriority) {
            $query->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('wc_number', 'like', "%{$search}%")
                        ->orWhere('rfq_number', 'like', "%{$search}%")
                        ->orWhere('subject', 'like', "%{$search}%");
                });
            })
                ->when($listRange, fn ($query, array $range) => $this->applyCreatedRange($query, $range))
                ->when($listPriority, fn ($query, array $priorities) => $query->whereIn('rfqs.priority_level', $priorities));
        };

        // Business Development sees only the RFQs they created, and Data Entry
        // only what Sourcing gave them — every list of theirs, Closed
        // included (Rfq::scopeVisibleTo()).
        $applyCommonFilters = function ($query) use ($status, $applySearch, $user) {
            // The Closed list is the RFQs that have been closed and, beside
            // them, the closed parts of split RFQs still open — a part shows
            // there as soon as Business Development closes it.
            // On Hold and Cancelled list the RFQs with a part stopped on its own
            // too — see Rfq::scopeStoppedAs().
            $query->visibleTo($user)
                ->when($status === 'Completed', fn ($query) => $query->closedOrWithClosedParts())
                ->when(in_array($status, [Rfq::ON_HOLD, Rfq::CANCELLED], true), fn ($query) => $query->stoppedAs($status))
                ->when($status === 'Pending', fn ($query) => $query->where('status', $status))
                ->tap($applySearch);
        };

        $jobCategories = JobCategory::orderBy('name')->get();
        $sourcingUsers = User::role('Sourcing')->active()->withSourcingWorkloadCounts()->orderBy('name')->get();

        // Senior Operations' Unassigned and Assigned tabs can be narrowed —
        // created range, priority, category, Sourcing member, part status —
        // and sorted; both tabs answer to the same filters.
        $opsFilters = $scopedToUnassigned
            ? $this->operationsFilters($request, $jobCategories->pluck('name')->all(), $sourcingUsers->pluck('id')->all())
            : null;

        $rfqsQuery = Rfq::query()
            ->with(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy', 'seniorOpsReviewedBy', 'headOfBdApprovedBy', 'gmAssistantCompletedBy', 'gmApprovedBy', 'bdClosedBy'])
            // The quick-detail modal (Data Entry's "By Sourcing" list and
            // Sourcing's own "My Pending RFQs") shows a comment thread
            // scoped to one assignee — only worth the extra eager load on
            // those two views.
            ->when($scopedToDataEntry || (($scopedToMe || $scopedToReturns) && ! $sourcingOverview), fn ($query) => $query->with(['comments.author.roles', 'comments.replies.author.roles', 'comments.attachments', 'comments.replies.attachments']))
            ->when($scopedToBdReturns || $scopedToSeniorOpsReturns || $scopedToHeadOfBdReturns || $scopedToGmAssistantReturns, fn ($query) => $query->with(['rejectedBy', 'latestRejection.attachments']))
            // A Sourcing member's own Pending list counts each part down, and
            // so does their Returns list — a part sent back carries on from
            // where it was, so every Sourcing stretch of it counts
            // (Rfq::sourcingCountdown()), and Finalize's round its own.
            ->when(($scopedToMe || $scopedToReturns) && ! $sourcingOverview, fn ($query) => $query->with(['steps' => fn ($steps) => $steps->whereIn('step', ['sourcing', 'finalize'])]))
            // GM Assistant's Review and Returns lists count each part down
            // against their target (Rfq::gmAssistantCountdown()).
            ->when($scopedToGmAssistant || $scopedToGmAssistantReturns, fn ($query) => $query->with(['steps' => fn ($steps) => $steps->where('step', 'gm_assistant')]))
            ->tap($applyCommonFilters)
            ->when($scopedToMe, function ($query) use ($user, $sourcingOverview) {
                // A part still with its member, or waiting on their Finalize —
                // not one assigned to Data Entry (until it's sent back to
                // finalize), nor one Data Entry sent back: that's on the
                // Returns list (below) until it's completed again. Each
                // member's own; Admin's overview, everyone's. A part stopped
                // on its own is on nobody's list.
                $query->whereHas('assignees', fn ($q) => $q
                    ->when(! $sourcingOverview, fn ($mine) => $mine->whereKey($user->id))
                    ->whereNull('rfq_user.status')
                    ->where(fn ($parts) => $parts
                        ->where(fn ($open) => $open->whereNull('rfq_user.completed_at')->whereNull('rfq_user.returned_at'))
                        ->orWhere(fn ($finalizing) => RfqAssignment::whereAwaitingFinalize($finalizing))));
            })
            ->when($scopedToReturns, function ($query) use ($user, $sourcingOverview) {
                $query->whereHas('assignees', function ($q) use ($user, $sourcingOverview) {
                    $q->when(! $sourcingOverview, fn ($q) => $q->whereKey($user->id))
                        ->whereNull('rfq_user.status')
                        ->whereNotNull('rfq_user.returned_at')
                        ->whereNull('rfq_user.completed_at');
                });
            })
            ->when($scopedToDataEntry, fn ($query) => $query->whereNotNull('sourcing_completed_at'))
            ->when($scopedToUnassigned, fn ($query) => $query->awaitingAssignment())
            ->when($scopedToHeadOfBdReview, fn ($query) => $query->awaitingHeadOfBdReview()->notHeldOnAReturnsPage())
            // An RFQ sent back to the Head of Business Development or GM
            // Assistant is on their Returns page and nobody's review page
            // until they've dealt with it — see Rfq::scopeNotHeldOnAReturnsPage().
            ->when($scopedToGmAssistant, fn ($query) => $query->awaitingGmAssistant()->notHeldOnAReturnsPage())
            ->when($scopedToGmReview, fn ($query) => $query->awaitingGmApproval()->notHeldOnAReturnsPage())
            ->when($scopedToBdClosing, fn ($query) => $query->awaitingBdClosing())
            ->when($scopedToBdReturns, fn ($query) => $query->where('reject_target_stage', 'business_development'))
            ->when($scopedToSeniorOpsReturns, fn ($query) => $query->returnedToSeniorOperations())
            ->when($scopedToHeadOfBdReturns, fn ($query) => $query->returnedToHeadOfBd())
            ->when($scopedToGmAssistantReturns, fn ($query) => $query->returnedToGmAssistant())
            ->when($scopedToMyClosed, fn ($query) => $query->where(fn ($mine) => $mine
                ->where(fn ($closedWhole) => $closedWhole
                    ->where('rfqs.status', 'Completed')
                    ->whereHas('assignees', fn ($parts) => $parts->whereKey($user->id)))
                ->orWhereHas('assignees', fn ($parts) => $parts->whereKey($user->id)->whereNotNull('rfq_user.bd_closed_at'))))
            ->when($allRfqsView, fn ($query, array $view) => $this->applyAllRfqsView($query, $view))
            ->when($groupBy, fn ($query, string $group) => $query->orderByRaw($this->listGroupOrder($group)))
            ->latest()
            ->when($opsFilters, fn ($query) => $this->applyOperationsFilters($query, $opsFilters));

        // How many RFQs each group has across the whole list, not just this page.
        $groupTotals = $groupBy
            ? (clone $rfqsQuery)->reorder()->toBase()
                ->selectRaw($this->listGroupKey($groupBy).' as group_key, count(*) as total')
                ->groupBy('group_key')
                ->pluck('total', 'group_key')
            : collect();

        $rfqs = $rfqsQuery->paginate(self::PER_PAGE)->withQueryString();

        // Data Entry's "By Sourcing" tab shows each Sourcing assignee's
        // completed part as soon as *they* finish it — each by each —
        // rather than waiting for every assignee on a split RFQ to be
        // done, like the Detailed view (above) does. An RFQ can show up
        // here before it's fully handed off and appears there.
        // Whose Ready list it is: theirs, or — Admin's view — everyone's.
        $dataEntryFor = $user->hasRole('Admin') ? null : $user->id;

        $bySourcingRfqs = $scopedToDataEntry
            ? Rfq::query()
                ->with(['assignees', 'comments.author.roles', 'comments.replies.author.roles', 'comments.attachments', 'comments.replies.attachments'])
                // Each started part counts down — its Data Entry rounds are
                // what that runs from (Rfq::dataEntryCountdown()).
                ->with(['steps' => fn ($steps) => $steps->where('step', 'data_entry')])
                // Only assignees Sourcing has finished but Data Entry
                // hasn't processed yet — once Data Entry completes one
                // assignee's split, it drops out of this queue on its own,
                // without touching any other assignee's split on the same
                // RFQ. See Rfq::completeDataEntryPartFor().
                // And only theirs: given to them by name, or to anyone in Data
                // Entry — Admin's view of the page, everyone's.
                ->whereHas('assignees', fn ($q) => $q->whereNull('rfq_user.status')->whereNotNull('rfq_user.completed_at')->whereNull('rfq_user.data_entry_completed_at')
                    ->tap(fn ($parts) => RfqAssignment::whereForDataEntryUser($parts, $dataEntryFor)))
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(self::PER_PAGE, ['*'], 'sourcing_page')
                ->withQueryString()
            : null;

        // Data Entry's page has two tabs, like Senior Operations' (below):
        // Ready (above), and Completed (?tab=completed) — the parts they've
        // sent to finalize, their own (on Admin's view of the page, everyone's),
        // latest first, whatever's become of them since. Each tab counts its
        // parts, the search narrowing both.
        $dataEntryTab = $request->query('tab') === 'completed' ? 'completed' : 'ready';
        $dataEntryCompletedBy = $user->hasRole('Admin') ? null : $user->id;
        $isDataEntryDone = fn ($parts) => $parts->whereNotNull('rfq_user.data_entry_completed_at')
            ->when($dataEntryCompletedBy, fn ($mine) => $mine->where('rfq_user.data_entry_completed_by', $dataEntryCompletedBy));

        $dataEntryCompletedRfqs = $scopedToDataEntry
            ? Rfq::query()
                ->with('assignees')
                ->whereHas('assignees', $isDataEntryDone)
                ->tap($applySearch)
                ->orderByDesc(DB::table('rfq_user')->selectRaw('max(rfq_user.data_entry_completed_at)')->whereColumn('rfq_user.rfq_id', 'rfqs.id')->tap($isDataEntryDone))
                ->paginate(self::PER_PAGE, ['*'], 'completed_page')
                ->withQueryString()
                ->appends(['tab' => 'completed'])
            : null;

        $dataEntryCounts = $scopedToDataEntry ? [
            'ready' => DB::table('rfq_user')
                ->whereIn('rfq_id', Rfq::query()->select('rfqs.id')->where('rfqs.status', 'Pending')->tap($applySearch))
                ->whereNull('status')->whereNotNull('completed_at')->whereNull('data_entry_completed_at')
                ->tap(fn ($parts) => RfqAssignment::whereForDataEntryUser($parts, $dataEntryFor))
                ->count(),
            'completed' => DB::table('rfq_user')
                ->whereIn('rfq_id', Rfq::query()->select('rfqs.id')->tap($applySearch))
                ->tap($isDataEntryDone)
                ->count(),
        ] : null;

        $bySourcingRfqs?->appends(['tab' => 'ready']);

        // A Sourcing member's My Pending RFQs has three tabs: Pending for
        // Sourcing — what's theirs to complete or finalize ($rfqs, above);
        // With Data Entry (?tab=with-data-entry) — their parts assigned to
        // Data Entry and not yet sent back to finalize, to follow; and
        // Finalized (?tab=finalized) — the parts they've finalized, on their
        // way through the reviews, till the RFQ's closed. Each counts its
        // parts, the search narrowing all three. Admin's overview of Sourcing
        // has just the one.
        $ownSourcingTabs = $scopedToMe && ! $sourcingOverview;
        $sourcingTab = $ownSourcingTabs && in_array($request->query('tab'), ['with-data-entry', 'finalized'], true) ? $request->query('tab') : 'todo';
        $isMineWithDataEntry = fn ($parts) => $parts->where('rfq_user.user_id', $user->id)
            ->whereNull('rfq_user.status')
            ->whereNotNull('rfq_user.completed_at')
            ->whereNull('rfq_user.data_entry_completed_at');

        $withDataEntryRfqs = $ownSourcingTabs
            ? Rfq::query()
                ->with(['assignees', 'steps' => fn ($steps) => $steps->where('step', 'data_entry')])
                ->whereHas('assignees', $isMineWithDataEntry)
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(self::PER_PAGE, ['*'], 'with_data_entry_page')
                ->withQueryString()
                ->appends(['tab' => 'with-data-entry'])
            : null;

        $isMineFinalized = fn ($parts) => $parts->where('rfq_user.user_id', $user->id)
            ->whereNull('rfq_user.status')
            ->whereNotNull('rfq_user.finalized_at');

        $finalizedRfqs = $ownSourcingTabs
            ? Rfq::query()
                ->with('assignees')
                ->whereHas('assignees', $isMineFinalized)
                ->tap($applyCommonFilters)
                ->orderByDesc(DB::table('rfq_user')->selectRaw('max(rfq_user.finalized_at)')->whereColumn('rfq_user.rfq_id', 'rfqs.id')->where('rfq_user.user_id', $user->id))
                ->paginate(self::PER_PAGE, ['*'], 'finalized_page')
                ->withQueryString()
                ->appends(['tab' => 'finalized'])
            : null;

        $sourcingCounts = $ownSourcingTabs ? [
            'todo' => DB::table('rfq_user')
                ->whereIn('rfq_id', Rfq::query()->select('rfqs.id')->tap($applyCommonFilters))
                ->where('user_id', $user->id)
                ->whereNull('status')
                ->where(fn ($parts) => $parts
                    ->where(fn ($open) => $open->whereNull('completed_at')->whereNull('returned_at'))
                    ->orWhere(fn ($finalizing) => $finalizing->whereNotNull('data_entry_completed_at')->whereNull('finalized_at')))
                ->count(),
            'with_data_entry' => DB::table('rfq_user')
                ->whereIn('rfq_id', Rfq::query()->select('rfqs.id')->tap($applyCommonFilters))
                ->tap($isMineWithDataEntry)
                ->count(),
            'finalized' => DB::table('rfq_user')
                ->whereIn('rfq_id', Rfq::query()->select('rfqs.id')->tap($applyCommonFilters))
                ->tap($isMineFinalized)
                ->count(),
        ] : null;

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
                ->paginate(self::PER_PAGE, ['*'], 'assigned_page')
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
                ->notHeldOnAReturnsPage()
                ->tap($applyCommonFilters)
                ->latest()
                ->paginate(self::PER_PAGE, ['*'], 'review_page')
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

        $dataEntryNames = $namesOfWhoDid($seniorOpsReviewRfqs ?? ($dataEntryCompletedBy === null ? $dataEntryCompletedRfqs : null), 'data_entry_completed_by');
        $seniorOpsNames = $namesOfWhoDid($scopedToHeadOfBdReview || $scopedToHeadOfBdReturns ? $rfqs : null, 'senior_ops_reviewed_by');
        $headOfBdNames = $namesOfWhoDid($scopedToGmAssistant || $scopedToGmAssistantReturns ? $rfqs : null, 'head_of_bd_approved_by');
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
            'dataEntryTab' => $dataEntryTab,
            'dataEntryCompletedRfqs' => $dataEntryCompletedRfqs,
            'dataEntryCompletedBy' => $dataEntryCompletedBy,
            'dataEntryCounts' => $dataEntryCounts,
            'sourcingTab' => $sourcingTab,
            'withDataEntryRfqs' => $withDataEntryRfqs,
            'finalizedRfqs' => $finalizedRfqs,
            'sourcingCounts' => $sourcingCounts,
            'assignedRfqs' => $assignedRfqs,
            'seniorOpsReviewRfqs' => $seniorOpsReviewRfqs,
            'dataEntryNames' => $dataEntryNames,
            'search' => $search,
            'listRange' => $listRange,
            'listPriority' => $listPriority,
            'canGroup' => $canGroup,
            'groupBy' => $groupBy,
            'groupTotals' => $groupTotals,
            'allRfqsViews' => $isAllRfqsPage ? $this->allRfqsViewLabels($user) : null,
            'allRfqsView' => $allRfqsView,
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'scopedToMe' => $scopedToMe,
            'scopedToMyClosed' => $scopedToMyClosed,
            'scopedToReturns' => $scopedToReturns,
            'scopedToSeniorOpsReview' => $scopedToSeniorOpsReview,
            'scopedToDataEntry' => $scopedToDataEntry,
            'scopedToUnassigned' => $scopedToUnassigned,
            'scopedToHeadOfBdReview' => $scopedToHeadOfBdReview,
            'scopedToGmAssistant' => $scopedToGmAssistant,
            'scopedToGmReview' => $scopedToGmReview,
            'scopedToBdClosing' => $scopedToBdClosing,
            'scopedToBdReturns' => $scopedToBdReturns,
            'scopedToSeniorOpsReturns' => $scopedToSeniorOpsReturns,
            'scopedToHeadOfBdReturns' => $scopedToHeadOfBdReturns,
            'scopedToGmAssistantReturns' => $scopedToGmAssistantReturns,
            // What the countdowns there — Sourcing's own Pending and Returns
            // lists, Data Entry's — tick against in the browser: the server's
            // clock, and the working periods of the next two weeks.
            'myRunningDataEntry' => $this->runningDataEntryOfViewer($user),
            'myRunningGmAssistant' => $this->runningGmAssistantOfViewer($user),
            // Their own Ready for Data Entry page counts down to Senior
            // Operations being told they haven't started.
            'myDataEntryIdle' => $scopedToDataEntry && $user->hasRole('Data Entry') && ! $user->hasRole('Admin')
                ? $user->dataEntryIdleness()
                : null,
            // GM Assistant's own Review page, the same.
            'myGmAssistantIdle' => $scopedToGmAssistant && $user->hasRole('GM Assistant') && ! $user->hasRole('Admin')
                ? $user->gmAssistantIdleness()
                : null,
            'countdownSchedule' => (($scopedToMe || $scopedToReturns) && ! $sourcingOverview) || $scopedToDataEntry || $scopedToGmAssistant || $scopedToGmAssistantReturns
                ? [
                    'now' => now()->getTimestamp(),
                    'periods' => Setting::workingPeriodsBetween(now(), now()->addDays(14)),
                    // Whoever works hours of their own — extra hours
                    // (ExtraWorkingHour), or their own or their role's week
                    // (WorkingSchedule): theirs.
                    'people' => collect(array_keys(ExtraWorkingHour::byUser()))
                        ->merge(WorkingSchedule::peopleWithOwnHours())
                        ->unique()
                        ->mapWithKeys(fn (int $userId) => [$userId => Setting::workingPeriodsBetween(now(), now()->addDays(14), $userId)]),
                ]
                : null,
            'lensRole' => $lensRole,
            'sourcingOverview' => $sourcingOverview,
            'opsFilters' => $opsFilters,
            'sourcingUsers' => $sourcingUsers,
            'jobCategories' => $jobCategories,
            'operationsUsers' => User::role('Senior Operations')->active()->orderBy('name')->get(),
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
        ['range' => $range, 'from' => $from, 'to' => $to] = $this->createdRange($request, self::OPERATIONS_RANGES);

        $priority = $this->prioritiesFromQuery($request);

        $category = $request->query('category');
        $category = is_string($category) && in_array($category, $categories, true) ? $category : null;

        $member = $request->query('member');
        $member = is_numeric($member) && in_array((int) $member, $sourcingIds, true) ? (int) $member : null;

        $partStatus = $request->query('part_status');
        $partStatus = is_string($partStatus) && array_key_exists($partStatus, RfqAssignment::PROGRESS_LABELS) ? $partStatus : null;

        $sort = $request->query('sort');

        return [
            'range' => $range,
            'from' => $from,
            'to' => $to,
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
     * What All RFQs calls each of ALL_RFQS_VIEWS for $user: the General
     * Manager's own decisions read as theirs ("Approved by you").
     *
     * @return array<string, string>
     */
    protected function allRfqsViewLabels(User $user): array
    {
        return $user->hasRole('General Manager')
            ? array_merge(self::ALL_RFQS_VIEWS, [
                'awaiting_gm' => 'Awaiting your approval',
                'gm_approved' => 'Approved by you',
                'gm_returned' => 'Sent back by you',
            ])
            : self::ALL_RFQS_VIEWS;
    }

    /**
     * The All RFQs view asked for (?show=, ALL_RFQS_VIEWS), or null: which,
     * how it reads, its dates (?show_from, ?show_to, "Y-m-d" in the business
     * time zone — either may be left out) for one over dates, and whose
     * decisions count — the General Manager's own, as their dashboard
     * counted them, else everyone's in the role.
     *
     * @return array{show: string, label: string, from: ?string, to: ?string, start: ?CarbonImmutable, end: ?CarbonImmutable, deciders: array<int, int>}|null
     */
    protected function allRfqsView(Request $request, User $user): ?array
    {
        $show = (string) $request->query('show');

        if (! array_key_exists($show, self::ALL_RFQS_VIEWS)) {
            return null;
        }

        $dated = in_array($show, self::DATED_ALL_RFQS_VIEWS, true);
        $date = function (string $key) use ($request, $dated): ?CarbonImmutable {
            $value = (string) $request->query($key);

            return $dated && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                ? CarbonImmutable::createFromFormat('Y-m-d', $value, Setting::timezone())->startOfDay()
                : null;
        };
        $from = $date('show_from');
        $to = $date('show_to');

        if ($from !== null && $to !== null && $to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'show' => $show,
            'label' => $this->allRfqsViewLabels($user)[$show],
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'start' => $from?->setTimezone(config('app.timezone')),
            'end' => $to?->endOfDay()->setTimezone(config('app.timezone')),
            'deciders' => $user->hasRole('General Manager')
                ? [$user->id]
                : User::role('General Manager')->pluck('id')->all(),
        ];
    }

    /**
     * Narrows All RFQs to $view (allRfqsView()), as the General Manager's
     * dashboard counts each (DashboardController::approverOverview()): every
     * open RFQ; those still open — pending or on hold — that Sourcing hasn't
     * finalized (Rfq::scopeNotFinalizedBySourcing()); those with a part
     * waiting on their approval; those with a
     * part they approved, or that they sent back (the reject's comment),
     * between its dates; and those Business Development closed between them.
     *
     * @param  Builder<Rfq>  $query
     * @param  array{show: string, start: ?CarbonImmutable, end: ?CarbonImmutable, deciders: array<int, int>}  $view
     */
    protected function applyAllRfqsView(Builder $query, array $view): void
    {
        $between = function ($query, string $column) use ($view) {
            $query->when($view['start'], fn ($q, CarbonImmutable $start) => $q->where($column, '>=', $start))
                ->when($view['end'], fn ($q, CarbonImmutable $end) => $q->where($column, '<=', $end));
        };

        match ($view['show']) {
            'open' => $query->where('rfqs.status', 'Pending'),
            'not_finalized' => $query->whereIn('rfqs.status', ['Pending', Rfq::ON_HOLD])->notFinalizedBySourcing(),
            'awaiting_gm' => $query->where('rfqs.status', 'Pending')->awaitingGmApproval(),
            'gm_approved' => $query->whereHas('assignees', fn ($parts) => $parts
                ->whereIn('rfq_user.gm_approved_by', $view['deciders'])
                ->tap(fn ($parts) => $between($parts, 'rfq_user.gm_approved_at'))),
            'gm_returned' => $query->whereIn('rfqs.id', RfqComment::query()
                ->select('rfq_id')
                ->where('action', 'rejected')
                ->whereIn('user_id', $view['deciders'])
                ->tap(fn ($comments) => $between($comments, 'created_at'))),
            'closed' => $query->whereNotNull('rfqs.bd_closed_at')->tap(fn ($closed) => $between($closed, 'rfqs.bd_closed_at')),
        };
    }

    /**
     * When the RFQs listed were created, read from the query string: ?range=
     * one of $presets (key => days, today the first), or "custom" with
     * ?from= and/or ?to= — at least one, the two put the right way round.
     * Anything else is "all".
     *
     * @param  array<string, int>  $presets
     * @return array{range: string, from: ?string, to: ?string}
     */
    protected function createdRange(Request $request, array $presets): array
    {
        $range = (string) $request->query('range');
        $from = $this->dateFromQuery($request->query('from'));
        $to = $this->dateFromQuery($request->query('to'));

        if ($range !== 'custom' || (! $from && ! $to)) {
            $range = array_key_exists($range, $presets) ? $range : 'all';
        }

        if ($range !== 'custom') {
            $from = $to = null;
        } elseif ($from && $to && $from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return ['range' => $range, 'from' => $from?->toDateString(), 'to' => $to?->toDateString()];
    }

    /**
     * Narrows $query to the RFQs created in $range (createdRange()) — whole
     * days in the working hours' time zone (Setting::timezone()), compared
     * with the stored times in the app's own.
     *
     * @param  Builder<Rfq>  $query
     * @param  array{range: string, from: ?string, to: ?string}  $range
     */
    protected function applyCreatedRange(Builder $query, array $range): void
    {
        $timezone = Setting::timezone();
        $day = fn (string $date) => CarbonImmutable::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
        $stored = fn (CarbonImmutable $moment) => $moment->setTimezone(config('app.timezone'));

        $query
            ->when(self::OPERATIONS_RANGES[$range['range']] ?? null, fn ($query, int $days) => $query
                ->where('rfqs.created_at', '>=', $stored(CarbonImmutable::now($timezone)->startOfDay()->subDays($days - 1))))
            ->when($range['from'], fn ($query, string $from) => $query->where('rfqs.created_at', '>=', $stored($day($from))))
            ->when($range['to'], fn ($query, string $to) => $query->where('rfqs.created_at', '<=', $stored($day($to)->endOfDay())));
    }

    /**
     * The SQL for what a list is grouped by (LIST_GROUPS): the WC number,
     * the same however it was typed (spaces and case aside); the priority;
     * or the status. Rfq::listGroupKey() is the PHP twin, for each row.
     */
    protected function listGroupKey(string $group): string
    {
        return match ($group) {
            'wc' => 'upper(trim(rfqs.wc_number))',
            'priority' => 'rfqs.priority_level',
            default => 'rfqs.status',
        };
    }

    /**
     * How a grouped list is ordered, so each group's RFQs come together: WC
     * numbers in order, priorities Urgent first, statuses as the work goes —
     * pending, on hold, closed, cancelled. The newest first within each.
     */
    protected function listGroupOrder(string $group): string
    {
        return match ($group) {
            'wc' => 'upper(trim(rfqs.wc_number))',
            'priority' => Rfq::priorityOrderSql('rfqs.priority_level'),
            default => "case rfqs.status when 'Pending' then 0 when '".Rfq::ON_HOLD."' then 1 when 'Completed' then 2 else 3 end",
        };
    }

    /**
     * The priority levels asked for (?priority[]=), in Rfq::PRIORITIES'
     * order — anything that isn't one is dropped.
     *
     * @return array<int, string>
     */
    protected function prioritiesFromQuery(Request $request): array
    {
        return array_values(array_intersect(Rfq::PRIORITIES, array_filter((array) $request->query('priority'), 'is_string')));
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
        $this->applyCreatedRange($query, $filters);

        $query
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
            'priority' => $query->orderByRaw(Rfq::priorityOrderSql())->latest(),
            default => $query->latest(),
        };
    }

    public function show(Request $request, Rfq $rfq): View
    {
        $status = $request->query('status');

        if (! in_array($status, Rfq::LIST_STATUSES, true)) {
            $status = null;
        }

        return view('admin.rfqs.show', [
            'rfq' => $rfq->load(['assignees', 'creator', 'operationsAssignee', 'sourcingCompletedBy', 'dataEntryCompletedBy', 'comments.author.roles', 'comments.replies.author.roles', 'comments.attachments', 'comments.replies.attachments', 'steps']),
            'priorities' => Rfq::PRIORITIES,
            'statuses' => Rfq::STATUSES,
            'statusFilter' => $status,
            'sourcingUsers' => User::role('Sourcing')->active()->withSourcingWorkloadCounts()->orderBy('name')->get(),
            'jobCategories' => JobCategory::orderBy('name')->get(),
            'operationsUsers' => User::role('Senior Operations')->active()->orderBy('name')->get(),
            'myRunningDataEntry' => $this->runningDataEntryOfViewer($request->user()),
            'myRunningGmAssistant' => $this->runningGmAssistantOfViewer($request->user()),
        ]);
    }

    /**
     * The part a Data Entry viewer has running (RfqStep::runningDataEntryOf()),
     * which keeps their Start off on every other part. Not an Admin's: they
     * start on someone's behalf, picked on the form, so the server checks.
     */
    private function runningDataEntryOfViewer(User $user): ?RfqStep
    {
        return $user->hasRole('Data Entry') && ! $user->hasRole('Admin') ? RfqStep::runningDataEntryOf($user) : null;
    }

    /**
     * The part a GM Assistant viewer has running (RfqStep::runningGmAssistantOf()),
     * which keeps their Start off on every other part — not an Admin's, who
     * starts on someone's behalf.
     */
    private function runningGmAssistantOfViewer(User $user): ?RfqStep
    {
        return $user->hasRole('GM Assistant') && ! $user->hasRole('Admin') ? RfqStep::runningGmAssistantOf($user) : null;
    }

    public function store(Request $request): RedirectResponse
    {
        // RFQ Number is auto-generated (WP0001, WP0002, ...), never taken
        // from user input — see Rfq::nextRfqNumber(). Nor is the status: a
        // new RFQ always starts Pending, whatever the request says.
        [$input, $rules] = $this->wcNumberChecked($request->all(), Arr::except($this->rules(requireRfqNumber: false), 'status'), digitsOnly: true);
        $validator = Validator::make($input, $rules, $this->wcNumberMessages(digitsOnly: true));

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

    /**
     * Edits an RFQ's own details (WC/RFQ number, priority, subject,
     * description, status) — for whoever Rfq::canBeEditedBy() lets: Admin,
     * any RFQ; Senior Operations and Business Development, any not yet
     * closed. Only an Admin's status is kept; anyone else's edit leaves the
     * status as it was, and one sent back to Business Development
     * (Rfq::isReturnedToBusinessDevelopment()) is Pending again: closing it
     * out is still the very end of the approval chain, not theirs to skip
     * to from here.
     *
     * Saving an edit to an RFQ sent back to Business Development — by them,
     * or by Admin, who can act at every stage — counts as fixing it: it
     * comes off their Returns page (Rfq::resolveBusinessDevelopmentReturn()).
     */
    public function update(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $rfq->canBeEditedBy($request->user()),
            403,
            $rfq->status === 'Completed' ? 'A closed RFQ can\'t be edited.' : 'You can\'t edit this RFQ.'
        );

        $isBdReturn = $request->user()->hasRole('Business Development') && $rfq->isReturnedToBusinessDevelopment();
        $resolvesBdReturn = $rfq->isReturnedToBusinessDevelopment() && $request->user()->hasAnyRole(['Business Development', 'Admin']);

        // An RFQ on hold or cancelled keeps its status through an edit — only
        // Senior Operations' own change (changeStatus()) sets it going again.
        $rules = $this->rules();
        if ($rfq->isStopped()) {
            $rules['status'] = ['nullable'];
        }

        // An older RFQ keeps its WC number as it was; a new one is checked.
        [$input, $rules] = $this->wcNumberChecked($request->all(), $rules, unless: $rfq->wc_number);
        $validator = Validator::make($input, $rules, $this->wcNumberMessages());

        if ($validator->fails()) {
            return back()->withErrors($validator, 'edit')->withInput();
        }

        $validated = $validator->validated();

        // Only an Admin changes the status from here: Senior Operations has
        // its own way to stop and resume one (changeStatus()), and closing is
        // Business Development's — whose returned RFQ is Pending again.
        if (! $request->user()->hasRole('Admin')) {
            $validated['status'] = $isBdReturn ? 'Pending' : $rfq->status;
        }

        if ($rfq->isStopped()) {
            $validated['status'] = $rfq->status;
        }

        $rfq->update($validated);

        // Admin can close it from here — any step still timed on it ends.
        $rfq->syncSteps();

        // Fixed, and — if they asked, and it still can — straight back to
        // whoever sent it (Rfq::forwardBack()) rather than on to Senior
        // Operations to assign again.
        $forwardBack = $resolvesBdReturn && $request->boolean('forward_back') ? $rfq->openReturnsTo('business_development') : collect();

        if ($forwardBack->count() === 1) {
            $rfq->forwardBack($forwardBack->first(), $this->doneBy($request, 'Business Development'));

            return $this->redirectAfterSave($request, $rfq)->with('status', 'RFQ updated — and sent straight back to '.Rfq::stageLabel($forwardBack->keys()->first()).'.');
        }

        if ($resolvesBdReturn) {
            $rfq->resolveBusinessDevelopmentReturn();
        }

        return $this->redirectAfterSave($request, $rfq)->with('status', $resolvesBdReturn
            ? 'RFQ updated — it\'s been taken off the Returns list.'
            : 'RFQ updated successfully.');
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
     * Whoever assigns Sourcing directly is implicitly the one routing this
     * RFQ — if nobody's recorded as the Operations assignee yet, record
     * them (Admin's own pick, via doneBy(), if they made one), so Operations
     * doesn't need a separate "Assign Operations" step just to name
     * themselves.
     *
     * Business Development never has access either — not even on an RFQ
     * sent back to them (Rfq::isReturnedToBusinessDevelopment()): they fix
     * its details, and Senior Operations re-assigns it from their
     * Unassigned queue.
     */
    public function assign(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_if($request->user()->hasRole('Sourcing'), 403, 'Sourcing cannot assign RFQs — that\'s Operations\' or a coordinator\'s call.');
        abort_if($request->user()->hasRole('Business Development'), 403, 'Business Development cannot assign Sourcing — Senior Operations re-assigns a returned RFQ.');

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

            if ($pickedUserIds->diff(User::role('Sourcing')->active()->pluck('id'))->isNotEmpty()) {
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

            $rfq->assignSourcingParts($assignments->all(), $user);

            if (! $rfq->operations_assigned_by) {
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
     * "Get Details Again" — Senior Operations, looking at an RFQ on their
     * Unassigned queue, finds it's missing what they need to route it, and
     * sends it back to Business Development's Returns page with a reason.
     * The same whole-RFQ send-back Senior Operations' second review can do
     * (Rfq::rejectToStage() to 'business_development'), only from their
     * assignment step: any Sourcing part already assigned on a partly
     * assigned split is freed, and the wizard starts over once it's back.
     * Business Development fixing it (RfqController::update()) puts it back
     * in front of Senior Operations.
     */
    public function requestDetails(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['Senior Operations', 'Admin']),
            403,
            'Only Senior Operations can ask Business Development for details again.'
        );
        abort_unless(
            $rfq->status === 'Pending' && Rfq::whereKey($rfq->id)->needingSourcing()->exists(),
            422,
            'This RFQ is not waiting on Sourcing assignment.'
        );
        abort_if($rfq->isReturnedToBusinessDevelopment(), 422, 'This RFQ is already with Business Development.');

        $validated = $request->validateWithBag('requestDetails', [
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->rejectToStage('business_development', $validated['reason'], $this->doneBy($request, 'Senior Operations'), 'operations'));

        return redirect()->back()->with('status', "Sent {$rfq->rfq_number} back to Business Development for details.");
    }

    /**
     * Senior Operations puts an RFQ on hold, cancels it, or sets it going
     * again (status Pending): an open RFQ can be put on hold or cancelled, one
     * on hold resumed or cancelled, a cancelled one reopened. Putting on hold
     * or cancelling needs a reason; a hold can say it's waiting for the
     * customer's clarification (customer_clarification). A closed RFQ is done with. Admin can too,
     * as one of Senior Operations (doneBy()). See Rfq::changeStatus(). With a
     * part, just that part of a split — see changePartStatus().
     */
    public function changeStatus(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->canChangeRfqStatus(), 403, 'Only Senior Operations can put an RFQ on hold or cancel it.');

        if ($request->filled('part')) {
            return $this->changePartStatus($request, $rfq, (int) $request->input('part'));
        }

        $allowed = match ($rfq->status) {
            'Pending' => [Rfq::ON_HOLD, Rfq::CANCELLED],
            Rfq::ON_HOLD => ['Pending', Rfq::CANCELLED],
            Rfq::CANCELLED => ['Pending'],
            default => [],
        };
        abort_if($allowed === [], 422, 'A closed RFQ can\'t be put on hold or cancelled.');

        $validated = $request->validateWithBag('rfq_status', [
            'status' => ['required', Rule::in($allowed)],
            'reason' => ['nullable', 'required_unless:status,Pending', 'string', 'max:1000'],
            'customer_clarification' => ['nullable', 'boolean'],
        ], [
            'status.in' => 'That isn\'t a change this RFQ can make right now.',
            'reason.required_unless' => 'Say why.',
        ]);

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->changeStatus($validated['status'], $this->doneBy($request, 'Senior Operations'), $validated['reason'] ?? null, (bool) ($validated['customer_clarification'] ?? false)));

        return redirect()->back()->with('status', match ($validated['status']) {
            Rfq::ON_HOLD => "{$rfq->rfq_number} is on hold.",
            Rfq::CANCELLED => "{$rfq->rfq_number} is cancelled.",
            default => "{$rfq->rfq_number} is back in progress.",
        });
    }

    /**
     * Senior Operations puts one part of a split on hold, cancels it, or sets
     * it going again, on its own — the same changes as the whole RFQ's
     * (changeStatus()), while the RFQ itself is in progress. A part that's
     * closed is done with, and the last part still going ahead can't be
     * cancelled — that's cancelling the RFQ. See Rfq::changePartStatus().
     */
    private function changePartStatus(Request $request, Rfq $rfq, int $part): RedirectResponse
    {
        $assignee = $rfq->assigneeForPart($part);

        abort_unless($rfq->isSplit() && $assignee, 404, 'That part is not assigned on this RFQ.');
        abort_unless($rfq->status === 'Pending', 422, 'Set the RFQ itself going again first.');
        abort_if($assignee->pivot->isBdClosed(), 422, 'A closed part can\'t be put on hold or cancelled.');

        $allowed = match ($assignee->pivot->status) {
            Rfq::ON_HOLD => ['Pending', Rfq::CANCELLED],
            Rfq::CANCELLED => ['Pending'],
            default => [Rfq::ON_HOLD, Rfq::CANCELLED],
        };
        $isLastLivePart = ! $assignee->pivot->isCancelled() && $rfq->liveParts()->count() === 1;

        $validated = $request->validateWithBag('rfq_status', [
            'status' => ['required', Rule::in($allowed), function (string $attribute, mixed $value, Closure $fail) use ($isLastLivePart) {
                if ($value === Rfq::CANCELLED && $isLastLivePart) {
                    $fail('It\'s the last part still going ahead — cancel the whole RFQ instead.');
                }
            }],
            'reason' => ['nullable', 'required_unless:status,Pending', 'string', 'max:1000'],
            'customer_clarification' => ['nullable', 'boolean'],
        ], [
            'status.in' => 'That isn\'t a change this part can make right now.',
            'reason.required_unless' => 'Say why.',
        ]);

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->changePartStatus($part, $validated['status'], $this->doneBy($request, 'Senior Operations'), $validated['reason'] ?? null, (bool) ($validated['customer_clarification'] ?? false)));

        $label = $rfq->partNumberLabel($part);

        return redirect()->back()->with('status', match ($validated['status']) {
            Rfq::ON_HOLD => "{$label} is on hold.",
            Rfq::CANCELLED => "{$label} is cancelled — the rest of the RFQ carries on without it.",
            default => "{$label} is back in progress.",
        });
    }

    /**
     * Refuses work on $assignee's part while it — or the RFQ itself — is
     * stopped (on hold or cancelled): it's out of every queue until Senior
     * Operations sets it going again.
     */
    private function abortIfStopped(Rfq $rfq, ?User $assignee): void
    {
        abort_if($rfq->isStopped(), 422, 'This RFQ is '.strtolower($rfq->status).'.');
        abort_if($assignee?->pivot->isStopped() === true, 422, 'That part is '.strtolower((string) $assignee?->pivot->status).'.');
    }

    /**
     * Refuses acting on the RFQ as a whole — approving every part, closing it
     * — while a part of it is on hold: that would move it on past the part.
     * The other parts can still be dealt with one by one.
     */
    private function abortIfPartOnHold(Rfq $rfq): void
    {
        $partsOnHold = $rfq->partsOnHold();

        abort_if($partsOnHold !== [], 422, "{$rfq->partsLabel($partsOnHold)} is on hold — deal with the other parts one by one, or resume it first.");
    }

    /**
     * Whoever something was sent back to sends it straight back to the
     * reviewer who sent it, skipping the steps in between — their earlier
     * work stands (Rfq::forwardBack()). One part (part), or — for a
     * whole-RFQ row on a Returns page — every part sent back to $target from
     * $from that still can be (Rfq::openReturns()). Only the role at
     * $target (Rfq::RETURN_TARGET_ROLES), and for Sourcing only the part's
     * own member; Admin can too, as one of them (doneBy()).
     */
    public function forwardBack(Request $request, Rfq $rfq): RedirectResponse
    {
        $validated = $request->validate([
            'part' => ['nullable', 'integer'],
            'target' => ['required', Rule::in(array_keys(Rfq::RETURN_TARGET_ROLES))],
            'from' => ['required', 'string'],
        ]);

        $role = Rfq::RETURN_TARGET_ROLES[$validated['target']];
        $user = $request->user();
        $part = isset($validated['part']) ? (int) $validated['part'] : null;

        abort_unless($user->hasAnyRole([$role, 'Admin']), 403, "Only {$role} can send this back.");
        abort_if(
            $role === 'Sourcing' && ! $user->hasRole('Admin') && ($part === null || ! $rfq->assigneeForPart($part)?->is($user)),
            403,
            'Only the Sourcing member with this part can send it back.'
        );

        $returns = $rfq->openReturns()
            ->where('target_stage', $validated['target'])
            ->where('from_stage', $validated['from'])
            ->when($part !== null, fn ($returns) => $returns->filter(fn (RfqReturn $return) => $return->part_number === $part));

        abort_if($returns->isEmpty(), 422, 'There\'s nothing here to send straight back.');

        $rfq->forwardBack($returns, $this->doneBy($request, $role));

        $what = $part !== null ? $rfq->partNumberLabel($part) : $rfq->rfq_number;

        return back()->with('status', "Sent {$what} straight back to ".Rfq::stageLabel($validated['from']).'.');
    }

    /**
     * Senior Operations passes the parts of an RFQ sent back to their
     * assignment step straight back to the Sourcing members who held them —
     * one click, no wizard: each freed part to whoever last had it
     * (Rfq::previousHoldersOfOpenParts()), the same as assigning it to them
     * (assign()). A part with nobody to go back to is left for Assign
     * Sourcing. Admin can too, as one of Senior Operations (doneBy()).
     */
    public function passBackToSourcing(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['Senior Operations', 'Admin']), 403, 'Only Senior Operations can pass an RFQ back to Sourcing.');
        $this->abortIfStopped($rfq, null);

        $holders = $rfq->previousHoldersOfOpenParts();

        abort_if($holders === [], 422, 'No part here has a Sourcing member to pass it back to — assign it instead.');

        $user = $this->doneBy($request, 'Senior Operations');

        DB::transaction(function () use ($rfq, $holders, $user) {
            $rfq->assignSourcingParts(array_map(fn (User $holder) => $holder->id, $holders), $user);

            if (! $rfq->operations_assigned_by) {
                $rfq->update([
                    'operations_assigned_by' => $user->id,
                    'operations_assigned_at' => now(),
                ]);
            }
        });

        $names = collect($holders)->pluck('name')->unique()->join(', ', ' and ');
        $remaining = $rfq->sourcingParts()->whereNull('assignee')->count();

        return redirect()->back()->with('status', "Passed {$rfq->partsLabel(array_keys($holders))} back to {$names}."
            .($remaining > 0 ? " {$remaining} still to assign." : ''));
    }

    /**
     * Senior Operations replaces a part's Sourcing member with another, at
     * whatever step the part has got to — from the Assigned tab or the RFQ's
     * page — with a reason if they like. The part keeps its progress
     * (Rfq::replaceSourcingPart()). Not a closed or cancelled one. Admin can
     * too, as one of Senior Operations (doneBy()).
     */
    public function reassignSourcing(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['Senior Operations', 'Admin']), 403, 'Only Senior Operations can replace Sourcing.');

        $validated = $request->validateWithBag('reassign', [
            'part' => ['required', 'integer'],
            'user_id' => ['required', 'integer'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ], [
            'user_id.required' => 'Pick who takes it over.',
            'reason.max' => 'Keep the reason under 1000 characters.',
        ]);

        $part = (int) $validated['part'];
        $current = $rfq->assigneeForPart($part);

        abort_unless($current, 404, 'That part is not assigned on this RFQ.');
        abort_unless($rfq->canReplaceSourcing($part), 422, 'This part is closed or cancelled — its Sourcing can\'t be replaced now.');

        $to = User::role('Sourcing')->active()->find($validated['user_id']);

        if ($to === null || $to->id === $current->id) {
            return back()->withInput()->withErrors([
                'user_id' => $to === null ? 'Pick someone from Sourcing.' : 'They already have it — pick someone else.',
            ], 'reassign');
        }

        $reason = trim((string) ($validated['reason'] ?? ''));
        $rfq->replaceSourcingPart($part, $to, $this->doneBy($request, 'Senior Operations'), $reason !== '' ? $reason : null);

        return redirect()->back()->with('status', "Replaced Sourcing on {$rfq->partNumberLabel($part)}: {$current->name} → {$to->name}.");
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
            && User::role('Senior Operations')->active()->whereKey($operationsUserId)->exists();

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
        $this->abortIfStopped($rfq, $assignee);

        $comment = $this->requiredComment($request, 'comment', 'Add a comment to assign this part to Data Entry.');

        if ($comment instanceof RedirectResponse) {
            return $comment;
        }

        // How many line items Data Entry has to add for it.
        $lineItems = Validator::make($request->all(), [
            'line_items' => ['required', 'integer', 'min:1', 'max:'.self::MAX_LINE_ITEMS],
        ], [
            'line_items.*' => 'Add how many line items Data Entry has to add — a whole number, 1 or more.',
        ]);

        if ($lineItems->fails()) {
            return back()->with('error', $lineItems->errors()->first());
        }

        // Whom in Data Entry it's for — someone there by name, or anyone
        // (left empty): whoever starts it first.
        $dataEntry = null;

        if ($request->filled('data_entry_user_id')) {
            $dataEntry = User::query()->holdingRole('Data Entry')->find($request->integer('data_entry_user_id'));

            if ($dataEntry === null) {
                return back()->with('error', 'Pick someone in Data Entry from the list, or leave it with anyone there.');
            }
        }

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        $completed = RfqComment::attachingFiles($rfq, $files, fn () => $rfq->completeSourcingPart((int) $validated['part'], $comment, $dataEntry, (int) $lineItems->validated()['line_items']));

        // Sent twice — a double click, a retry: it's done already, and they've been told.
        if (! $completed) {
            return $this->redirectAfterSave($request, $rfq)->with('status', 'This part is already assigned to Data Entry.');
        }

        // They're told, in their bell.
        $dataEntry?->notify(new DataEntryAssigned($rfq->refresh(), (int) $validated['part'], $assignee));

        $status = $dataEntry !== null
            ? "Assigned to {$dataEntry->name} in Data Entry."
            : null;
        $status ??= $rfq->isWithDataEntry()
            ? 'Assigned to Data Entry — every part is with them now.'
            : ($rfq->isSplit()
                ? 'Part assigned to Data Entry — the rest of the parts are still with Sourcing.'
                : 'Your part is assigned to Data Entry — the rest are still with the Sourcing team.');

        return $this->redirectAfterSave($request, $rfq)->with('status', $status);
    }

    /**
     * Data Entry sends one Sourcing part of this RFQ back for rework, with
     * a required reason and how many line items they'd added to it so far
     * (entries, kept with the return — rfq_user.return_line_items). Clears that part's completed_at (Mark Complete
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
        $this->abortIfStopped($rfq, $assignee);

        // Already back with Sourcing — the same Return sent twice (a double click, a retry).
        if ($assignee->pivot->completed_at === null) {
            return redirect()->back()->with('status', "{$assignee->name}'s part is already back with Sourcing.");
        }

        // How many line items they'd added to it so far, kept with the return.
        $lineItemsAdded = Validator::make($request->all(), [
            'entries' => ['required', 'integer', 'min:0', 'max:'.self::MAX_DATA_ENTRIES],
        ], [
            'entries.required' => 'Add how many line items you added to this part before sending it back.',
            'entries.integer' => 'Give the line items added as a whole number, like 12.',
            'entries.min' => 'The line items added can\'t be less than 0.',
            'entries.max' => 'That\'s more line items than one part can have — at most '.number_format(self::MAX_DATA_ENTRIES).'.',
        ]);

        if ($lineItemsAdded->fails()) {
            return back()->with('error', $lineItemsAdded->errors()->first());
        }

        $reason = $this->requiredComment($request, 'reason', 'Add a reason to send this part back.', 1000);

        if ($reason instanceof RedirectResponse) {
            return $reason;
        }

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->returnSourcingPart((int) $validated['part'], $reason, $this->doneBy($request, 'Data Entry'), (int) $lineItemsAdded->validated()['entries']));

        return redirect()->back()->with('status', "Sent {$assignee->name}'s part back to Sourcing.");
    }

    /**
     * Data Entry's Start on a part with them (Rfq::startDataEntryPart()) —
     * at any hour, but their time on it only counts in working hours: started
     * at lunch or out of hours (Setting::isWorkingTime()), it counts from when
     * they're back, and says when (Setting::offHoursReason()). One part at a
     * time: not while they've another running, till they send it to finalize
     * (RfqStep::runningDataEntryOf()). Recorded as one of Data Entry — Admin
     * can, naming who (doneBy()).
     */
    public function startDataEntry(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['Data Entry', 'Admin']), 403, 'Only Data Entry can start on a part.');

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];
        $assignee = $rfq->assigneeForPart($part);

        abort_unless($assignee, 404, 'That part is not assigned on this RFQ.');
        $this->abortIfStopped($rfq, $assignee);
        abort_unless($rfq->partAwaitsDataEntryStart($part), 422, 'This part isn\'t waiting to be started.');

        $starter = $this->doneBy($request, 'Data Entry');

        // Given to someone in Data Entry by name: theirs to start.
        if (! $rfq->partIsForDataEntryUser($part, $starter)) {
            $for = $rfq->assigneeForPart($part)?->pivot->dataEntryAssignee;

            return back()->with('error', 'This part is assigned to '.($for?->name ?? 'someone else').' in Data Entry — it\'s theirs to start.');
        }

        if ($running = RfqStep::runningDataEntryOf($starter)) {
            return back()->with('error', ($starter->is($request->user()) ? 'You\'re' : "{$starter->name} is").' already on '
                .$running->rfq->partNumberLabel($running->part_number).' — send it to finalize before starting another.');
        }

        $rfq->startDataEntryPart($part, $starter);

        // Out of their hours — their own extra hours count — it waits for them.
        $offHours = Setting::offHoursReason(now(), $starter->id);

        return back()->with('status', $offHours === null
            ? "Started {$rfq->partNumberLabel($part)} — your time on it is counting."
            : "Started {$rfq->partNumberLabel($part)} — outside working hours, so your time on it isn't counting yet. {$offHours}");
    }

    /**
     * Data Entry's Pause on the part they're on (Rfq::pauseDataEntryPart()),
     * with why and the document it's waiting on, if any: its countdown stops
     * where it is, and they can start another. Only whoever started it —
     * Admin can, for them.
     */
    public function pauseDataEntry(Request $request, Rfq $rfq): RedirectResponse
    {
        [$part, $assignee] = $this->dataEntryPartOfStarter($request, $rfq);

        if (! $assignee->pivot->isDataEntryRunning()) {
            return back()->with('error', 'This part isn\'t running, so there\'s nothing to pause.');
        }

        // Why, and the document it's waiting on, if any.
        $validator = Validator::make($request->all(), [
            'pause_reason' => ['required', 'string', 'max:1000'],
            'pause_reference' => ['nullable', 'string', 'max:255'],
        ], [
            'pause_reason.required' => 'Say why you\'re pausing it.',
            'pause_reason.max' => 'Keep the reason under 1000 characters.',
            'pause_reference.max' => 'Keep the document reference under 255 characters.',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $rfq->pauseDataEntryPart($part, $request->user(), $validator->validated()['pause_reason'], $validator->validated()['pause_reference'] ?? null);

        // Senior Operations and the General Manager hear of every pause, with
        // the reason and reference as saved on the part.
        $paused = $rfq->refresh()->assigneeForPart($part)?->pivot;

        if ($paused?->isDataEntryPaused()) {
            Notification::send(DataEntryPaused::recipients(), new DataEntryPaused($rfq, $part, $request->user(), $paused->data_entry_pause_reason, $paused->data_entry_pause_reference));
        }

        return back()->with('status', "Paused {$rfq->partNumberLabel($part)} — its countdown has stopped. You can start another, and resume this one once you've nothing running.");
    }

    /**
     * Data Entry's Resume on a part they'd paused (Rfq::resumeDataEntryPart()):
     * its countdown goes on from where it stopped. One part at a time, as with
     * Start — not while they've another running.
     */
    public function resumeDataEntry(Request $request, Rfq $rfq): RedirectResponse
    {
        [$part, $assignee] = $this->dataEntryPartOfStarter($request, $rfq);

        if (! $assignee->pivot->isDataEntryPaused()) {
            return back()->with('error', 'This part isn\'t paused.');
        }

        $starter = $assignee->pivot->dataEntryStartedBy;

        if ($starter && ($running = RfqStep::runningDataEntryOf($starter))) {
            return back()->with('error', ($starter->is($request->user()) ? 'You\'re' : "{$starter->name} is").' on '
                .$running->rfq->partNumberLabel($running->part_number).' — send it to finalize, or pause it, before resuming this one.');
        }

        $rfq->resumeDataEntryPart($part);

        return back()->with('status', "Resumed {$rfq->partNumberLabel($part)} — its countdown carries on from where it stopped.");
    }

    /**
     * The part a Pause or Resume is for — [its number, its assignee] —
     * refused unless it's Data Entry's own: whoever started it, or Admin.
     *
     * @return array{0: int, 1: User}
     */
    private function dataEntryPartOfStarter(Request $request, Rfq $rfq): array
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['Data Entry', 'Admin']), 403, 'Only Data Entry can pause or resume a part.');

        $part = (int) $request->validate(['part' => ['required', 'integer']])['part'];
        $assignee = $rfq->assigneeForPart($part);

        abort_unless($assignee, 404, 'That part is not assigned on this RFQ.');
        $this->abortIfStopped($rfq, $assignee);
        abort_unless(
            $user->hasRole('Admin') || (int) $assignee->pivot->data_entry_started_by === $user->id,
            403,
            'Only whoever started this part can pause or resume it.'
        );

        return [$part, $assignee];
    }

    /**
     * Data Entry's "Send to Finalize" — finishes processing one Sourcing part
     * (only that part, never the others on the same RFQ, even other parts
     * held by the same person) and sends it back to its Sourcing member to
     * finalize, with a comment that's posted to the RFQ's thread and how
     * many entries they made on it (0 to MAX_DATA_ENTRIES), kept with the
     * part. It's their Finalize (finalize()) that sends it on to Senior
     * Operations' review. See Rfq::completeDataEntryPart().
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
        $this->abortIfStopped($rfq, $assignee);

        // Their time on it runs from their Start — there's none to send on
        // before it (startDataEntry()).
        if ($assignee->pivot->data_entry_completed_at === null && ! $assignee->pivot->hasDataEntryStarted()) {
            return back()->with('error', 'Start on this part first — Send to Finalize comes after.');
        }

        if ($assignee->pivot->isDataEntryPaused()) {
            return back()->with('error', 'Resume this part first — it\'s paused.');
        }

        // How many entries they made on it, kept with the part.
        $entries = Validator::make($request->all(), [
            'entries' => ['required', 'integer', 'min:0', 'max:'.self::MAX_DATA_ENTRIES],
        ], [
            'entries.required' => 'Add how many line items you added to this part.',
            'entries.integer' => 'Give the line items added as a whole number, like 12.',
            'entries.min' => 'The line items added can\'t be less than 0.',
            'entries.max' => 'That\'s more line items than one part can have — at most '.number_format(self::MAX_DATA_ENTRIES).'.',
        ]);

        if ($entries->fails()) {
            return back()->with('error', $entries->errors()->first());
        }

        $comment = $this->requiredComment($request, 'comment', 'Add a comment to send this part to finalize.');

        if ($comment instanceof RedirectResponse) {
            return $comment;
        }

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        $wasWithDataEntry = $assignee->pivot->data_entry_completed_at === null;
        $doneBy = $this->doneBy($request, 'Data Entry');

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->completeDataEntryPart((int) $validated['part'], $doneBy, $comment, (int) $entries->validated()['entries']));

        // Senior Operations and the General Manager hear of it when the line
        // items added aren't the ones Sourcing gave to add.
        $sent = $rfq->refresh()->assigneeForPart((int) $validated['part'])?->pivot;

        if ($wasWithDataEntry && $sent?->lineItemsDiscrepancy() !== null) {
            Notification::send(LineItemsDiscrepancy::recipients(), new LineItemsDiscrepancy($rfq, (int) $validated['part'], $sent->data_entry_entries, $sent->line_items_to_add, $doneBy));
        }

        return redirect()->back()->with('status', "Sent {$assignee->name}'s part to finalize.");
    }

    /**
     * The Sourcing member's Finalize — on a part Data Entry has sent to
     * finalize, it sends that part on to Senior Operations' review. Only the
     * part's own assignee can, or Admin on their behalf (recorded as the
     * assignee either way). See Rfq::finalizePart().
     */
    public function finalize(Request $request, Rfq $rfq): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];
        $assignee = $rfq->assigneeForPart($part);

        abort_unless(
            ($user->hasRole('Sourcing') && $assignee?->id === $user->id) || ($user->hasRole('Admin') && $assignee !== null),
            403,
            'Only the Sourcing member assigned to this part can finalize it.'
        );
        abort_unless($rfq->partAwaitsFinalize($part), 422, 'This part is not waiting to be finalized.');

        $rfq->finalizePart($part);

        return $this->redirectAfterSave($request, $rfq)->with('status', "Finalized {$rfq->partNumberLabel($part)} — sent to Senior Operations' review.");
    }

    /**
     * The Sourcing member's Return to Data Entry — on a part Data Entry has
     * sent to finalize, instead of finalizing it, sends it back to Data
     * Entry with a required reason. Only the part's own assignee can, or
     * Admin on their behalf (recorded as the assignee either way). See
     * Rfq::returnToDataEntry().
     */
    public function returnDataEntry(Request $request, Rfq $rfq): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];
        $assignee = $rfq->assigneeForPart($part);
        $isAdminActing = $user->hasRole('Admin') && $assignee !== null;

        abort_unless(
            ($user->hasRole('Sourcing') && $assignee?->id === $user->id) || $isAdminActing,
            403,
            'Only the Sourcing member assigned to this part can send it back to Data Entry.'
        );
        abort_if(
            $isAdminActing && $request->filled('acting_user_id') && (int) $request->input('acting_user_id') !== $assignee->id,
            422,
            "That person isn't the Sourcing member assigned to this part."
        );
        abort_unless($rfq->partAwaitsFinalize($part), 422, 'This part is not waiting to be finalized.');

        $reason = $this->requiredComment($request, 'reason', 'Add a reason to send this part back to Data Entry.', 1000);

        if ($reason instanceof RedirectResponse) {
            return $reason;
        }

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->returnToDataEntry($part, $reason));

        return $this->redirectAfterSave($request, $rfq)->with('status', "Sent {$rfq->partNumberLabel($part)} back to Data Entry.");
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

        $verified = $this->verifiedLineItems($request, $rfq, collect([$rfq->assigneeForPart($part)]));

        if ($verified instanceof RedirectResponse) {
            return $verified;
        }

        $rfq->approveSeniorOpsPart($part, $this->doneBy($request, 'Senior Operations'), $verified[$part]);

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
        $this->abortIfPartOnHold($rfq);

        $verified = $this->verifiedLineItems($request, $rfq, $rfq->partsAwaitingSeniorOpsApproval());

        if ($verified instanceof RedirectResponse) {
            return $verified;
        }

        $rfq->completeSeniorOpsReview($this->doneBy($request, 'Senior Operations'), $verified);

        return redirect()->back()->with('status', 'Approved — escalated to Head of Business Development.');
    }

    /**
     * The line items Senior Operations counted on each of $parts as they
     * approve them on to the Head of Business Development —
     * actual_line_items[part number] — or back, saying what's wrong. Asked
     * in the approve popup (_senior_ops_approve_modal) beside what Sourcing
     * gave to add and Data Entry added. See lineItemsGiven().
     *
     * @param  Collection<int, User>  $parts
     * @return array<int, int>|RedirectResponse part number => line items
     */
    private function verifiedLineItems(Request $request, Rfq $rfq, Collection $parts): array|RedirectResponse
    {
        return $this->lineItemsGiven($request, $rfq, $parts, 'actual_line_items', 'Enter the actual line items you verified on :part.', 'actual line items');
    }

    /**
     * The line items the Head of Business Development finalized on each of
     * $parts as they approve them on to GM Assistant —
     * finalized_line_items[part number] — or back, saying what's wrong. Asked
     * in their approve popup (_head_of_bd_approve_modal). See lineItemsGiven().
     *
     * @param  Collection<int, User>  $parts
     * @return array<int, int>|RedirectResponse part number => line items
     */
    private function finalizedLineItems(Request $request, Rfq $rfq, Collection $parts): array|RedirectResponse
    {
        return $this->lineItemsGiven($request, $rfq, $parts, 'finalized_line_items', 'Enter the finalized line items for :part.', 'finalized line items');
    }

    /**
     * A count of line items for every one of $parts — $field[part number],
     * a whole number from 0 to MAX_DATA_ENTRIES — or back, saying what's
     * wrong: $missing (with :part for the part's number) when one's not
     * given, and in terms of $what otherwise.
     *
     * @param  Collection<int, User>  $parts
     * @return array<int, int>|RedirectResponse part number => line items
     */
    private function lineItemsGiven(Request $request, Rfq $rfq, Collection $parts, string $field, string $missing, string $what): array|RedirectResponse
    {
        $rules = [];
        $messages = [];

        foreach ($parts as $assignee) {
            $key = "{$field}.{$assignee->pivot->part_number}";

            $rules[$key] = ['required', 'integer', 'min:0', 'max:'.self::MAX_DATA_ENTRIES];
            $messages += [
                "{$key}.required" => str_replace(':part', $rfq->partNumberLabel($assignee->pivot->part_number), $missing),
                "{$key}.integer" => 'Give the '.$what.' as a whole number, like 12.',
                "{$key}.min" => 'The '.$what.' can\'t be less than 0.',
                "{$key}.max" => 'That\'s more line items than one part can have — at most '.number_format(self::MAX_DATA_ENTRIES).'.',
            ];
        }

        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        return $parts->mapWithKeys(fn (User $assignee) => [
            $assignee->pivot->part_number => (int) $request->input("{$field}.{$assignee->pivot->part_number}"),
        ])->all();
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

        $finalized = $this->finalizedLineItems($request, $rfq, collect([$rfq->assigneeForPart($part)]));

        if ($finalized instanceof RedirectResponse) {
            return $finalized;
        }

        $rfq->approveHeadOfBdPart($part, $this->doneBy($request, 'Head of Business Development'), $finalized[$part]);

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
        $this->abortIfPartOnHold($rfq);

        $finalized = $this->finalizedLineItems($request, $rfq, $rfq->partsAwaitingHeadOfBdApproval());

        if ($finalized instanceof RedirectResponse) {
            return $finalized;
        }

        $rfq->approveByHeadOfBd($this->doneBy($request, 'Head of Business Development'), $finalized);

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
     * through Business Development itself. With a part, just that one part
     * goes back — see Rfq::rejectPartToStage() — otherwise the whole RFQ,
     * which has to be at their review stage — see Rfq::rejectToStage().
     */
    public function rejectGm(Request $request, Rfq $rfq): RedirectResponse
    {
        return $this->reject($request, $rfq, 'gm_review', 'General Manager');
    }

    /**
     * GM Assistant sends the RFQ (or, with a part, just that part) back to
     * Senior Operations, Sourcing or Data Entry instead of adding their
     * details — see Rfq::RETURN_TARGETS.
     */
    public function rejectGmAssistant(Request $request, Rfq $rfq): RedirectResponse
    {
        return $this->reject($request, $rfq, 'gm_assistant', 'GM Assistant');
    }

    /**
     * Business Development sends an RFQ ready to close (or, with a part, just
     * that part) back to the Head of Business Development or the General
     * Manager instead of closing it — see Rfq::RETURN_TARGETS.
     */
    public function rejectBd(Request $request, Rfq $rfq): RedirectResponse
    {
        return $this->reject($request, $rfq, 'bd_closing', 'Business Development');
    }

    /**
     * A Sourcing part, still with its member, sent back to Senior Operations
     * with a required reason: the part is freed for them to assign again,
     * and the RFQ is on their Returns page until they have
     * (Rfq::rejectPartToStage() to 'operations'). Only Admin, on the
     * member's behalf (User::canSendSourcingPartsBackToSeniorOps()) —
     * Sourcing themselves no longer can. Recorded as the part's own member.
     * Once Sourcing has assigned it to Data Entry, it's past this.
     */
    public function returnSeniorOps(Request $request, Rfq $rfq): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'part' => ['required', 'integer'],
        ]);

        $part = (int) $validated['part'];
        $assignee = $rfq->assigneeForPart($part);

        abort_unless($assignee, 404, 'That part is not assigned on this RFQ.');
        $this->abortIfStopped($rfq, $assignee);

        abort_unless(
            $user->canSendSourcingPartsBackToSeniorOps(),
            403,
            'Sourcing can\'t send a part back to Senior Operations — an Admin can, on their behalf.'
        );
        abort_unless($assignee->pivot->completed_at === null, 422, 'This part has moved on past Sourcing.');

        $reason = $this->requiredComment($request, 'reason', 'Add a reason to send this part back to Senior Operations.', 1000);

        if ($reason instanceof RedirectResponse) {
            return $reason;
        }

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->rejectPartToStage($part, 'operations', $reason, $assignee, 'sourcing'));

        // Sourcing no longer holds the part — nothing of it to go back to.
        return $this->redirectToIndex($request)->with('status', "Sent {$rfq->partNumberLabel($part)} back to Senior Operations.");
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
     * Shared by rejectSeniorOps()/rejectHeadOfBd()/rejectGmAssistant()/
     * rejectGm()/rejectBd(): the stages that send an RFQ back with Reject &
     * Return (see Rfq::REJECTABLE_STAGES) all work the same way, only
     * $fromStage and $roleName differ — and where each can send it,
     * Rfq::RETURN_TARGETS.
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
                'gm_assistant' => $rfq->partAwaitsGmAssistant($part),
                'gm_review' => $rfq->partAwaitsGmApproval($part),
                'bd_closing' => $rfq->partAwaitsBdClosing($part),
            };
            abort_unless($awaits, 422, "This part is not awaiting {$roleName} review.");
        }

        $validated = $request->validateWithBag('reject', [
            'target_stage' => ['required', 'in:'.implode(',', Rfq::rejectTargetStages($fromStage))],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        if ($part === null) {
            RfqComment::attachingFiles($rfq, $files, fn () => $rfq->rejectToStage($validated['target_stage'], $validated['reason'], $this->doneBy($request, $roleName), $fromStage));

            return redirect()->back()->with('status', 'Sent back to '.Rfq::stageLabel($validated['target_stage']).'.');
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->rejectPartToStage($part, $validated['target_stage'], $validated['reason'], $this->doneBy($request, $roleName), $fromStage));

        return redirect()->back()->with('status', "Sent {$rfq->partNumberLabel($part)} back to ".Rfq::stageLabel($validated['target_stage']).'.');
    }

    /**
     * GM Assistant's Start on a part with them (Rfq::startGmAssistantPart()),
     * as Data Entry starts theirs (startDataEntry()): at any hour, their time
     * on it counting in working hours. One part at a time — not while they've
     * another running (RfqStep::runningGmAssistantOf()). Recorded as one of
     * GM Assistant — Admin can, naming who (doneBy()).
     */
    public function startGmAssistant(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['GM Assistant', 'Admin']), 403, 'Only GM Assistant can start on a part.');

        $part = (int) $request->validate(['part' => ['required', 'integer']])['part'];
        $assignee = $rfq->assigneeForPart($part);

        abort_unless($assignee, 404, 'That part is not assigned on this RFQ.');
        $this->abortIfStopped($rfq, $assignee);
        abort_unless($rfq->partAwaitsGmAssistantStart($part), 422, 'This part isn\'t waiting to be started.');

        $starter = $this->doneBy($request, 'GM Assistant');

        if ($running = RfqStep::runningGmAssistantOf($starter)) {
            return back()->with('error', ($starter->is($request->user()) ? 'You\'re' : "{$starter->name} is").' already on '
                .$running->rfq->partNumberLabel($running->part_number).' — submit it, or pause it, before starting another.');
        }

        $rfq->startGmAssistantPart($part, $starter);

        $offHours = Setting::offHoursReason(now(), $starter->id);

        return back()->with('status', $offHours === null
            ? "Started {$rfq->partNumberLabel($part)} — your time on it is counting."
            : "Started {$rfq->partNumberLabel($part)} — outside working hours, so your time on it isn't counting yet. {$offHours}");
    }

    /**
     * GM Assistant's Pause on the part they're on (Rfq::pauseGmAssistantPart()),
     * with why and the document it's waiting on, if any: its countdown stops
     * where it is, and they can start another. Only whoever started it —
     * Admin can, for them. The same popup as Data Entry's
     * (_pause_data_entry_modal), the same fields.
     */
    public function pauseGmAssistant(Request $request, Rfq $rfq): RedirectResponse
    {
        [$part, $assignee] = $this->gmAssistantPartOfStarter($request, $rfq);

        if (! $assignee->pivot->isGmAssistantRunning() || ! $rfq->partAwaitsGmAssistant($part)) {
            return back()->with('error', 'This part isn\'t running, so there\'s nothing to pause.');
        }

        $validator = Validator::make($request->all(), [
            'pause_reason' => ['required', 'string', 'max:1000'],
            'pause_reference' => ['nullable', 'string', 'max:255'],
        ], [
            'pause_reason.required' => 'Say why you\'re pausing it.',
            'pause_reason.max' => 'Keep the reason under 1000 characters.',
            'pause_reference.max' => 'Keep the document reference under 255 characters.',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $rfq->pauseGmAssistantPart($part, $request->user(), $validator->validated()['pause_reason'], $validator->validated()['pause_reference'] ?? null);

        return back()->with('status', "Paused {$rfq->partNumberLabel($part)} — its countdown has stopped. You can start another, and resume this one once you've nothing running.");
    }

    /**
     * GM Assistant's Resume on a part they'd paused (Rfq::resumeGmAssistantPart()):
     * its countdown goes on from where it stopped. One part at a time, as
     * with Start.
     */
    public function resumeGmAssistant(Request $request, Rfq $rfq): RedirectResponse
    {
        [$part, $assignee] = $this->gmAssistantPartOfStarter($request, $rfq);

        if (! $assignee->pivot->isGmAssistantPaused()) {
            return back()->with('error', 'This part isn\'t paused.');
        }

        $starter = $assignee->pivot->gmAssistantStartedBy;

        if ($starter && ($running = RfqStep::runningGmAssistantOf($starter))) {
            return back()->with('error', ($starter->is($request->user()) ? 'You\'re' : "{$starter->name} is").' on '
                .$running->rfq->partNumberLabel($running->part_number).' — submit it, or pause it, before resuming this one.');
        }

        $rfq->resumeGmAssistantPart($part);

        return back()->with('status', "Resumed {$rfq->partNumberLabel($part)} — its countdown carries on from where it stopped.");
    }

    /**
     * The part a GM Assistant Pause or Resume is for — [its number, its
     * assignee] — refused unless it's whoever started it's, or Admin's.
     *
     * @return array{0: int, 1: User}
     */
    private function gmAssistantPartOfStarter(Request $request, Rfq $rfq): array
    {
        $user = $request->user();
        abort_unless($user->hasAnyRole(['GM Assistant', 'Admin']), 403, 'Only GM Assistant can pause or resume a part.');

        $part = (int) $request->validate(['part' => ['required', 'integer']])['part'];
        $assignee = $rfq->assigneeForPart($part);

        abort_unless($assignee, 404, 'That part is not assigned on this RFQ.');
        $this->abortIfStopped($rfq, $assignee);
        abort_unless(
            $user->hasRole('Admin') || (int) $assignee->pivot->gm_assistant_started_by === $user->id,
            403,
            'Only whoever started this part can pause or resume it.'
        );

        return [$part, $assignee];
    }

    /**
     * GM Assistant's Submit: forwards it on to the General Manager, with a
     * comment and photos if they gave any — posted to the RFQ's
     * thread as theirs. With a part, just that one part goes on — see
     * Rfq::recordGmAssistantPart() — otherwise the whole RFQ, which has to be
     * at their step — see Rfq::recordGmAssistantDetails().
     */
    public function submitGmAssistantDetails(Request $request, Rfq $rfq): RedirectResponse
    {
        abort_unless(
            $request->user()->hasAnyRole(['GM Assistant', 'Admin']),
            403,
            'Only GM Assistant can submit this.'
        );

        $part = $request->filled('part') ? (int) $request->input('part') : null;

        if ($part === null) {
            abort_unless($rfq->stage === 'gm_assistant', 422, 'This RFQ is not waiting on GM Assistant.');
            $this->abortIfPartOnHold($rfq);
        } else {
            abort_unless($rfq->assigneeForPart($part), 404, 'That part is not assigned on this RFQ.');
            abort_unless($rfq->partAwaitsGmAssistant($part), 422, 'This part is not waiting on GM Assistant.');
        }

        // Their time on it runs from their Start — there's none to submit
        // before it, nor while it's paused (startGmAssistant()).
        foreach ($part === null ? $rfq->partsAwaitingGmAssistant() : collect([$rfq->assigneeForPart($part)]) as $waiting) {
            $label = $rfq->partNumberLabel($waiting->pivot->part_number);

            if (! $waiting->pivot->hasGmAssistantStarted()) {
                return back()->with('error', "Start on {$label} first — Submit comes after.");
            }

            if ($waiting->pivot->isGmAssistantPaused()) {
                return back()->with('error', "Resume {$label} first — it's paused.");
            }
        }

        $validator = Validator::make($request->all(), ['comment' => ['nullable', 'string', 'max:2000']], [
            'comment.max' => 'That comment is too long — keep it under 2000 characters.',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $files = $this->attachmentsFrom($request);

        if ($files instanceof RedirectResponse) {
            return $files;
        }

        // Photos with no words still go on the thread, with the Submit.
        $comment = trim((string) $request->input('comment'));
        $comment = $comment === '' && $files === [] ? null : $comment;
        $submittedBy = $this->doneBy($request, 'GM Assistant');

        if ($part === null) {
            RfqComment::attachingFiles($rfq, $files, fn () => $rfq->recordGmAssistantDetails($submittedBy, $comment));

            return redirect()->back()->with('status', 'Submitted — forwarded to General Manager.');
        }

        RfqComment::attachingFiles($rfq, $files, fn () => $rfq->recordGmAssistantPart($part, $submittedBy, $comment));

        return redirect()->back()->with('status', $rfq->stage === 'gm_review'
            ? 'Submitted — every part is through, forwarded to General Manager.'
            : "Submitted {$rfq->partNumberLabel($part)} — forwarded to General Manager.");
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
        $this->abortIfPartOnHold($rfq);

        $rfq->approveByGm($this->doneBy($request, 'General Manager'));

        return redirect()->back()->with('status', 'Approved — ready for Business Development to close.');
    }

    /**
     * Business Development closes one Sourcing part the General Manager has
     * approved — without waiting for the rest of a split — giving the
     * quotation number that's required to close anything, kept with the part.
     * The RFQ closes once every part has been. See Rfq::closePart().
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

        $referenceCode = $request->validateWithBag('close', $this->referenceCodeRules(), $this->referenceCodeMessages())['reference_code'];

        $rfq->closePart($part, $this->doneBy($request, 'Business Development'), $referenceCode);

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
     * yet closed on its own included — the true end of the lifecycle, giving
     * the quotation number that's required to close it, kept with it. See
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
        $this->abortIfPartOnHold($rfq);

        $validated = $request->validateWithBag('close', $this->referenceCodeRules(), $this->referenceCodeMessages());

        $rfq->closeOut($this->doneBy($request, 'Business Development'), $validated['reference_code']);

        return redirect()->back()
            ->with('celebrate', $this->celebration($rfq->rfq_number, "It's now in Closed RFQs.", grand: true))
            ->with('status', 'RFQ closed.');
    }

    /**
     * The quotation number (kept as the reference code) Business Development must give to close a part or an
     * RFQ — kept with it (Rfq::closePart(), closeOut()).
     *
     * @return array<string, array<int, string>>
     */
    private function referenceCodeRules(): array
    {
        return ['reference_code' => ['required', 'string', 'max:100']];
    }

    /**
     * @return array<string, string>
     */
    private function referenceCodeMessages(): array
    {
        return [
            'reference_code.required' => 'Give the quotation number to close it.',
            'reference_code.max' => 'Keep the quotation number under 100 characters.',
        ];
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
        $parameters = in_array($status, Rfq::LIST_STATUSES, true) ? ['status' => $status] : [];

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
    /**
     * $input and $rules with the WC number checked, while Settings has it on
     * (Setting::wcFormatRequired()): in the WC0000 format, typed in any case
     * and saved in capitals. With $digitsOnly — the Add RFQ form, whose field
     * has WC fixed before it — the four digits alone are taken too, and saved
     * with WC in front ("0123" is WC0123). Not when it's $unless — an RFQ's
     * own WC number, kept as it was through an edit.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, array<int, mixed>>  $rules
     * @return array{0: array<string, mixed>, 1: array<string, array<int, mixed>>}
     */
    protected function wcNumberChecked(array $input, array $rules, ?string $unless = null, bool $digitsOnly = false): array
    {
        $wcNumber = $input['wc_number'] ?? null;

        if (! Setting::wcFormatRequired() || ! is_string($wcNumber) || $wcNumber === $unless) {
            return [$input, $rules];
        }

        $wcNumber = strtoupper(trim($wcNumber));
        $input['wc_number'] = $digitsOnly && preg_match('/^\d{4}$/', $wcNumber) === 1 ? 'WC'.$wcNumber : $wcNumber;
        $rules['wc_number'][] = 'regex:'.Setting::WC_NUMBER_PATTERN;

        return [$input, $rules];
    }

    /**
     * What to say when a WC number isn't in the WC0000 format — on the Add
     * RFQ form ($digitsOnly), where only its digits are typed, in those terms.
     *
     * @return array<string, string>
     */
    protected function wcNumberMessages(bool $digitsOnly = false): array
    {
        return ['wc_number.regex' => $digitsOnly
            ? 'Type the WC number\'s four digits, like 0123 — WC is added for you.'
            : 'Use the WC0000 format — WC and four digits, like WC0123.'];
    }

    protected function rules(bool $requireRfqNumber = true): array
    {
        return [
            'wc_number' => ['required', 'string', 'max:255'],
            'rfq_number' => [$requireRfqNumber ? 'required' : 'nullable', 'string', 'max:255'],
            'priority_level' => ['required', 'string', 'in:'.implode(',', Rfq::PRIORITIES)],
            'number_of_items' => ['required', 'integer', 'min:1', 'max:100000'],
            'status' => ['required', 'string', 'in:'.implode(',', Rfq::STATUSES)],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
