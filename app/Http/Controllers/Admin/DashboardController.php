<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\RfqAssignment;
use App\Models\RfqComment;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\DataEntryIdle;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
     * What each tracked step (RfqStep::ROLE_STEPS) is called as a task on
     * Admin's dashboard.
     *
     * @var array<string, string>
     */
    public const TASK_LABELS = [
        'sourcing' => 'Sourcing',
        'finalize' => 'Finalize',
        'data_entry' => 'Data entry',
        'gm_assistant' => 'GM Assistant submit',
    ];

    /**
     * The periods Admin's dashboard can cover besides a custom one — what's
     * been finished, how the team did, and the charts follow it; what's
     * open and in progress is always as of now. Seven days to begin with.
     *
     * @var array<string, array{days: int, label: string}>
     */
    public const ADMIN_PERIODS = [
        'today' => ['days' => 1, 'label' => 'Today'],
        'week' => ['days' => 7, 'label' => '7 days'],
        'month' => ['days' => 30, 'label' => '30 days'],
        'quarter' => ['days' => 90, 'label' => '90 days'],
    ];

    public const ADMIN_DEFAULT_PERIOD = 'week';

    /**
     * How often the open Critical monitor fetches itself again, and how long
     * the top bar's count of what's critical is kept before it's worked out
     * again — in seconds.
     */
    public const CRITICAL_REFRESH_SECONDS = 30;

    public const CRITICAL_COUNT_SECONDS = 60;

    /**
     * Display the admin dashboard.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $canViewRfqs = $user->can('rfqs.view');

        // Admin's is the whole operation at a glance, like a project board:
        // what's waiting where, who's on what right now and how it stands
        // against its target, how each person is doing, Data Entry's idle
        // time, and the charts of it.
        if ($canViewRfqs && $user->hasRole('Admin')) {
            $period = $this->summaryPeriod($request, self::ADMIN_PERIODS, self::ADMIN_DEFAULT_PERIOD);

            return view('admin.dashboard.admin', [
                'period' => $period,
                'overview' => $this->adminOverview($period),
                'rfqActivity' => $this->rfqActivityIn($period),
                'notifications' => $this->notifications($request),
            ]);
        }

        // Business Development gets a dashboard of its own — its queues, where
        // the pending RFQs sit in the workflow, and what happened today. Admin,
        // which sees every role's side, keeps the general one.
        if ($canViewRfqs && $user->hasRole('Business Development') && ! $user->hasRole('Admin')) {
            $period = $this->summaryPeriod($request);
            // Only the RFQs they created, if that's all they see (User::seesOnlyOwnRfqs()).
            $createdBy = $user->ownRfqsOnlyId();

            return view('admin.dashboard.business-development', [
                'period' => $period,
                'overview' => $this->businessDevelopmentOverview($period, $createdBy),
                'rfqActivity' => $this->rfqActivity($request->integer('range', 7), $createdBy),
                'recentRfqs' => Rfq::query()->ownedBy($createdBy)->with('assignees')->latest()->limit(6)->get(),
                'notifications' => $this->notifications($request),
            ]);
        }

        // Data Entry's is about their own work: the parts waiting to be
        // started, the one they're on against its target, and what they've
        // sent to finalize — entries, time and all — over the period.
        if ($canViewRfqs && $user->hasRole('Data Entry') && ! $user->hasRole('Admin')) {
            $period = $this->summaryPeriod($request, self::ADMIN_PERIODS, self::ADMIN_DEFAULT_PERIOD);
            $overview = $this->dataEntryOverview($user, $period);

            return view('admin.dashboard.data-entry', [
                'period' => $period,
                'overview' => $overview,
                'notifications' => $this->dataEntryNotifications($overview),
            ]);
        }

        // The General Manager's is their approvals — what's waiting on them,
        // what they've approved and sent back — and how the business stands:
        // the open RFQs, where they are, and what's been created and closed.
        // The Head of Business Development's and GM Assistant's are the same,
        // for their own step: what's been sent on to them, and what the
        // General Manager has sent back.
        foreach (['General Manager' => 'general-manager', 'Head of Business Development' => 'head-of-business-development', 'GM Assistant' => 'gm-assistant'] as $role => $page) {
            if ($canViewRfqs && $user->hasRole($role) && ! $user->hasRole('Admin')) {
                $period = $this->summaryPeriod($request, self::ADMIN_PERIODS, self::ADMIN_DEFAULT_PERIOD);
                $stage = $this->approverStage($role);
                $overview = $this->approverOverview($user, $period, $stage);

                return view("admin.dashboard.{$page}", [
                    'period' => $period,
                    'stage' => $stage,
                    'overview' => $overview,
                    'rfqActivity' => $this->rfqActivityIn($period),
                    'notifications' => $this->approverNotifications($overview, $stage),
                ]);
            }
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

        // HR Executive's is about people: today's attendance sheet, what's
        // waiting on their approval, how attendance has gone over the period,
        // and the team.
        if ($user->hasRole('HR Executive') && ! $user->hasRole('Admin')) {
            $period = $this->summaryPeriod($request, self::ADMIN_PERIODS, self::ADMIN_DEFAULT_PERIOD);
            $overview = $this->hrOverview($period);

            return view('admin.dashboard.hr-executive', [
                'period' => $period,
                'overview' => $overview,
                'notifications' => $this->hrNotifications($overview),
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
     * The Critical monitor, opened from the top bar (layouts/_critical_modal)
     * and fetched into it — on opening, then every CRITICAL_REFRESH_SECONDS:
     * everything that needs attention now. The tasks overdue or nearly so
     * (ongoingTasks()), and the share on time; who in Data Entry is idle with
     * parts waiting (dataEntryFloor()); the Urgent and High RFQs still open,
     * longest-waiting first; what's been sent back and is waiting on
     * someone; and the viewer's own unread alerts and messages. For those
     * who run the operation (User::canSeeCriticalMonitor()).
     */
    public function critical(Request $request): View
    {
        abort_unless($request->user()->canSeeCriticalMonitor(), 403, 'Only Admin and Senior Operations have the Critical monitor.');

        $now = CarbonImmutable::now();
        $today = $now->setTimezone(Setting::timezone())->startOfDay();
        $user = $request->user();
        $isAdmin = $user->hasRole('Admin');

        $ongoing = $this->ongoingTasks($now);
        $overdue = $ongoing->where('state', 'overdue')->sortBy('remaining')->values();
        $timed = $ongoing->whereNotNull('target');
        $floor = $this->dataEntryFloor($now, $today);
        $idle = $floor['people']->where('state', 'Idle')->sortByDesc('seconds')->values();

        $urgentCounts = Rfq::query()->where('status', 'Pending')->whereIn('priority_level', ['Urgent', 'High'])
            ->selectRaw('priority_level, count(*) as total')->groupBy('priority_level')->pluck('total', 'priority_level');
        $urgent = Rfq::query()
            ->where('status', 'Pending')
            ->whereIn('priority_level', ['Urgent', 'High'])
            ->with('assignees')
            ->orderByRaw("case priority_level when 'Urgent' then 0 else 1 end")
            ->oldest()
            ->limit(8)
            ->get()
            ->map(fn (Rfq $rfq) => [
                'rfq' => $rfq,
                'where' => match (true) {
                    $rfq->reject_target_stage !== null => 'Sent back to '.Rfq::stageLabel($rfq->reject_target_stage),
                    $rfq->stage !== null => Rfq::stageLabel($rfq->stage),
                    $rfq->hasUnassignedParts() => 'Awaiting assignment',
                    default => 'With Sourcing or Data Entry',
                },
            ]);

        $queues = Rfq::queueCounts();
        $returnsPage = fn (string $role) => $isAdmin
            ? route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns', 'role' => $role])
            : ($role === 'senior-operations' ? route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) : null);
        $returns = collect([
            ['label' => 'Business Development', 'count' => $queues['bd_returns'], 'url' => $returnsPage('business-development')],
            ['label' => 'Senior Operations', 'count' => $queues['ops_returns'], 'url' => $returnsPage('senior-operations')],
            ['label' => 'Sourcing', 'count' => $queues['sourcing_returns'], 'url' => $returnsPage('sourcing')],
            ['label' => 'Head of Business Development', 'count' => $queues['head_of_bd_returns'], 'url' => $returnsPage('head-of-business-development')],
            ['label' => 'GM Assistant', 'count' => $queues['gm_assistant_returns'], 'url' => $returnsPage('gm-assistant')],
        ]);

        $bell = $user->bellItems(20);
        $count = $overdue->count() + $idle->count();
        Cache::put('critical-monitor.count', $count, self::CRITICAL_COUNT_SECONDS);

        return view('admin.critical.monitor', [
            'count' => $count,
            'refreshSeconds' => self::CRITICAL_REFRESH_SECONDS,
            'overdue' => $overdue,
            'dueSoon' => $ongoing->where('state', 'due_soon')->count(),
            'ongoingCount' => $ongoing->count(),
            'health' => $timed->isEmpty() ? null : (int) round($timed->where('state', '!==', 'overdue')->count() / $timed->count() * 100),
            'timedCount' => $timed->count(),
            'floor' => $floor,
            'idle' => $idle,
            'alertMinutes' => Setting::dataEntryIdleAlert()['minutes'],
            'urgent' => $urgent,
            'urgentCounts' => ['Urgent' => (int) ($urgentCounts['Urgent'] ?? 0), 'High' => (int) ($urgentCounts['High'] ?? 0)],
            'returns' => $returns,
            'alerts' => collect($bell['items'])->where('kind', 'alert')->where('unread', true)->values(),
            'messages' => collect($bell['items'])->where('kind', 'message')->where('unread', true)->values(),
            'unreadAlerts' => $user->unreadNotifications()->count(),
            'unreadMessages' => $user->receivedMessages()->unread()->count(),
        ]);
    }

    /**
     * How many things are critical right now — tasks overdue, and people in
     * Data Entry idle with parts waiting — for the count on the top bar's
     * Critical monitor button. Kept CRITICAL_COUNT_SECONDS (every page
     * shows it), and put back fresh whenever the monitor itself is opened.
     */
    public static function criticalCount(): int
    {
        return (int) Cache::remember('critical-monitor.count', self::CRITICAL_COUNT_SECONDS, function () {
            $dashboard = app(static::class);
            $now = CarbonImmutable::now();

            return $dashboard->ongoingTasks($now)->where('state', 'overdue')->count()
                + $dashboard->dataEntryFloor($now, $now->setTimezone(Setting::timezone())->startOfDay())['idleCount'];
        });
    }

    /**
     * What Admin's dashboard shows: the headline figures (kpis), every task
     * someone's on right now (ongoingTasks()), what's waiting at each stage
     * (pipeline()), how each tracked person is doing (teamPerformance()),
     * where Data Entry stands (dataEntryFloor()), the tasks finished over
     * the period (throughput()), the open RFQs by priority, and the latest
     * workflow steps taken in it. What's open, waiting, in progress or idle
     * is as of now; what's been finished — and the team's figures and the
     * charts — is over $period (summaryPeriod()). Days are the working
     * hours' own (Setting::timezone()).
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: string, to: string, isToday: bool}  $period
     * @return array<string, mixed>
     */
    protected function adminOverview(array $period): array
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone(Setting::timezone())->startOfDay();
        $window = [$period['start'], $period['end']];

        $queues = Rfq::queueCounts();
        $pipeline = $this->pipeline($queues);
        $ongoing = $this->ongoingTasks($now);
        $dataEntry = $this->dataEntryFloor($now, $today);
        $team = $this->teamPerformance($ongoing, $dataEntry, $now, $period);

        $open = Rfq::query()->where('status', 'Pending');
        $byPriority = (clone $open)->selectRaw('priority_level, count(*) as total')->groupBy('priority_level')->pluck('total', 'priority_level');
        $finished = RfqStep::query()->where('ended_by_role', true)->whereBetween('ended_at', $window)->count();
        $closed = Rfq::query()->whereBetween('bd_closed_at', $window)->count();
        $overdue = $ongoing->where('state', 'overdue')->count();
        $working = $ongoing->pluck('worker.id')->filter()->unique()->count();

        return [
            'kpis' => [
                ['label' => 'Open RFQs', 'value' => (clone $open)->count(), 'caption' => ($byPriority['Urgent'] ?? 0).' urgent', 'icon' => 'bi-folder2-open', 'tone' => '', 'alert' => ($byPriority['Urgent'] ?? 0) > 0, 'url' => route('admin.rfqs.index', ['status' => 'Pending'])],
                ['label' => 'Pending tasks', 'value' => collect($pipeline)->sum('count'), 'caption' => 'Waiting across the workflow', 'icon' => 'bi-list-check', 'tone' => 'violet', 'alert' => false, 'url' => '#pending-by-stage'],
                ['label' => 'In progress', 'value' => $ongoing->count(), 'caption' => trans_choice('1 person working|:count people working', $working, ['count' => $working]), 'icon' => 'bi-play-circle', 'tone' => 'teal', 'alert' => false, 'url' => '#ongoing-tasks'],
                ['label' => 'Overdue', 'value' => $overdue, 'caption' => 'Past their target', 'icon' => 'bi-alarm', 'tone' => 'danger', 'alert' => $overdue > 0, 'url' => '#ongoing-tasks'],
                ['label' => $period['isToday'] ? 'Done today' : 'Finished', 'value' => $finished, 'caption' => trans_choice('1 RFQ closed|:count RFQs closed', $closed, ['count' => $closed]), 'icon' => 'bi-check2-circle', 'tone' => 'success', 'alert' => false, 'url' => '#throughput'],
                ['label' => 'Data Entry idle', 'value' => $dataEntry['idleCount'], 'caption' => $dataEntry['idleSeconds'] > 0 ? Setting::durationLabel($dataEntry['idleSeconds']).' idle in all' : 'Nobody idle now', 'icon' => 'bi-hourglass-bottom', 'tone' => 'warning', 'alert' => $dataEntry['idleCount'] > 0, 'url' => '#data-entry-floor'],
            ],
            'ongoing' => $ongoing,
            'pipeline' => $pipeline,
            'team' => $team,
            'dataEntry' => $dataEntry,
            'throughput' => $this->throughput($period),
            'workload' => [
                'labels' => $team->map(fn (array $row) => $row['user']->name)->all(),
                'inHand' => $team->map(fn (array $row) => $row['inHand'] ?? 0)->all(),
                'done' => $team->pluck('done')->all(),
            ],
            'priorities' => collect(array_reverse(Rfq::PRIORITIES))->mapWithKeys(fn (string $priority) => [$priority => (int) ($byPriority[$priority] ?? 0)])->all(),
            'activity' => $this->companyActivity($period),
        ];
    }

    /**
     * What's waiting at each stage of the workflow — the same counts as the
     * sidebar's queues (Rfq::queueCounts()), each linking to Admin's view of
     * that role's page. A stage's returns are counted with it, apart from
     * the two sent back to an earlier step, which have rows of their own.
     *
     * @param  array<string, int>  $queues
     * @return array<int, array{label: string, role: string, count: int, tone: string, url: string}>
     */
    protected function pipeline(array $queues): array
    {
        $page = fn (string $role, ?string $view = null) => route('admin.rfqs.index', array_filter(['status' => 'Pending', 'view' => $view, 'role' => $role]));

        return [
            ['label' => 'Awaiting assignment', 'role' => 'Senior Operations', 'count' => $queues['unassigned'], 'tone' => 'primary', 'url' => $page('senior-operations')],
            ['label' => 'With Sourcing', 'role' => 'Sourcing', 'count' => $queues['sourcing_pending'], 'tone' => 'primary', 'url' => $page('sourcing')],
            ['label' => 'Sent back to Sourcing', 'role' => 'Sourcing', 'count' => $queues['sourcing_returns'], 'tone' => 'danger', 'url' => $page('sourcing', 'returns')],
            ['label' => 'With Data Entry', 'role' => 'Data Entry', 'count' => $queues['data_entry'], 'tone' => 'primary', 'url' => $page('data-entry')],
            ['label' => 'Senior Operations review', 'role' => 'Senior Operations', 'count' => $queues['ops_review'], 'tone' => 'violet', 'url' => $page('senior-operations', 'review')],
            ['label' => 'Head of Business Development', 'role' => 'Head of Business Development', 'count' => $queues['head_of_bd'] + $queues['head_of_bd_returns'], 'tone' => 'violet', 'url' => $page('head-of-business-development')],
            ['label' => 'GM Assistant', 'role' => 'GM Assistant', 'count' => $queues['gm_assistant'] + $queues['gm_assistant_returns'], 'tone' => 'violet', 'url' => $page('gm-assistant')],
            ['label' => 'General Manager', 'role' => 'General Manager', 'count' => $queues['gm_review'], 'tone' => 'violet', 'url' => $page('general-manager')],
            ['label' => 'Ready to close', 'role' => 'Business Development', 'count' => $queues['closing'], 'tone' => 'success', 'url' => $page('business-development', 'closing')],
            ['label' => 'Sent back to Business Development', 'role' => 'Business Development', 'count' => $queues['bd_returns'], 'tone' => 'danger', 'url' => $page('business-development', 'returns')],
        ];
    }

    /**
     * Every task someone's on right now — each open stretch of a tracked
     * step (RfqStep::ROLE_STEPS) on a pending RFQ — with whose it is, the
     * working time it's taken, and, for Sourcing's and Data Entry's own
     * work and Finalize, where it stands against its target
     * (Rfq::sourcingCountdown() / dataEntryCountdown() / finalizeCountdown() /
     * gmAssistantCountdown(), so absences and resumed rounds count as they do
     * there): overdue first, then nearest to it, then the longest-running.
     * Each with when it was last updated: the later of when it reached the
     * step it's at (or was resumed there) and its part's last change.
     *
     * @return Collection<int, array{rfq: Rfq, label: string, task: string, role: ?string, worker: ?User, started_at: CarbonInterface, updated_at: CarbonInterface, seconds: int, target: ?int, remaining: ?int, state: string}>
     */
    protected function ongoingTasks(CarbonInterface $now): Collection
    {
        $steps = RfqStep::query()
            ->whereNull('ended_at')
            ->whereIn('step', array_keys(self::TASK_LABELS))
            ->whereHas('rfq', fn ($rfqs) => $rfqs->where('status', 'Pending'))
            ->with(['rfq.steps', 'rfq.assignees'])
            ->get();

        $workers = User::query()->whereIn('id', $steps->map(fn (RfqStep $step) => $step->workerId())->filter()->unique())->get()->keyBy('id');
        $states = ['overdue', 'due_soon', 'on_track', 'running'];

        return $steps
            ->map(function (RfqStep $step) use ($now, $workers) {
                $rfq = $step->rfq;
                $countdown = match ($step->step) {
                    'sourcing' => $rfq->sourcingCountdown((int) $step->part_number, $now),
                    'data_entry' => $rfq->dataEntryCountdown((int) $step->part_number, $now),
                    'finalize' => $rfq->finalizeCountdown((int) $step->part_number, $now),
                    'gm_assistant' => $rfq->gmAssistantCountdown((int) $step->part_number, $now),
                    default => null,
                };

                return [
                    'rfq' => $rfq,
                    'label' => $step->part_number !== null && $rfq->isSplit() ? $rfq->partNumberLabel($step->part_number) : $rfq->rfq_number,
                    'task' => self::TASK_LABELS[$step->step],
                    'role' => $step->role(),
                    'worker' => $workers->get($step->workerId()),
                    'started_at' => $step->started_at,
                    'updated_at' => max($step->started_at, $rfq->assignees->first(fn (User $assignee) => $assignee->pivot->part_number === $step->part_number)?->pivot->updated_at ?? $step->started_at),
                    'seconds' => max(0, $countdown !== null ? $countdown['target'] - $countdown['remaining'] : $step->workingSeconds($now)),
                    'target' => $countdown['target'] ?? null,
                    'remaining' => $countdown['remaining'] ?? null,
                    'state' => match (true) {
                        $countdown === null => 'running',
                        $countdown['remaining'] < 0 => 'overdue',
                        $countdown['remaining'] < $countdown['target'] / 4 => 'due_soon',
                        default => 'on_track',
                    },
                ];
            })
            ->sortBy(fn (array $task) => [array_search($task['state'], $states, true), $task['remaining'] ?? -$task['seconds']])
            ->values();
    }

    /**
     * Where each Data Entry person stands now: on a part (RfqStep::
     * runningDataEntryOf()), idle with parts waiting (User::dataEntryIdleness()
     * — which needs the idle alert on), on leave by today's sheet, or free —
     * with how many are idle and for how long in all, the parts waiting for
     * someone to start them, and how many people Senior Operations has been
     * alerted about today (DataEntryIdle).
     *
     * @return array{people: Collection<int, array{user: User, state: string, tone: string, detail: ?string, seconds: int}>, idleCount: int, idleSeconds: int, waiting: int, alertedToday: int, alertOn: bool}
     */
    protected function dataEntryFloor(CarbonInterface $now, CarbonImmutable $today): array
    {
        $alert = Setting::dataEntryIdleAlert();

        $people = User::query()->holdingRole('Data Entry')->orderBy('name')->get()->map(function (User $person) use ($now, $today, $alert) {
            $running = RfqStep::runningDataEntryOf($person);

            if ($running !== null) {
                $label = $running->rfq->isSplit() ? $running->rfq->partNumberLabel($running->part_number) : $running->rfq->rfq_number;

                return ['user' => $person, 'state' => 'Working', 'tone' => 'success', 'detail' => $label.' · '.Setting::durationLabel($running->workingSeconds($now)), 'seconds' => 0];
            }

            $idleness = $person->dataEntryIdleness($now);

            if ($idleness !== null) {
                return [
                    'user' => $person,
                    'state' => 'Idle',
                    'tone' => $idleness['idle_seconds'] >= $alert['minutes'] * 60 ? 'danger' : 'warning',
                    'detail' => Setting::durationLabel($idleness['idle_seconds']).($idleness['alerted_at'] ? ' · Senior Operations alerted' : ''),
                    'seconds' => $idleness['idle_seconds'],
                ];
            }

            if (Attendance::markOn($person->id, $today->toDateString())?->status === Attendance::ABSENT) {
                return ['user' => $person, 'state' => 'On leave', 'tone' => 'secondary', 'detail' => null, 'seconds' => 0];
            }

            return ['user' => $person, 'state' => 'Free', 'tone' => 'info', 'detail' => null, 'seconds' => 0];
        });

        $idle = $people->where('state', 'Idle');

        return [
            'people' => $people,
            'idleCount' => $idle->count(),
            'idleSeconds' => (int) $idle->sum('seconds'),
            'waiting' => Rfq::dataEntryWaitingSince()->count(),
            'alertedToday' => DatabaseNotification::query()
                ->where('type', DataEntryIdle::class)
                ->where('created_at', '>=', $today->setTimezone(config('app.timezone')))
                ->get(['data'])
                ->map(fn (DatabaseNotification $notification) => $notification->data['data_entry_user_id'] ?? null)
                ->filter()
                ->unique()
                ->count(),
            'alertOn' => $alert['enabled'],
        ];
    }

    /**
     * How each person whose time is tracked (AttendanceSheet::people()) is
     * doing: what they're on now — or idle, on leave, free — what's in their
     * hands, and over $period (summaryPeriod()) the tasks they finished, the working time each took on average (a whole round,
     * absences aside — Rfq::roundsOf()), and how many of those with a target
     * — Sourcing's and Data Entry's own work — were done within it. Most
     * tasks finished first.
     *
     * @param  Collection<int, array<string, mixed>>  $ongoing  ongoingTasks()
     * @param  array{people: Collection<int, array<string, mixed>>}  $dataEntry  dataEntryFloor()
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Collection<int, array{user: User, role: string, state: string, tone: string, detail: ?string, inHand: ?int, done: int, average: ?int, onTime: ?int, overdue: int}>
     */
    protected function teamPerformance(Collection $ongoing, array $dataEntry, CarbonInterface $now, array $period): Collection
    {
        $people = AttendanceSheet::people();
        $book = Attendance::book($people->pluck('id'));

        $finished = RfqStep::query()
            ->where('ended_by_role', true)
            ->whereBetween('ended_at', [$period['start'], $period['end']])
            ->whereIn('step', array_keys(self::TASK_LABELS))
            ->with('rfq.steps')
            ->get()
            ->map(function (RfqStep $stretch) use ($book, $now) {
                $round = Rfq::roundsOf($stretch->rfq->steps->filter(fn (RfqStep $other) => $other->part_number === $stretch->part_number && $other->step === $stretch->step))
                    ->first(fn (Collection $round) => $round->contains('id', $stretch->id)) ?? collect([$stretch]);
                $target = match ($stretch->step) {
                    'sourcing' => $stretch->rfq->sourcingTargetSeconds(),
                    'data_entry' => $stretch->rfq->dataEntryTargetSeconds($stretch->part_number),
                    'gm_assistant' => $stretch->rfq->gmAssistantTargetSeconds($stretch->part_number),
                    default => null,
                };
                $seconds = (int) $round->sum(fn (RfqStep $part) => $part->secondsExcludingAbsence($book, $now));
                // Sourcing's time on a part runs on across its rounds with the
                // same member (Rfq::sourcingTimeOf()): on time if, all told,
                // it was within the target when they finished this round.
                $judged = $stretch->step === 'sourcing'
                    ? (int) Rfq::sourcingTimeOf($stretch->rfq->steps, $stretch)->sum(fn (RfqStep $part) => $part->secondsExcludingAbsence($book, $now))
                    : $seconds;

                return ['worker' => $stretch->workerId(), 'seconds' => $seconds, 'onTime' => $target === null ? null : $judged <= $target];
            })
            ->groupBy('worker');

        $sourcingInHand = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->whereNull('rfq_user.status')
            ->whereNull('rfq_user.finalized_at')
            ->where(fn ($open) => $open->whereNull('rfq_user.completed_at')->orWhereNotNull('rfq_user.data_entry_completed_at'))
            ->groupBy('rfq_user.user_id')
            ->selectRaw('rfq_user.user_id, count(*) as total')
            ->pluck('total', 'user_id');

        $floor = $dataEntry['people']->keyBy(fn (array $row) => $row['user']->id);
        $today = $now->copy()->setTimezone(Setting::timezone())->toDateString();

        return $people
            ->map(function (User $person) use ($finished, $ongoing, $sourcingInHand, $floor, $today) {
                $mine = $ongoing->filter(fn (array $task) => $task['worker']?->id === $person->id);
                $done = $finished->get($person->id, collect());
                $timed = $done->whereNotNull('onTime');
                $role = $person->roles->pluck('name')->intersect(array_keys(RfqStep::ROLE_STEPS))->first();

                [$state, $tone, $detail] = match (true) {
                    $floor->has($person->id) && $floor[$person->id]['state'] !== 'Free' => [$floor[$person->id]['state'], $floor[$person->id]['tone'], $floor[$person->id]['detail']],
                    $mine->isNotEmpty() => ['Working', 'success', $mine->first()['label'].($mine->count() > 1 ? ' +'.($mine->count() - 1) : '')],
                    Attendance::markOn($person->id, $today)?->status === Attendance::ABSENT => ['On leave', 'secondary', null],
                    default => ['Free', 'info', null],
                };

                return [
                    'user' => $person,
                    'role' => (string) $role,
                    'state' => $state,
                    'tone' => $tone,
                    'detail' => $detail,
                    'inHand' => match (true) {
                        $person->hasRole('Sourcing') => (int) ($sourcingInHand[$person->id] ?? 0),
                        $person->hasRole('Data Entry') => $mine->count(),
                        default => null,
                    },
                    'done' => $done->count(),
                    'average' => $done->isEmpty() ? null : intdiv((int) $done->sum('seconds'), $done->count()),
                    'onTime' => $timed->isEmpty() ? null : (int) round($timed->where('onTime', true)->count() / $timed->count() * 100),
                    'overdue' => $mine->where('state', 'overdue')->count(),
                ];
            })
            ->sortBy([['done', 'desc'], ['user.name', 'asc']])
            ->values();
    }

    /**
     * The tasks finished over $period, span by span (periodSpans()) — each
     * tracked step a role ended themselves (RfqStep::ended_by_role) — by
     * role, for the throughput chart and its table.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: string, to: string}  $period
     * @return array{labels: array<int, string>, dates: array<int, string>, series: array<int, array{key: string, label: string, values: array<int, int>}>, total: int}
     */
    protected function throughput(array $period): array
    {
        ['spans' => $spans, 'keyOf' => $keyOf] = $this->periodSpans($period);

        $finished = RfqStep::query()
            ->where('ended_by_role', true)
            ->whereBetween('ended_at', [$period['start'], $period['end']])
            ->whereIn('step', array_keys(self::TASK_LABELS))
            ->get(['step', 'ended_at'])
            ->countBy(fn (RfqStep $stretch) => $stretch->role().'|'.$keyOf($stretch->ended_at));

        return [
            'labels' => array_column($spans, 'label'),
            'dates' => array_column($spans, 'title'),
            'series' => collect(array_keys(RfqStep::ROLE_STEPS))->map(fn (string $role) => [
                'key' => Str::slug($role),
                'label' => $role,
                'values' => collect($spans)->map(fn (array $span) => (int) ($finished[$role.'|'.$span['key']] ?? 0))->all(),
            ])->all(),
            'total' => (int) $finished->sum(),
        ];
    }

    /**
     * The spans a chart over $period is drawn in — hours for a single day,
     * days up to two months, weeks (from Monday) beyond — in the working
     * hours' time zone: each with its key, its axis label and its table
     * row's name, and keyOf() to tell which span a moment falls in.
     *
     * @param  array{from: string, to: string}  $period
     * @return array{unit: string, spans: array<int, array{key: string, label: string, title: string}>, keyOf: Closure(CarbonInterface): string}
     */
    protected function periodSpans(array $period): array
    {
        $timezone = Setting::timezone();
        $from = CarbonImmutable::parse($period['from'], $timezone)->startOfDay();
        $to = CarbonImmutable::parse($period['to'], $timezone)->endOfDay();
        $days = (int) $from->diffInDays($to->startOfDay()) + 1;
        $unit = match (true) {
            $days === 1 => 'hour',
            $days <= 62 => 'day',
            default => 'week',
        };

        $keyOf = fn (CarbonInterface $at) => match ($unit) {
            'hour' => CarbonImmutable::instance($at)->setTimezone($timezone)->format('Y-m-d H'),
            'day' => CarbonImmutable::instance($at)->setTimezone($timezone)->format('Y-m-d'),
            default => CarbonImmutable::instance($at)->setTimezone($timezone)->startOfWeek(CarbonInterface::MONDAY)->format('Y-m-d'),
        };

        $spans = [];
        $cursor = $unit === 'week' ? $from->startOfWeek(CarbonInterface::MONDAY) : $from;

        while ($cursor <= $to) {
            $spans[] = [
                'key' => $keyOf($cursor),
                'label' => $cursor->format($unit === 'hour' ? 'g A' : 'M j'),
                'title' => match ($unit) {
                    'hour' => $cursor->format('D, M j · g A'),
                    'day' => $cursor->format('D, M j'),
                    default => 'Week of '.$cursor->format('M j'),
                },
            ];
            $cursor = match ($unit) {
                'hour' => $cursor->addHour(),
                'day' => $cursor->addDay(),
                default => $cursor->addWeek(),
            };
        }

        return ['unit' => $unit, 'spans' => $spans, 'keyOf' => $keyOf];
    }

    /**
     * The RFQs created over $period, span by span (periodSpans()) — still
     * open, and closed — for the activity chart on Admin's dashboard, which
     * follows the dashboard's period rather than a range of its own. The
     * same shape as rfqActivity().
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: string, to: string}  $period
     * @return array{range: null, labels: array<int, string>, pending: array<int, int>, completed: array<int, int>, pendingTotal: int, completedTotal: int}
     */
    protected function rfqActivityIn(array $period): array
    {
        ['spans' => $spans, 'keyOf' => $keyOf] = $this->periodSpans($period);

        $created = Rfq::query()
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->get(['status', 'created_at'])
            ->countBy(fn (Rfq $rfq) => ($rfq->status === 'Completed' ? 'completed' : 'pending').'|'.$keyOf($rfq->created_at));

        $pending = collect($spans)->map(fn (array $span) => (int) ($created['pending|'.$span['key']] ?? 0))->all();
        $completed = collect($spans)->map(fn (array $span) => (int) ($created['completed|'.$span['key']] ?? 0))->all();

        return [
            'range' => null,
            'labels' => array_column($spans, 'label'),
            'pending' => $pending,
            'completed' => $completed,
            'pendingTotal' => array_sum($pending),
            'completedTotal' => array_sum($completed),
        ];
    }

    /**
     * The latest workflow steps taken company-wide over $period — each the
     * comment it left (RfqComment::ACTIONS) — with who took them.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return Collection<int, RfqComment>
     */
    protected function companyActivity(array $period, int $limit = 8): Collection
    {
        return RfqComment::query()
            ->whereNotNull('action')
            ->whereBetween('created_at', [$period['start'], $period['end']])
            ->whereHas('rfq')
            ->with(['rfq', 'author'])
            ->latest()
            ->limit($limit)
            ->get();
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
     * $presets and $default are Business Development's summary's unless
     * given — Admin's dashboard has its own (ADMIN_PERIODS). 'dates' is how
     * the period reads: "Monday, Oct 12", "Oct 6 – Oct 12".
     *
     * @param  array<string, array{days: int, label: string}>  $presets
     * @return array{key: string, from: string, to: string, start: CarbonImmutable, end: CarbonImmutable, isToday: bool, dates: string}
     */
    protected function summaryPeriod(Request $request, array $presets = self::SUMMARY_PERIODS, string $default = 'today'): array
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
            $key = array_key_exists($key, $presets) ? $key : $default;
            $from = $today->subDays($presets[$key]['days'] - 1);
            $to = $today;
        }

        $thisYear = fn (CarbonImmutable $day) => $day->year === $today->year ? '' : ', Y';

        return [
            'key' => $key,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'start' => $from->setTimezone(config('app.timezone')),
            'end' => $to->endOfDay()->setTimezone(config('app.timezone')),
            'isToday' => $from->eq($today) && $to->eq($today),
            'dates' => match (true) {
                $from->eq($to) => $from->format('l, M j'.$thisYear($from)),
                $from->year === $to->year => $from->format('M j').' – '.$to->format('M j'.$thisYear($to)),
                default => $from->format('M j, Y').' – '.$to->format('M j, Y'),
            },
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
    protected function businessDevelopmentOverview(array $period, ?int $createdBy = null): array
    {
        $mine = fn () => Rfq::query()->ownedBy($createdBy);
        $pending = fn () => $mine()->where('status', 'Pending');
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
        $within = fn (string $column) => $mine()->whereBetween($column, $window)->count();
        $returns = fn () => RfqComment::query()->where('action', 'rejected')->whereBetween('created_at', $window)
            ->when($createdBy !== null, fn ($theirs) => $theirs->whereHas('rfq', fn ($rfq) => $rfq->ownedBy($createdBy)));

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
            ->flatMap(fn (array $milestone, string $column) => $mine()
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
            'readyToClose' => Rfq::bdClosingCount($createdBy),
            'closed' => $mine()->where('status', 'Completed')->count(),
            'closedToday' => $mine()->where('bd_closed_at', '>=', CarbonImmutable::now(Setting::timezone())->startOfDay()->setTimezone(config('app.timezone')))->count(),
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
            'readyToCloseRfqs' => $this->readyToClose($createdBy),
        ];
    }

    /**
     * The next few things for Business Development to close — each part the
     * General Manager has approved (the same rows as their Ready to Close page)
     * and any RFQ at its closing stage with no part of its own — longest-waiting
     * first. Each carries the number to show, and the part to close (none, for
     * a whole RFQ). Of the RFQs $createdBy created, if given.
     *
     * @return Collection<int, array{rfq: Rfq, part: ?int, rfq_number: string, subject: string, approved_at: ?Carbon, approved_by: ?string}>
     */
    protected function readyToClose(?int $createdBy = null): Collection
    {
        $rfqs = Rfq::query()->ownedBy($createdBy)->with(['assignees', 'gmApprovedBy'])->awaitingBdClosing()->get();

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
     * What a Data Entry person's dashboard shows — their own work, not the
     * company's:
     *
     *  - now: the parts waiting for them to start — given to them by name,
     *    or to anyone in Data Entry — Urgent first then longest-waiting; the
     *    one they're on (RfqStep::runningDataEntryOf()) against its target
     *    (Rfq::dataEntryCountdown()); idle time against the alert
     *    (User::dataEntryIdleness()); and the hours they work today
     *    (Setting::weekOn() — their own, their role's or the company's);
     *  - over $period (summaryPeriod()): the parts they sent to finalize,
     *    with the entries they made (rfq_user.data_entry_entries) and the
     *    working time each took — its last Data Entry round, absences aside
     *    (Rfq::roundsOf()), as on Admin's dashboard — against its priority's
     *    target; span by span for the output chart (periodSpans()) and by
     *    priority; and the parts they returned to Sourcing;
     *  - and the last few they sent, whenever.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: string, to: string, isToday: bool, dates: string}  $period
     * @return array<string, mixed>
     */
    protected function dataEntryOverview(User $user, array $period): array
    {
        $now = CarbonImmutable::now();
        $window = [$period['start'], $period['end']];
        $priorities = array_reverse(Rfq::PRIORITIES);
        $book = Attendance::book([$user->id]);

        // Waiting to be started — theirs to start: given to them by name, or
        // to anyone in Data Entry.
        $isWaiting = fn ($parts) => $parts->whereNull('rfq_user.status')
            ->whereNotNull('rfq_user.completed_at')
            ->whereNull('rfq_user.data_entry_completed_at')
            ->whereNull('rfq_user.data_entry_started_at')
            ->tap(fn ($theirs) => RfqAssignment::whereForDataEntryUser($theirs, $user->id));

        $waiting = Rfq::query()
            ->where('rfqs.status', 'Pending')
            ->whereHas('assignees', $isWaiting)
            ->with('assignees')
            ->get()
            ->flatMap(fn (Rfq $rfq) => $rfq->assignees
                ->filter(fn (User $holder) => $holder->pivot->status === null
                    && $holder->pivot->completed_at !== null
                    && $holder->pivot->data_entry_completed_at === null
                    && $holder->pivot->data_entry_started_at === null
                    && $rfq->partIsForDataEntryUser($holder->pivot->part_number, $user))
                ->map(fn (User $holder) => [
                    'rfq' => $rfq,
                    'label' => $rfq->isSplit() ? $rfq->partNumberLabel($holder->pivot->part_number) : $rfq->rfq_number,
                    'since' => collect([$holder->pivot->completed_at, $holder->pivot->data_entry_returned_at, $holder->pivot->status_changed_at, $rfq->rejected_at])->filter()->max(),
                    'sentBack' => $holder->pivot->data_entry_returned_at !== null,
                    'forMe' => (int) $holder->pivot->data_entry_assigned_to === $user->id,
                ]))
            ->sortBy(fn (array $part) => [array_search($part['rfq']->priority_level, $priorities, true), $part['since']?->getTimestamp() ?? 0])
            ->values();

        // The one they're on.
        $running = RfqStep::runningDataEntryOf($user);
        $current = null;

        if ($running !== null) {
            $rfq = $running->rfq->load('steps');
            $countdown = $rfq->dataEntryCountdown((int) $running->part_number, $now);
            $seconds = $countdown !== null ? max(0, $countdown['target'] - $countdown['remaining']) : $running->workingSeconds($now);

            $current = [
                'rfq' => $rfq,
                'label' => $rfq->isSplit() ? $rfq->partNumberLabel($running->part_number) : $rfq->rfq_number,
                'started_at' => $running->started_at,
                'seconds' => $seconds,
                'target' => $countdown['target'] ?? null,
                'remaining' => $countdown['remaining'] ?? null,
                'state' => match (true) {
                    $countdown === null => 'running',
                    $countdown['remaining'] < 0 => 'overdue',
                    $countdown['remaining'] < $countdown['target'] / 4 => 'due_soon',
                    default => 'on_track',
                },
            ];
        }

        // What they sent to finalize, with the time its last round took.
        $sentBy = fn (?array $within) => Rfq::query()
            ->whereHas('assignees', fn ($parts) => $parts->where('rfq_user.data_entry_completed_by', $user->id)
                ->when($within, fn ($inPeriod) => $inPeriod->whereBetween('rfq_user.data_entry_completed_at', $within)))
            ->with(['assignees', 'steps'])
            ->get()
            ->flatMap(fn (Rfq $rfq) => $rfq->assignees
                ->filter(fn (User $holder) => (int) $holder->pivot->data_entry_completed_by === $user->id
                    && $holder->pivot->data_entry_completed_at !== null
                    && ($within === null || $holder->pivot->data_entry_completed_at->between(...$within)))
                ->map(function (User $holder) use ($rfq, $book, $now) {
                    $part = $holder->pivot;
                    $round = Rfq::roundsOf($rfq->steps
                        ->filter(fn (RfqStep $step) => $step->step === 'data_entry' && $step->part_number === $part->part_number && $step->ended_at !== null)
                        ->sortBy('started_at')
                        ->values())->last();
                    $seconds = $round === null ? null : (int) $round->sum(fn (RfqStep $step) => $step->secondsExcludingAbsence($book, $now));
                    $target = $rfq->dataEntryTargetSeconds($part->part_number);

                    return [
                        'rfq' => $rfq,
                        'label' => $rfq->isSplit() ? $rfq->partNumberLabel($part->part_number) : $rfq->rfq_number,
                        'priority' => $rfq->priority_level,
                        'at' => $part->data_entry_completed_at,
                        'entries' => $part->data_entry_entries,
                        'seconds' => $seconds,
                        'target' => $target,
                        'onTime' => $seconds === null ? null : $seconds <= $target,
                    ];
                }))
            ->sortByDesc(fn (array $sent) => $sent['at']->getTimestamp())
            ->values();

        $sent = $sentBy($window);
        $timed = $sent->whereNotNull('onTime');
        $returned = RfqComment::query()
            ->where('user_id', $user->id)
            ->where('action', 'returned_to_sourcing')
            ->whereBetween('created_at', $window)
            ->count();

        ['spans' => $spans, 'keyOf' => $keyOf] = $this->periodSpans($period);
        $bySpan = $sent->groupBy(fn (array $part) => $keyOf($part['at']));

        // Out of their hours they're neither idle nor free — the idle alert
        // doesn't run then either (AlertIdleDataEntry).
        $alert = Setting::dataEntryIdleAlert();
        $offHours = $current === null ? Setting::offHoursReason($now, $user->id) : null;
        $idleness = $current === null && $offHours === null ? $user->dataEntryIdleness($now) : null;
        $today = Setting::today();

        return [
            'waiting' => $waiting,
            'waitingByPriority' => collect($priorities)->mapWithKeys(fn (string $priority) => [$priority => $waiting->filter(fn (array $part) => $part['rfq']->priority_level === $priority)->count()])->all(),
            'current' => $current,
            'idleness' => $idleness,
            'alert' => $alert,
            'offHours' => $offHours,
            'hoursToday' => Setting::weekOn($today, $user->id)[strtolower(CarbonImmutable::parse($today)->englishDayOfWeek)],
            'sent' => $sent->count(),
            'entries' => (int) $sent->sum('entries'),
            'withEntries' => $sent->whereNotNull('entries')->count(),
            'average' => $timed->isEmpty() ? null : intdiv((int) $timed->sum('seconds'), $timed->count()),
            'onTime' => $timed->isEmpty() ? null : (int) round($timed->where('onTime', true)->count() / $timed->count() * 100),
            'onTimeCount' => $timed->where('onTime', true)->count(),
            'timedCount' => $timed->count(),
            'returned' => $returned,
            'output' => [
                'labels' => array_column($spans, 'label'),
                'dates' => array_column($spans, 'title'),
                'parts' => collect($spans)->map(fn (array $span) => $bySpan->get($span['key'], collect())->count())->all(),
                'entries' => collect($spans)->map(fn (array $span) => (int) $bySpan->get($span['key'], collect())->sum('entries'))->all(),
            ],
            'byPriority' => collect($priorities)->map(function (string $priority) use ($sent) {
                $mine = $sent->where('priority', $priority);
                $timed = $mine->whereNotNull('onTime');

                return [
                    'priority' => $priority,
                    'sent' => $mine->count(),
                    'entries' => (int) $mine->sum('entries'),
                    'average' => $timed->isEmpty() ? null : intdiv((int) $timed->sum('seconds'), $timed->count()),
                    // By priority, the priority's; per item, what the parts sent had on average.
                    'target' => $mine->isNotEmpty() ? (int) round($mine->avg('target')) : (Setting::dataEntryPerItem()['enabled'] ? null : (Setting::dataEntryTargets()[$priority] ?? 0) * 60),
                    'onTime' => $timed->where('onTime', true)->count(),
                    'timed' => $timed->count(),
                ];
            })->all(),
            'latest' => $sentBy(null)->take(6),
        ];
    }

    /**
     * What HR Executive's dashboard shows. Today: everyone who goes on the
     * attendance sheet (AttendanceSheet::people()) with their mark on today's
     * sheet — approved or not — and the hours they work today
     * (Setting::weekOn()). Over $period: each person's working days present,
     * on a half day, on leave and not yet counted, by the approved sheets
     * (Attendance::book()), from the day attendance started. And the sheets
     * — waiting on their approval, returned, the latest — the working days
     * still without one, and the team: active people by role, who joined in
     * the period, and how many accounts are deactivated.
     *
     * @param  array{from: string, to: string, start: CarbonImmutable, end: CarbonImmutable}  $period
     * @return array<string, mixed>
     */
    protected function hrOverview(array $period): array
    {
        $today = Setting::today();
        $weekday = strtolower(CarbonImmutable::parse($today)->format('l'));
        $people = AttendanceSheet::people();
        $trackedRoles = array_keys(RfqStep::ROLE_STEPS);
        $todaySheet = AttendanceSheet::query()->whereDate('date', $today)->with(['attendances', 'approvedBy', 'returnedBy'])->first();
        $marks = $todaySheet?->attendances->keyBy('user_id') ?? collect();

        $todayRows = $people->map(function (User $person) use ($marks, $today, $weekday, $trackedRoles) {
            $mark = $marks->get($person->id);
            $hours = Setting::weekOn($today, $person->id)[$weekday] ?? ['working' => false];

            return [
                'user' => $person,
                'roles' => $person->roles->pluck('name')->intersect($trackedRoles)->values(),
                'status' => $mark?->status ?? Attendance::UNMARKED,
                'timeOff' => $mark?->timeOffLabel(),
                'note' => $mark?->note,
                'hours' => $hours['working'] ? "{$hours['start']}–{$hours['end']}" : null,
            ];
        });

        $since = Setting::attendanceSince();
        $book = Attendance::book($people->pluck('id'));
        $days = collect();

        for ($day = CarbonImmutable::parse($period['from']); $day->toDateString() <= min($period['to'], $today); $day = $day->addDay()) {
            if ($since !== null && $day->toDateString() >= $since) {
                $days->push($day);
            }
        }

        $attendance = $people->map(function (User $person) use ($days, $book) {
            $counts = ['present' => 0, 'half' => 0, 'leave' => 0, 'unmarked' => 0];

            foreach ($days as $day) {
                if (! (Setting::weekOn($day->toDateString(), $person->id)[strtolower($day->format('l'))]['working'] ?? false)) {
                    continue;
                }

                $status = Attendance::statusIn($book, $person->id, $day->toDateString());
                $counts[match (true) {
                    $status === Attendance::PRESENT => 'present',
                    $status === Attendance::ABSENT => 'leave',
                    $status === Attendance::UNMARKED => 'unmarked',
                    default => 'half',
                }]++;
            }

            $counted = $counts['present'] + $counts['half'] + $counts['leave'];

            return ['user' => $person] + $counts + [
                'rate' => $counted > 0 ? (int) round(($counts['present'] + $counts['half'] / 2) / $counted * 100) : null,
            ];
        });

        $active = User::query()->active()->with('roles')->get();
        $counted = $attendance->sum(fn (array $row) => $row['present'] + $row['half'] + $row['leave']);

        return [
            'today' => $today,
            'todaySheet' => $todaySheet,
            'isWorkingDay' => Setting::workingHours()[$weekday]['working'] ?? false,
            'todayRows' => $todayRows,
            'todayCounts' => [
                'present' => $todayRows->where('status', Attendance::PRESENT)->count(),
                'half' => $todayRows->where('status', Attendance::HALF_DAY)->count(),
                'leave' => $todayRows->where('status', Attendance::ABSENT)->count(),
                'unmarked' => $todayRows->where('status', Attendance::UNMARKED)->count(),
            ],
            'attendanceSince' => $since,
            'attendance' => $attendance,
            'attendanceRate' => $counted > 0 ? (int) round(($attendance->sum('present') + $attendance->sum('half') / 2) / $counted * 100) : null,
            'awaitingApproval' => AttendanceSheet::awaitingApprovalCount(),
            'returned' => AttendanceSheet::returnedCount(),
            'missingDays' => AttendanceSheet::missingDays(),
            'sheets' => AttendanceSheet::query()->with(['attendances', 'createdBy', 'approvedBy', 'returnedBy'])->latest('date')->limit(7)->get(),
            'team' => Role::query()->orderBy('name')->get()
                ->map(fn (Role $role) => ['role' => $role->name, 'count' => $active->filter(fn (User $member) => $member->roles->contains('name', $role->name))->count()])
                ->filter(fn (array $row) => $row['count'] > 0)
                ->values(),
            'activePeople' => $active->count(),
            'deactivated' => User::query()->whereNotNull('deactivated_at')->count(),
            'joined' => User::query()->whereBetween('created_at', [$period['start'], $period['end']])->count(),
        ];
    }

    /**
     * HR Executive's alerts: sheets waiting on their approval, today's sheet
     * not in yet, working days without one, sheets back with Senior
     * Operations, who's on leave today — and attendance not being counted at
     * all, when it isn't.
     *
     * @param  array<string, mixed>  $overview  hrOverview()
     * @return array<int, array{type: string, icon: string, title: string, description: string, url: string}>
     */
    protected function hrNotifications(array $overview): array
    {
        $sheets = route('admin.attendance.index');
        $notifications = [];

        if ($overview['attendanceSince'] === null) {
            return [[
                'type' => 'info',
                'icon' => 'bi-info-circle',
                'title' => 'Attendance isn\'t counted yet',
                'description' => 'An Admin chooses the day it starts, in Settings → Working Hours.',
                'url' => $sheets,
            ]];
        }

        if ($overview['awaitingApproval'] > 0) {
            $notifications[] = [
                'type' => 'warning',
                'icon' => 'bi-hourglass-split',
                'title' => trans_choice('1 attendance sheet awaits your approval|:count attendance sheets await your approval', $overview['awaitingApproval'], ['count' => $overview['awaitingApproval']]),
                'description' => 'Nobody\'s time counts on those days until you approve them.',
                'url' => $sheets,
            ];
        }

        $earlierMissing = array_values(array_diff($overview['missingDays'], [$overview['today']]));

        if (in_array($overview['today'], $overview['missingDays'], true)) {
            $notifications[] = [
                'type' => 'info',
                'icon' => 'bi-clipboard',
                'title' => 'Today\'s attendance sheet isn\'t in yet',
                'description' => 'Senior Operations fills it in and sends it to you.',
                'url' => $sheets,
            ];
        }

        if ($earlierMissing !== []) {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'bi-calendar-x',
                'title' => trans_choice('1 earlier working day has no sheet|:count earlier working days have no sheet', count($earlierMissing), ['count' => count($earlierMissing)]),
                'description' => 'Senior Operations still has to make '.(count($earlierMissing) === 1 ? 'it' : 'them').' — the oldest is '.CarbonImmutable::parse(end($earlierMissing))->format('M d, Y').'.',
                'url' => $sheets,
            ];
        }

        if ($overview['returned'] > 0) {
            $notifications[] = [
                'type' => 'info',
                'icon' => 'bi-arrow-counterclockwise',
                'title' => trans_choice('1 sheet is back with Senior Operations|:count sheets are back with Senior Operations', $overview['returned'], ['count' => $overview['returned']]),
                'description' => 'You returned it to be corrected; it comes back to you.',
                'url' => $sheets,
            ];
        }

        $onLeave = $overview['todayRows']->where('status', Attendance::ABSENT);

        if ($onLeave->isNotEmpty()) {
            $notifications[] = [
                'type' => 'info',
                'icon' => 'bi-person-dash',
                'title' => trans_choice('1 person is on leave today|:count people are on leave today', $onLeave->count(), ['count' => $onLeave->count()]),
                'description' => $onLeave->map(fn (array $row) => $row['user']->name)->join(', ', ' and ').'.',
                'url' => $sheets,
            ];
        }

        return $notifications;
    }

    /**
     * A Data Entry person's alerts — about their own work and the queue they
     * take from, not the company-wide ones.
     *
     * @param  array<string, mixed>  $overview  dataEntryOverview()
     * @return array<int, array{type: string, icon: string, title: string, description: string, url: string}>
     */
    protected function dataEntryNotifications(array $overview): array
    {
        $queue = route('admin.rfqs.index', ['status' => 'Pending']);
        $notifications = [];

        if ($overview['current'] !== null && $overview['current']['state'] === 'overdue') {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'bi-alarm',
                'title' => $overview['current']['label'].' is past its target',
                'description' => 'Send it to finalize as soon as it\'s done.',
                'url' => $queue,
            ];
        }

        if ($overview['idleness'] !== null && $overview['idleness']['idle_seconds'] > 0) {
            $notifications[] = [
                'type' => $overview['idleness']['alerted_at'] !== null ? 'danger' : 'warning',
                'icon' => 'bi-hourglass-bottom',
                'title' => 'Idle for '.Setting::durationLabel($overview['idleness']['idle_seconds']),
                'description' => $overview['idleness']['alerted_at'] !== null
                    ? 'Senior Operations has been told — start a part to clear it.'
                    : 'Senior Operations is told after '.$overview['alert']['minutes'].' minutes — start a part.',
                'url' => $queue,
            ];
        }

        $urgent = $overview['waitingByPriority']['Urgent'] ?? 0;

        if ($urgent > 0) {
            $notifications[] = [
                'type' => 'warning',
                'icon' => 'bi-exclamation-triangle-fill',
                'title' => trans_choice('1 urgent part is waiting|:count urgent parts are waiting', $urgent, ['count' => $urgent]),
                'description' => 'Start these first.',
                'url' => $queue,
            ];
        }

        $forMe = $overview['waiting']->where('forMe', true)->count();

        if ($forMe > 0) {
            $notifications[] = [
                'type' => 'primary',
                'icon' => 'bi-person-check',
                'title' => trans_choice('1 part was assigned to you|:count parts were assigned to you', $forMe, ['count' => $forMe]),
                'description' => 'Given to you by name by Sourcing — yours to start.',
                'url' => $queue,
            ];
        }

        $sentBack = $overview['waiting']->where('sentBack', true)->count();

        if ($sentBack > 0) {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'bi-arrow-counterclockwise',
                'title' => trans_choice('1 part was sent back to Data Entry|:count parts were sent back to Data Entry', $sentBack, ['count' => $sentBack]),
                'description' => 'Sourcing returned it at Finalize — see why on the part.',
                'url' => $queue,
            ];
        }

        return $notifications;
    }

    /**
     * What sets one approver's dashboard apart (approverOverview()) — the
     * General Manager's, the Head of Business Development's or GM
     * Assistant's: who they are, what's waiting on them (an RFQ query, and
     * whether one part of it is), the part's columns for who passed it on and
     * when, the one it's measured from (when it reached them), who sends it
     * to them, their Returns page for what the General Manager sent back to
     * them (not the General Manager's own), the countdown on their queue
     * where their step has a target, and how their step reads — approving,
     * or (GM Assistant's) submitting.
     *
     * @return array{role: string, from: string, since: string, by: string, at: string, rfqBy: string, rfqAt: string, waiting: Closure, awaits: Closure, returns: ?Closure, returnsUrl: ?string, countdown: ?Closure, countdownStep: ?string, anchor: string, words: array<string, string>}
     */
    protected function approverStage(string $role): array
    {
        $approving = [
            'awaiting' => 'Awaiting your approval',
            'done' => 'Approved',
            'doneToday' => 'Approved today',
            'average' => 'Avg. time to approve',
            'waitingTitle' => 'Waiting for your approval',
            'queueLink' => 'Your approvals',
            'fullyDone' => '1 RFQ fully approved|:count RFQs fully approved',
            'waits' => '1 part waits on your approval|:count parts wait on your approval',
            'urgentWaits' => '1 urgent part waits on your approval|:count urgent parts wait on your approval',
            'urgentHint' => 'Marked Urgent — approve or send back first.',
            'waitsHint' => 'Approve it, or send it back with why.',
            'noDecisions' => "You haven't approved or sent back anything yet.",
        ];

        return match ($role) {
            'GM Assistant' => [
                'role' => $role,
                'from' => 'Head of Business Development',
                'since' => 'head_of_bd_approved_at',
                'by' => 'gm_assistant_completed_by',
                'at' => 'gm_assistant_completed_at',
                'rfqBy' => 'gm_assistant_completed_by',
                'rfqAt' => 'gm_assistant_completed_at',
                'waiting' => fn ($rfqs) => $rfqs->awaitingGmAssistant()->notHeldOnAReturnsPage(),
                'awaits' => fn (Rfq $rfq, int $part) => $rfq->partAwaitsGmAssistant($part),
                'returns' => fn () => Rfq::query()->returnedToGmAssistant()->with('rejectedBy')->latest('rejected_at')->get(),
                'returnsUrl' => route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']),
                // Their step has a target (Settings → GM Assistant Time).
                'countdown' => fn (Rfq $rfq, int $part) => $rfq->gmAssistantCountdown($part),
                'countdownStep' => 'gm_assistant',
                'anchor' => 'gma',
                'words' => [
                    'awaiting' => 'Awaiting your submit',
                    'done' => 'Submitted',
                    'doneToday' => 'Submitted today',
                    'average' => 'Avg. time to submit',
                    'waitingTitle' => 'Waiting for you to submit',
                    'queueLink' => 'Your queue',
                    'fullyDone' => '1 RFQ fully submitted|:count RFQs fully submitted',
                    'waits' => '1 part waits on your submit|:count parts wait on your submit',
                    'urgentWaits' => '1 urgent part waits on your submit|:count urgent parts wait on your submit',
                    'urgentHint' => 'Marked Urgent — submit it or send it back first.',
                    'waitsHint' => 'Add your details and submit it to the General Manager, or send it back with why.',
                    'noDecisions' => "You haven't submitted or sent back anything yet.",
                ],
            ],
            'Head of Business Development' => [
                'role' => $role,
                'from' => 'Senior Operations',
                'since' => 'senior_ops_reviewed_at',
                'by' => 'head_of_bd_approved_by',
                'at' => 'head_of_bd_approved_at',
                'rfqBy' => 'head_of_bd_approved_by',
                'rfqAt' => 'head_of_bd_approved_at',
                'waiting' => fn ($rfqs) => $rfqs->awaitingHeadOfBdReview()->notHeldOnAReturnsPage(),
                'awaits' => fn (Rfq $rfq, int $part) => $rfq->partAwaitsHeadOfBdReview($part),
                'returns' => fn () => Rfq::query()->returnedToHeadOfBd()->with('rejectedBy')->latest('rejected_at')->get(),
                'returnsUrl' => route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']),
                'countdown' => null,
                'countdownStep' => null,
                'anchor' => 'hbd',
                'words' => $approving,
            ],
            default => [
                'role' => 'General Manager',
                'from' => 'GM Assistant',
                'since' => 'gm_assistant_completed_at',
                'by' => 'gm_approved_by',
                'at' => 'gm_approved_at',
                'rfqBy' => 'gm_approved_by',
                'rfqAt' => 'gm_approved_at',
                'waiting' => fn ($rfqs) => $rfqs->awaitingGmApproval(),
                'awaits' => fn (Rfq $rfq, int $part) => $rfq->partAwaitsGmApproval($part),
                'returns' => null,
                'returnsUrl' => null,
                'countdown' => null,
                'countdownStep' => null,
                'anchor' => 'gm',
                'words' => $approving,
            ],
        };
    }

    /**
     * What an approver's dashboard shows — the General Manager's or the Head
     * of Business Development's ($stage, approverStage()):
     *
     *  - now: every part waiting on their approval, Urgent first then
     *    longest-waiting; for the Head, what the General Manager sent back to
     *    them; and the business as it stands — the open RFQs by priority, and
     *    how many wait at each stage (pipeline(), the sidebar's counts);
     *  - over $period (summaryPeriod()): the parts they approved and the
     *    RFQs that left them fully approved, how long an approval took them
     *    — working time from when it reached them, in their hours
     *    (Setting::workingSecondsBetween()) — the send-backs they made (the
     *    reject's own comment, as Business Development's dashboard counts
     *    them) and to where, both span by span for the decisions chart; and
     *    the RFQs Business Development closed;
     *  - and their latest decisions, whenever.
     *
     * @param  array{start: CarbonImmutable, end: CarbonImmutable, from: string, to: string, isToday: bool, dates: string}  $period
     * @param  array<string, mixed>  $stage  approverStage()
     * @return array<string, mixed>
     */
    protected function approverOverview(User $user, array $period, array $stage): array
    {
        $window = [$period['start'], $period['end']];
        $priorities = array_reverse(Rfq::PRIORITIES);

        $waiting = Rfq::query()
            ->where('rfqs.status', 'Pending')
            ->tap($stage['waiting'])
            ->with('assignees')
            ->when($stage['countdownStep'], fn ($rfqs, string $step) => $rfqs->with(['steps' => fn ($steps) => $steps->where('step', $step)]))
            ->get()
            ->flatMap(fn (Rfq $rfq) => $rfq->assignees
                ->filter(fn (User $holder) => ($stage['awaits'])($rfq, $holder->pivot->part_number))
                ->map(fn (User $holder) => [
                    'rfq' => $rfq,
                    'label' => $rfq->isSplit() ? $rfq->partNumberLabel($holder->pivot->part_number) : $rfq->rfq_number,
                    'since' => $holder->pivot->{$stage['since']},
                    'countdown' => $stage['countdown'] !== null ? ($stage['countdown'])($rfq, $holder->pivot->part_number) : null,
                ]))
            ->sortBy(fn (array $part) => [array_search($part['rfq']->priority_level, $priorities, true), $part['since']?->getTimestamp() ?? 0])
            ->values();

        $approved = DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where("rfq_user.{$stage['by']}", $user->id)
            ->whereBetween("rfq_user.{$stage['at']}", $window)
            ->get(["rfq_user.{$stage['at']} as decided_at", "rfq_user.{$stage['since']} as reached_at"])
            ->map(fn (object $part) => [
                'at' => CarbonImmutable::parse($part->decided_at),
                'seconds' => $part->reached_at === null ? null
                    : Setting::workingSecondsBetween(CarbonImmutable::parse($part->reached_at), CarbonImmutable::parse($part->decided_at), $user->id),
            ]);
        $timed = $approved->whereNotNull('seconds');

        $returns = RfqComment::query()
            ->where('user_id', $user->id)
            ->where('action', 'rejected')
            ->whereBetween('created_at', $window)
            ->get(['created_at', 'meta']);

        ['spans' => $spans, 'keyOf' => $keyOf] = $this->periodSpans($period);
        $approvedBySpan = $approved->countBy(fn (array $part) => $keyOf($part['at']));
        $returnedBySpan = $returns->countBy(fn (RfqComment $comment) => $keyOf($comment->created_at));

        $open = Rfq::query()->where('status', 'Pending');
        $byPriority = (clone $open)->selectRaw('priority_level, count(*) as total')->groupBy('priority_level')->pluck('total', 'priority_level');

        return [
            'waiting' => $waiting,
            'waitingUrgent' => $waiting->filter(fn (array $part) => $part['rfq']->priority_level === 'Urgent')->count(),
            'waitingOverdue' => $waiting->filter(fn (array $part) => $part['countdown'] !== null && $part['countdown']['remaining'] <= 0)->count(),
            'returnedToMe' => $stage['returns'] !== null ? ($stage['returns'])() : collect(),
            'approved' => $approved->count(),
            'approvedRfqs' => Rfq::query()->where($stage['rfqBy'], $user->id)->whereBetween($stage['rfqAt'], $window)->count(),
            'average' => $timed->isEmpty() ? null : intdiv((int) $timed->sum('seconds'), $timed->count()),
            'returned' => $returns->count(),
            'returnedTo' => $returns->countBy(fn (RfqComment $comment) => $comment->meta['stage'] ?? 'an earlier step')->sortDesc()->all(),
            'open' => (clone $open)->count(),
            'openUrgent' => (int) ($byPriority['Urgent'] ?? 0),
            'closed' => Rfq::query()->whereBetween('bd_closed_at', $window)->count(),
            'priorities' => collect($priorities)->mapWithKeys(fn (string $priority) => [$priority => (int) ($byPriority[$priority] ?? 0)])->all(),
            'pipeline' => $this->pipeline(Rfq::queueCounts()),
            'decisions' => [
                'labels' => array_column($spans, 'label'),
                'dates' => array_column($spans, 'title'),
                'series' => [
                    ['key' => 'approved', 'label' => $stage['words']['done'], 'values' => collect($spans)->map(fn (array $span) => (int) ($approvedBySpan[$span['key']] ?? 0))->all()],
                    ['key' => 'returned', 'label' => 'Sent back', 'values' => collect($spans)->map(fn (array $span) => (int) ($returnedBySpan[$span['key']] ?? 0))->all()],
                ],
            ],
            'latest' => $this->approverDecisions($user, $stage),
        ];
    }

    /**
     * An approver's latest decisions, whenever: each part they approved, and
     * each send-back they made with where to and why — latest first.
     *
     * @param  array<string, mixed>  $stage  approverStage()
     * @return Collection<int, array{rfq: Rfq, label: string, at: CarbonInterface, approved: bool, detail: ?string}>
     */
    protected function approverDecisions(User $user, array $stage, int $limit = 8): Collection
    {
        $approvals = DB::table('rfq_user')
            ->where($stage['by'], $user->id)
            ->whereNotNull($stage['at'])
            ->orderByDesc($stage['at'])
            ->limit($limit)
            ->get(['rfq_id', 'part_number', "{$stage['at']} as decided_at"]);
        $rfqs = Rfq::query()->whereIn('id', $approvals->pluck('rfq_id'))->get()->keyBy('id');

        $approved = $approvals
            ->filter(fn (object $part) => $rfqs->has($part->rfq_id))
            ->map(fn (object $part) => [
                'rfq' => $rfqs[$part->rfq_id],
                'label' => $rfqs[$part->rfq_id]->isSplit() ? $rfqs[$part->rfq_id]->partNumberLabel((int) $part->part_number) : $rfqs[$part->rfq_id]->rfq_number,
                'at' => Carbon::parse($part->decided_at),
                'approved' => true,
                'detail' => null,
            ]);

        $returned = RfqComment::query()
            ->where('user_id', $user->id)
            ->where('action', 'rejected')
            ->with('rfq')
            ->latest()
            ->limit($limit)
            ->get()
            ->filter(fn (RfqComment $comment) => $comment->rfq !== null)
            ->map(fn (RfqComment $comment) => [
                'rfq' => $comment->rfq,
                'label' => isset($comment->meta['label']) ? (string) $comment->meta['label'] : $comment->rfq->rfq_number,
                'at' => $comment->created_at,
                'approved' => false,
                'detail' => 'to '.($comment->meta['stage'] ?? 'an earlier step').(filled($comment->body) ? ' — '.Str::limit($comment->body, 80) : ''),
            ]);

        return $approved->concat($returned)->sortByDesc(fn (array $decision) => $decision['at']->getTimestamp())->take($limit)->values();
    }

    /**
     * An approver's alerts — about what waits on them, and (the Head's) what
     * the General Manager sent back to them.
     *
     * @param  array<string, mixed>  $overview  approverOverview()
     * @param  array<string, mixed>  $stage  approverStage()
     * @return array<int, array{type: string, icon: string, title: string, description: string, url: string}>
     */
    protected function approverNotifications(array $overview, array $stage): array
    {
        $queue = route('admin.rfqs.index', ['status' => 'Pending']);
        $notifications = [];

        if ($overview['returnedToMe']->isNotEmpty()) {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'bi-arrow-counterclockwise',
                'title' => trans_choice('1 RFQ sent back to you|:count RFQs sent back to you', $overview['returnedToMe']->count(), ['count' => $overview['returnedToMe']->count()]),
                'description' => 'By the General Manager — on your Returns page.',
                'url' => $stage['returnsUrl'],
            ];
        }

        if ($overview['waitingOverdue'] > 0) {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'bi-alarm',
                'title' => trans_choice('1 part is past your target|:count parts are past your target', $overview['waitingOverdue'], ['count' => $overview['waitingOverdue']]),
                'description' => 'Their working time has run past the target — see to them first.',
                'url' => $queue,
            ];
        }

        if ($overview['waitingUrgent'] > 0) {
            $notifications[] = [
                'type' => 'danger',
                'icon' => 'bi-exclamation-triangle-fill',
                'title' => trans_choice($stage['words']['urgentWaits'], $overview['waitingUrgent'], ['count' => $overview['waitingUrgent']]),
                'description' => $stage['words']['urgentHint'],
                'url' => $queue,
            ];
        }

        $longWaiting = $overview['waiting']->filter(fn (array $part) => $part['since'] !== null && $part['since']->lt(now()->subDay()))->count();

        if ($longWaiting > 0) {
            $notifications[] = [
                'type' => 'warning',
                'icon' => 'bi-hourglass-split',
                'title' => trans_choice('1 part has waited over a day|:count parts have waited over a day', $longWaiting, ['count' => $longWaiting]),
                'description' => "Since {$stage['from']} sent them on.",
                'url' => $queue,
            ];
        }

        if ($overview['waiting']->isNotEmpty()) {
            $notifications[] = [
                'type' => 'primary',
                'icon' => 'bi-patch-check',
                'title' => trans_choice($stage['words']['waits'], $overview['waiting']->count(), ['count' => $overview['waiting']->count()]),
                'description' => $stage['words']['waitsHint'],
                'url' => $queue,
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
        // Only about the RFQs they created, for whoever sees only their own.
        $createdBy = $user->ownRfqsOnlyId();
        $notifications = [];

        // Closing is Business Development's own step — lead with it.
        if ($user->hasRole('Business Development')) {
            $readyToClose = Rfq::bdClosingCount($createdBy);
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
            $urgentPending = Rfq::query()->ownedBy($createdBy)->where('status', 'Pending')->where('priority_level', 'Urgent')->count();
            if ($urgentPending > 0) {
                $notifications[] = [
                    'type' => 'danger',
                    'icon' => 'bi-exclamation-triangle-fill',
                    'title' => trans_choice('1 urgent RFQ needs attention|:count urgent RFQs need attention', $urgentPending, ['count' => $urgentPending]),
                    'description' => 'Marked Urgent and still pending.',
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }

            $awaitingOperations = Rfq::query()->ownedBy($createdBy)->where('status', 'Pending')->whereNull('operations_assigned_by')->count();
            if ($awaitingOperations > 0) {
                $notifications[] = [
                    'type' => 'warning',
                    'icon' => 'bi-diagram-2',
                    'title' => trans_choice('1 RFQ awaiting Operations|:count RFQs awaiting Operations', $awaitingOperations, ['count' => $awaitingOperations]),
                    'description' => "No one's routed these yet.",
                    'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                ];
            }

            $awaitingSourcing = Rfq::query()->ownedBy($createdBy)->where('status', 'Pending')->needingSourcing()->count();
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
     * chart never skips a period with no activity. Only those $createdBy
     * created, if given.
     *
     * @return array{
     *     range: int, labels: array<int, string>, pending: array<int, int>,
     *     completed: array<int, int>, pendingTotal: int, completedTotal: int
     * }
     */
    protected function rfqActivity(int $range, ?int $createdBy = null): array
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
            ->ownedBy($createdBy)
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
