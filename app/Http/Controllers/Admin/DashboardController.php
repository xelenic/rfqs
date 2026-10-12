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
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
     * work, where it stands against its target (Rfq::sourcingCountdown() /
     * dataEntryCountdown(), so absences and resumed rounds count as they do
     * there): overdue first, then nearest to it, then the longest-running.
     * Finalize and GM Assistant have no target.
     *
     * @return Collection<int, array{rfq: Rfq, label: string, task: string, role: ?string, worker: ?User, started_at: CarbonInterface, seconds: int, target: ?int, remaining: ?int, state: string}>
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
                    default => null,
                };

                return [
                    'rfq' => $rfq,
                    'label' => $step->part_number !== null && $rfq->isSplit() ? $rfq->partNumberLabel($step->part_number) : $rfq->rfq_number,
                    'task' => self::TASK_LABELS[$step->step],
                    'role' => $step->role(),
                    'worker' => $workers->get($step->workerId()),
                    'started_at' => $step->started_at,
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
                    'data_entry' => $stretch->rfq->dataEntryTargetSeconds(),
                    default => null,
                };
                $seconds = (int) $round->sum(fn (RfqStep $part) => $part->secondsExcludingAbsence($book, $now));

                return ['worker' => $stretch->workerId(), 'seconds' => $seconds, 'onTime' => $target === null ? null : $seconds <= $target];
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
     * @return array{unit: string, spans: array<int, array{key: string, label: string, title: string}>, keyOf: \Closure(CarbonInterface): string}
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
