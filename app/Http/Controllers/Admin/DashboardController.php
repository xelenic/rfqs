<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Rfq;
use App\Models\RfqAssignment;
use App\Models\RfqComment;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DashboardController extends Controller
{
    /**
     * The selectable RFQ-activity time ranges, in days.
     *
     * @var array<int, int>
     */
    public const RANGES = [1, 7, 30, 90];

    /**
     * The periods Business Development's summary can cover, besides a custom
     * one: how many days back, today included, and what the filter calls it.
     *
     * @var array<string, array{days: int, label: string}>
     */
    public const SUMMARY_PERIODS = [
        'today' => ['days' => 1, 'label' => 'Today'],
        '3d' => ['days' => 3, 'label' => '3 days'],
        'week' => ['days' => 7, 'label' => 'Week'],
    ];

    /**
     * How many events the summary's activity list shows, latest first.
     */
    public const SUMMARY_FEED_SIZE = 8;

    /**
     * Display the admin dashboard.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $canViewRfqs = $user->can('rfqs.view');

        // Business Development gets a dashboard of its own — its queues, where
        // the pending RFQs sit in the workflow, and what happened today. Admin,
        // which sees every role's side, keeps the general one.
        if ($canViewRfqs && $user->hasRole('Business Development') && ! $user->hasRole('Admin')) {
            $period = $this->summaryPeriod($request);

            return view('admin.dashboard.business-development', [
                'period' => $period,
                'overview' => $this->businessDevelopmentOverview($period),
                'rfqActivity' => $this->rfqActivity($request->integer('range', 7)),
                'recentRfqs' => Rfq::query()->with('assignees')->latest()->limit(6)->get(),
                'notifications' => $this->notifications($request),
            ]);
        }

        // Sourcing's is about their own parts: what's on their plate, what's
        // come back, what's with Data Entry, and what they were given last.
        if ($canViewRfqs && $user->hasRole('Sourcing') && ! $user->hasRole('Admin')) {
            $overview = $this->sourcingOverview($user);

            return view('admin.dashboard.sourcing', [
                'overview' => $overview,
                'notifications' => $this->sourcingNotifications($overview),
            ]);
        }

        return view('admin.dashboard', [
            'usersCount' => User::count(),
            'rolesCount' => Role::count(),
            'permissionsCount' => Permission::count(),
            'rfqActivity' => $canViewRfqs ? $this->rfqActivity($request->integer('range', 7)) : null,
            'recentRfqs' => $canViewRfqs
                ? Rfq::query()->with('assignees')->latest()->limit(6)->get()
                : null,
            'notifications' => $this->notifications($request),
        ]);
    }

    /**
     * The period Business Development's summary covers, from ?period= — and
     * ?from= / ?to= for a custom one — in whole days of the working hours'
     * time zone (Setting::timezone()); today when nothing (or nothing
     * sensible) is asked for. A custom range needs a date: from one on its
     * own it runs to today, a "to" on its own is just that day, and dates
     * the wrong way round are swapped. 'start' and 'end' are in the app's
     * time zone, as the times are stored.
     *
     * @return array{key: string, from: string, to: string, start: CarbonImmutable, end: CarbonImmutable, isToday: bool}
     */
    protected function summaryPeriod(Request $request): array
    {
        $timezone = Setting::timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $date = fn (mixed $value) => is_string($value) && CarbonImmutable::canBeCreatedFromFormat($value, 'Y-m-d')
            ? CarbonImmutable::createFromFormat('Y-m-d', $value, $timezone)->startOfDay()
            : null;

        $key = (string) $request->query('period');
        $from = $date($request->query('from'));
        $to = $date($request->query('to'));

        if ($key === 'custom' && ($from || $to)) {
            $to ??= $today;
            $from ??= $to;

            if ($from->gt($to)) {
                [$from, $to] = [$to, $from];
            }
        } else {
            $key = array_key_exists($key, self::SUMMARY_PERIODS) ? $key : 'today';
            $from = $today->subDays(self::SUMMARY_PERIODS[$key]['days'] - 1);
            $to = $today;
        }

        return [
            'key' => $key,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'start' => $from->setTimezone(config('app.timezone')),
            'end' => $to->endOfDay()->setTimezone(config('app.timezone')),
            'isToday' => $from->eq($today) && $to->eq($today),
        ];
    }

    /**
     * What Business Development's dashboard shows: how big each queue is,
     * where the pending RFQs currently sit in the workflow, and a summary of
     * $period (summaryPeriod()) — how many RFQs were created, approved by
     * the General Manager, returned and closed in it, and what happened.
     * Company-wide, like the Pending and Closed lists they see.
     *
     * The activity is the approval chain's milestones — created through
     * closed — since the Sourcing and Data Entry detail is restricted for
     * Business Development (see Rfq::APPROVAL_CHAIN_TIMELINE_TYPES). A
     * return is each send-back's own comment (Rfq::rejectToStage(), action
     * 'rejected'), so one stays counted after the RFQ is fixed and its
     * rejected_at cleared, and one RFQ returned twice counts once.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array{
     *     pending: int, urgent: int, inReview: int, readyToClose: int, closed: int, closedToday: int,
     *     stages: array<int, array{label: string, tone: string, count: int}>,
     *     summary: array{created: int, approved: int, returned: int, returnedToBd: int, closed: int},
     *     feed: Collection<int, array{rfq: Rfq, at: Carbon, actor: ?string, icon: string, tone: string, text: string}>,
     *     readyToCloseRfqs: Collection<int, Rfq>
     * }
     */
    protected function businessDevelopmentOverview(array $period): array
    {
        $pending = fn () => Rfq::query()->where('status', 'Pending');
        $beforeDataEntry = fn () => $pending()->whereNull('stage');

        $inStage = $pending()->whereNotNull('stage')
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage');

        $stages = [
            ['label' => 'Awaiting Sourcing', 'tone' => 'primary', 'count' => $beforeDataEntry()->needingSourcing()->count()],
            ['label' => 'With Sourcing', 'tone' => 'primary', 'count' => $beforeDataEntry()->fullySourced()->whereNull('sourcing_completed_at')->count()],
            ['label' => 'With Data Entry', 'tone' => 'primary', 'count' => $beforeDataEntry()->fullySourced()->whereNotNull('sourcing_completed_at')->count()],
            ['label' => 'Senior Operations review', 'tone' => 'violet', 'count' => $inStage['senior_ops_review'] ?? 0],
            ['label' => 'Head of Business Development', 'tone' => 'violet', 'count' => $inStage['head_of_bd_review'] ?? 0],
            ['label' => 'GM Assistant', 'tone' => 'violet', 'count' => $inStage['gm_assistant'] ?? 0],
            ['label' => 'General Manager', 'tone' => 'violet', 'count' => $inStage['gm_review'] ?? 0],
            ['label' => 'Ready to close', 'tone' => 'success', 'count' => $inStage['bd_closing'] ?? 0],
        ];

        $window = [$period['start'], $period['end']];
        $within = fn (string $column) => Rfq::query()->whereBetween($column, $window)->count();
        $returns = fn () => RfqComment::query()->where('action', 'rejected')->whereBetween('created_at', $window);

        // Each dated milestone: who did it, how it looks, and what to say.
        $milestones = [
            'created_at' => ['actor' => 'creator', 'icon' => 'bi-plus-circle', 'tone' => 'primary', 'text' => 'created'],
            'senior_ops_reviewed_at' => ['actor' => 'seniorOpsReviewedBy', 'icon' => 'bi-clipboard2-check', 'tone' => 'violet', 'text' => 'passed Senior Operations review'],
            'head_of_bd_approved_at' => ['actor' => 'headOfBdApprovedBy', 'icon' => 'bi-person-check', 'tone' => 'violet', 'text' => 'approved by Head of Business Development'],
            'gm_assistant_completed_at' => ['actor' => 'gmAssistantCompletedBy', 'icon' => 'bi-file-earmark-text', 'tone' => 'violet', 'text' => 'submitted to the General Manager by GM Assistant'],
            'gm_approved_at' => ['actor' => 'gmApprovedBy', 'icon' => 'bi-award', 'tone' => 'success', 'text' => 'approved by the General Manager — ready to close'],
            'bd_closed_at' => ['actor' => 'bdClosedBy', 'icon' => 'bi-flag-fill', 'tone' => 'success', 'text' => 'closed'],
        ];

        $feed = collect($milestones)
            ->flatMap(fn (array $milestone, string $column) => Rfq::query()
                ->with($milestone['actor'])
                ->whereBetween($column, $window)
                ->latest($column)
                ->limit(self::SUMMARY_FEED_SIZE)
                ->get()
                ->map(fn (Rfq $rfq) => [
                    'rfq' => $rfq,
                    'at' => $rfq->{$column},
                    'actor' => $rfq->{$milestone['actor']}?->name,
                    'icon' => $milestone['icon'],
                    'tone' => $milestone['tone'],
                    'text' => $milestone['text'],
                ]))
            ->concat($returns()
                ->with(['rfq', 'author'])
                ->latest()
                ->limit(self::SUMMARY_FEED_SIZE)
                ->get()
                ->filter(fn (RfqComment $comment) => $comment->rfq !== null)
                ->map(fn (RfqComment $comment) => [
                    'rfq' => $comment->rfq,
                    'at' => $comment->created_at,
                    'actor' => $comment->author?->name,
                    'icon' => 'bi-arrow-counterclockwise',
                    'tone' => 'danger',
                    'text' => 'returned to '.($comment->meta['stage'] ?? 'an earlier step'),
                ]))
            ->sortByDesc('at')
            ->take(self::SUMMARY_FEED_SIZE)
            ->values();

        return [
            'pending' => $pending()->count(),
            'urgent' => $pending()->where('priority_level', 'Urgent')->count(),
            'inReview' => collect(Rfq::REVIEW_STAGES)->sum(fn (string $stage) => $inStage[$stage] ?? 0),
            'readyToClose' => Rfq::bdClosingCount(),
            'closed' => Rfq::query()->where('status', 'Completed')->count(),
            'closedToday' => Rfq::query()->where('bd_closed_at', '>=', CarbonImmutable::now(Setting::timezone())->startOfDay()->setTimezone(config('app.timezone')))->count(),
            'stages' => $stages,
            'summary' => [
                'created' => $within('created_at'),
                'approved' => $within('gm_approved_at'),
                'returned' => $returns()->distinct()->count('rfq_id'),
                'returnedToBd' => $returns()->where('meta->stage', Rfq::stageLabel('business_development'))->distinct()->count('rfq_id'),
                'closed' => $within('bd_closed_at'),
            ],
            'feed' => $feed,
            // Longest-waiting first — they're the ones to close next.
            'readyToCloseRfqs' => $this->readyToClose(),
        ];
    }

    /**
     * The next few things for Business Development to close — each part the
     * General Manager has approved (the same rows as their Ready to Close page)
     * and any RFQ at its closing stage with no part of its own — longest-waiting
     * first. Each carries the number to show, and the part to close (none, for
     * a whole RFQ).
     *
     * @return Collection<int, array{rfq: Rfq, part: ?int, rfq_number: string, subject: string, approved_at: ?Carbon, approved_by: ?string}>
     */
    protected function readyToClose(): Collection
    {
        $rfqs = Rfq::query()->with(['assignees', 'gmApprovedBy'])->awaitingBdClosing()->get();

        $names = User::whereIn('id', $rfqs->flatMap(fn (Rfq $rfq) => $rfq->assignees->pluck('pivot.gm_approved_by'))->filter()->unique())->pluck('name', 'id');

        return $rfqs
            ->flatMap(function (Rfq $rfq) use ($names) {
                $parts = $rfq->assignees->filter(fn (User $assignee) => $assignee->pivot->isAwaitingBdClosing());

                if ($parts->isEmpty()) {
                    return [[
                        'rfq' => $rfq,
                        'part' => null,
                        'rfq_number' => $rfq->rfq_number,
                        'subject' => $rfq->subject,
                        'approved_at' => $rfq->gm_approved_at,
                        'approved_by' => $rfq->gmApprovedBy?->name,
                    ]];
                }

                return $parts->map(fn (User $assignee) => [
                    'rfq' => $rfq,
                    'part' => $assignee->pivot->part_number,
                    'rfq_number' => $rfq->partNumberLabel($assignee->pivot->part_number),
                    'subject' => $rfq->subject,
                    'approved_at' => $assignee->pivot->gm_approved_at,
                    'approved_by' => $names->get($assignee->pivot->gm_approved_by),
                ]);
            })
            ->sortBy('approved_at')
            ->take(5)
            ->values();
    }

    /**
     * What a Sourcing member's dashboard shows: their own parts of open
     * RFQs — one entry per part, since one person can hold several parts of
     * the same RFQ — counted by where each stands, plus the ones to put in
     * front of them: what they were given most recently, what Data Entry
     * sent back, and what's with Data Entry now.
     *
     * "Pending" is what's still theirs to complete or finalize, leaving out
     * what Data Entry sent back (that's "Returned", and their Returns list) —
     * the same count as the badge on their sidebar link.
     *
     * @return array{
     *     assigned: int, pending: int, urgent: int, returned: int, inReview: int, toFinalize: int, done: int, inProgress: int,
     *     recent: Collection<int, array{rfq: Rfq, part: int, label: string, assignment: RfqAssignment, state: string}>,
     *     returnedParts: Collection<int, array{rfq: Rfq, part: int, label: string, assignment: RfqAssignment, state: string}>,
     *     inReviewParts: Collection<int, array{rfq: Rfq, part: int, label: string, assignment: RfqAssignment, state: string}>
     * }
     */
    protected function sourcingOverview(User $user): array
    {
        $parts = Rfq::query()
            ->with('assignees')
            ->where('status', 'Pending')
            ->whereHas('assignees', fn ($assignees) => $assignees->whereKey($user->id))
            ->get()
            ->flatMap(fn (Rfq $rfq) => $rfq->assignees
                ->where('id', $user->id)
                ->map(fn (User $assignee) => [
                    'rfq' => $rfq,
                    'part' => $assignee->pivot->part_number,
                    'label' => $rfq->partNumberLabel($assignee->pivot->part_number),
                    'assignment' => $assignee->pivot,
                    'state' => $assignee->pivot->progressState(),
                ])
                ->values())
            ->values();

        $inState = fn (string $state) => $parts->where('state', $state);
        // Theirs to act on: not yet completed (and not sent back — that's a
        // return), or sent to finalize by Data Entry and waiting on their
        // Finalize. Matches the badge on their sidebar's Pending link.
        $stillTheirs = $parts->filter(fn (array $part) => ($part['assignment']->completed_at === null && $part['state'] !== 'returned')
            || $part['state'] === 'data_entry_done');

        return [
            'assigned' => $parts->count(),
            'pending' => $stillTheirs->count(),
            'urgent' => $stillTheirs->filter(fn (array $part) => $part['rfq']->priority_level === 'Urgent')->count(),
            'returned' => $inState('returned')->count(),
            'inReview' => $inState('with_data_entry')->count(),
            'toFinalize' => $inState('data_entry_done')->count(),
            'done' => $inState('finalized')->count(),
            'inProgress' => $inState('in_progress')->count(),
            'recent' => $parts->sortByDesc(fn (array $part) => $part['assignment']->created_at)->take(6)->values(),
            'returnedParts' => $inState('returned')->sortByDesc(fn (array $part) => $part['assignment']->returned_at)->take(5)->values(),
            'inReviewParts' => $inState('with_data_entry')->sortByDesc(fn (array $part) => $part['assignment']->completed_at)->take(5)->values(),
        ];
    }

    /**
     * A Sourcing member's alerts — only about their own parts, not the
     * company-wide ones the general dashboard leads with.
     *
     * @param  array{pending: int, urgent: int, returned: int}  $overview
     * @return array<int, array{type: string, icon: string, title: string, description: string, url: string}>
     */
    protected function sourcingNotifications(array $overview): array
    {
        $notifications = [];

        if ($overview['returned'] > 0) {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'bi-arrow-counterclockwise',
                'title' => trans_choice('1 part was sent back|:count parts were sent back', $overview['returned'], ['count' => $overview['returned']]),
                'description' => 'Data Entry returned it for rework.',
                'url' => route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']),
            ];
        }

        if ($overview['urgent'] > 0) {
            $notifications[] = [
                'type' => 'warning',
                'icon' => 'bi-exclamation-triangle-fill',
                'title' => trans_choice('1 urgent part is waiting|:count urgent parts are waiting', $overview['urgent'], ['count' => $overview['urgent']]),
                'description' => 'Marked Urgent and not yet completed.',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
            ];
        }

        if ($overview['pending'] > 0) {
            $notifications[] = [
                'type' => 'primary',
                'icon' => 'bi-hourglass-split',
                'title' => trans_choice('1 part is waiting on you|:count parts are waiting on you', $overview['pending'], ['count' => $overview['pending']]),
                'description' => 'Mark each complete as you finish it.',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
            ];
        }

        return $notifications;
    }

    /**
     * Personalized alerts for the dashboard's messages panel — only what's
     * actionable for this user, scoped to what they're allowed to see.
     *
     * @return array<int, array{type: string, icon: string, title: string, description: string, url: string}>
     */
    protected function notifications(Request $request): array
    {
        $user = $request->user();
        $notifications = [];

        // Closing is Business Development's own step — lead with it.
        if ($user->hasRole('Business Development')) {
            $readyToClose = Rfq::bdClosingCount();
            if ($readyToClose > 0) {
                $notifications[] = [
                    'type' => 'success',
                    'icon' => 'bi-flag',
                    'title' => trans_choice('1 RFQ ready to close|:count RFQs ready to close', $readyToClose, ['count' => $readyToClose]),
                    'description' => 'Approved by the General Manager.',
                    'url' => route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']),
                ];
            }
        }

        if ($user->can('rfqs.view')) {
            $urgentPending = Rfq::where('status', 'Pending')->where('priority_level', 'Urgent')->count();
            if ($urgentPending > 0) {
                $notifications[] = [
                    'type' => 'danger',
                    'icon' => 'bi-exclamation-triangle-fill',
                    'title' => trans_choice('1 urgent RFQ needs attention|:count urgent RFQs need attention', $urgentPending, ['count' => $urgentPending]),
                    'description' => 'Marked Urgent and still pending.',
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }

            $awaitingOperations = Rfq::where('status', 'Pending')->whereNull('operations_assigned_by')->count();
            if ($awaitingOperations > 0) {
                $notifications[] = [
                    'type' => 'warning',
                    'icon' => 'bi-diagram-2',
                    'title' => trans_choice('1 RFQ awaiting Operations|:count RFQs awaiting Operations', $awaitingOperations, ['count' => $awaitingOperations]),
                    'description' => "No one's routed these yet.",
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }

            $awaitingSourcing = Rfq::where('status', 'Pending')->needingSourcing()->count();
            if ($awaitingSourcing > 0) {
                $notifications[] = [
                    'type' => 'warning',
                    'icon' => 'bi-person-plus',
                    'title' => trans_choice('1 RFQ awaiting Sourcing|:count RFQs awaiting Sourcing', $awaitingSourcing, ['count' => $awaitingSourcing]),
                    'description' => 'Not yet assigned to anyone.',
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }
        }

        $assignedToMe = $user->assignedRfqs()->where('rfqs.status', 'Pending')->count();
        if ($assignedToMe > 0) {
            $notifications[] = [
                'type' => 'primary',
                'icon' => 'bi-person-check',
                'title' => trans_choice('1 RFQ assigned to you|:count RFQs assigned to you', $assignedToMe, ['count' => $assignedToMe]),
                'description' => 'Still pending your action.',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
            ];
        }

        return $notifications;
    }

    /**
     * Bucket RFQs created in the selected range by status, zero-filled so the
     * chart never skips a period with no activity.
     *
     * @return array{
     *     range: int, labels: array<int, string>, pending: array<int, int>,
     *     completed: array<int, int>, pendingTotal: int, completedTotal: int
     * }
     */
    protected function rfqActivity(int $range): array
    {
        $range = in_array($range, self::RANGES, true) ? $range : 7;
        $hourly = $range === 1;

        $start = $hourly
            ? Carbon::now()->subHours(23)->startOfHour()
            : Carbon::now()->subDays($range - 1)->startOfDay();

        $bucketKey = fn (Carbon $date) => $hourly ? $date->format('Y-m-d H') : $date->format('Y-m-d');

        $buckets = collect(range(0, $hourly ? 23 : $range - 1))
            ->map(fn ($i) => $hourly ? $start->copy()->addHours($i) : $start->copy()->addDays($i));

        $pending = $buckets->mapWithKeys(fn ($date) => [$bucketKey($date) => 0])->all();
        $completed = $pending;

        Rfq::query()
            ->where('created_at', '>=', $start)
            ->get(['status', 'created_at'])
            ->each(function (Rfq $rfq) use (&$pending, &$completed, $bucketKey) {
                $key = $bucketKey($rfq->created_at);

                if (! array_key_exists($key, $pending)) {
                    return;
                }

                if ($rfq->status === 'Completed') {
                    $completed[$key]++;
                } else {
                    $pending[$key]++;
                }
            });

        return [
            'range' => $range,
            'labels' => $buckets->map(fn (Carbon $date) => $hourly ? $date->format('g A') : $date->format('M j'))->all(),
            'pending' => array_values($pending),
            'completed' => array_values($completed),
            'pendingTotal' => array_sum($pending),
            'completedTotal' => array_sum($completed),
        ];
    }
}
