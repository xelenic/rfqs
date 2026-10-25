{{--
    Admin's dashboard — the whole operation at a glance, like a project
    board (DashboardController::adminOverview()): the headline figures; every
    task someone's on right now, against its target; what's waiting at each
    stage; how each person whose time is tracked is doing; where Data Entry
    stands, idle time and all; the tasks finished day by day and each
    person's workload, as charts with table twins (public/js/admin-dashboard.js);
    the RFQs created over time; and the latest steps taken. Live: it follows
    changes as they happen (live.js), the charts redrawn after each.

    One period filter, under the greeting, scopes what's been finished, the
    team's figures, the charts and the latest steps (Today, 7 / 30 / 90 days,
    or dates of your own — DashboardController::summaryPeriod()); what's
    open, waiting, in progress or idle is always as of now.

    Expects: $period, $overview, $rfqActivity, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $timezone = \App\Models\Setting::timezone();
        $taskStates = [
            'overdue' => ['Overdue', 'badge-soft-danger', 'tone-danger'],
            'due_soon' => ['Due soon', 'badge-soft-warning', 'tone-warning'],
            'on_track' => ['On track', 'badge-soft-success', 'tone-success'],
            'running' => ['In progress', 'badge-soft-info', 'tone-info'],
        ];
        $stateBadges = ['success' => 'badge-soft-success', 'warning' => 'badge-soft-warning', 'danger' => 'badge-soft-danger', 'secondary' => 'badge-soft-secondary', 'info' => 'badge-soft-info'];
        $openTotal = array_sum($overview['priorities']);
        $busiestStage = max(1, collect($overview['pipeline'])->max('count'));
    @endphp

    @include('admin.dashboard._header', ['subtitle' => "The whole operation at a glance — what's waiting, who's on what, and how the team is doing."])

    @include('admin.dashboard._period_filter', ['period' => $period, 'note' => "finished work, the team and the charts; what's open and in progress is as of now"])

    {{-- The headline figures, each leading to where it's from. --}}
    <div class="row g-3 mb-4">
        @foreach ($overview['kpis'] as $kpi)
            <div class="col-6 col-md-4 col-xxl-2">
                <a href="{{ $kpi['url'] }}" class="stat-card-link">
                    <div class="stat-card kpi-card">
                        <div class="kpi-card-head">
                            <span class="stat-label">{{ $kpi['label'] }}</span>
                            <span class="stat-icon {{ $kpi['tone'] ? 'stat-icon-'.$kpi['tone'] : '' }}"><i class="bi {{ $kpi['icon'] }}"></i></span>
                        </div>
                        <div class="stat-value">{{ $kpi['value'] }}</div>
                        <div class="stat-caption {{ $kpi['alert'] ? 'text-danger fw-semibold' : '' }}">{{ $kpi['caption'] }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        {{-- Today's ongoing tasks: every open stretch of tracked work, overdue first. --}}
        <div class="col-xxl-8">
            <div class="card mb-4" id="ongoing-tasks">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Today's ongoing tasks <span class="badge badge-soft-primary ms-1">{{ $overview['ongoing']->count() }}</span></span>
                    <span class="task-legend">
                        @foreach (['on_track', 'due_soon', 'overdue'] as $state)
                            <span class="{{ $taskStates[$state][2] }}"><i class="task-legend-dot"></i>{{ $taskStates[$state][0] }}</span>
                        @endforeach
                    </span>
                </div>
                @if ($overview['ongoing']->isEmpty())
                    <div class="card-body">
                        <p class="text-muted-soft small mb-0">Nobody's on a task right now.</p>
                    </div>
                @else
                    <div class="table-responsive dash-scroll">
                        <table class="table table-hover mb-0 task-sheet">
                            <thead>
                                <tr>
                                    <th>Task</th>
                                    <th>Assignee</th>
                                    <th>Priority</th>
                                    <th title="When the task last changed — reached its step, was resumed, or its part was updated">Last Updated</th>
                                    <th>Time vs target</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['ongoing'] as $task)
                                    @php [$stateLabel, $stateBadge, $stateTone] = $taskStates[$task['state']]; @endphp
                                    <tr>
                                        <td class="task-sheet-task">
                                            <a href="{{ $task['rfq']->detailsUrl($task['part'] ?? null, 'Pending') }}" class="fw-semibold">{{ $task['label'] }}</a>
                                            <div class="small text-muted-soft text-truncate">{{ $task['task'] }} · {{ $task['rfq']->subject }}</div>
                                        </td>
                                        <td>
                                            @if ($task['worker'])
                                                <a href="{{ route('admin.users.show', $task['worker']) }}" class="dash-person">
                                                    <x-avatar :user="$task['worker']" class="dash-avatar" />
                                                    <span class="text-truncate">{{ $task['worker']->name }}</span>
                                                </a>
                                            @else
                                                <span class="dash-person text-muted-soft">
                                                    <span class="dash-avatar is-team"><i class="bi bi-people"></i></span>
                                                    {{ $task['role'] }} team
                                                </span>
                                            @endif
                                        </td>
                                        <td><span class="badge {{ $task['rfq']->priorityBadgeClass() }}">{{ $task['rfq']->priorityLabel() }}</span></td>
                                        <td class="text-nowrap text-muted-soft">{{ $task['updated_at']->copy()->setTimezone($timezone)->format('M d, Y g:i A') }}</td>
                                        <td>
                                            @if ($task['target'])
                                                @include('admin.dashboard._time_vs_target', ['timing' => $task])
                                            @else
                                                <span class="task-progress-text">{{ \App\Models\Setting::durationLabel($task['seconds']) }} so far · no target</span>
                                            @endif
                                        </td>
                                        <td><span class="badge {{ $stateBadge }}">{{ $stateLabel }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- What's waiting at each stage. --}}
        <div class="col-xxl-4">
            <div class="card mb-4" id="pending-by-stage">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Pending by stage</span>
                    <span class="text-muted-soft small fw-normal">{{ collect($overview['pipeline'])->sum('count') }} waiting</span>
                </div>
                <div class="card-body">
                    <ul class="pipeline list-unstyled mb-0">
                        @foreach ($overview['pipeline'] as $stage)
                            <li class="pipeline-row tone-{{ $stage['tone'] }}">
                                <a href="{{ $stage['url'] }}" class="pipeline-label" title="{{ $stage['role'] }}">{{ $stage['label'] }}</a>
                                <span class="pipeline-track">
                                    <span class="pipeline-bar" style="width: {{ round($stage['count'] / $busiestStage * 100, 1) }}%"></span>
                                </span>
                                <span class="pipeline-count">{{ $stage['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>

        {{-- Each tracked person: what they're on, what's in hand, and the last week's output. --}}
        <div class="col-xxl-8">
            <div class="card mb-4" id="team-performance">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Team performance</span>
                    <span class="text-muted-soft small fw-normal">Sourcing, Data Entry and GM Assistant · {{ $period['dates'] }}</span>
                </div>
                @if ($overview['team']->isEmpty())
                    <div class="card-body">
                        <p class="text-muted-soft small mb-0">Nobody holds Sourcing, Data Entry or GM Assistant yet.</p>
                    </div>
                @else
                    <div class="table-responsive dash-scroll">
                        <table class="table table-hover mb-0 team-table">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th>Now</th>
                                    <th class="text-end">In hand</th>
                                    <th class="text-end">Finished</th>
                                    <th class="text-end">Avg. time</th>
                                    <th>On time</th>
                                    <th class="text-end">Overdue</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['team'] as $row)
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.users.show', $row['user']) }}" class="dash-person">
                                                <x-avatar :user="$row['user']" class="dash-avatar" />
                                                <span class="min-w-0">
                                                    <span class="d-block text-truncate fw-semibold">{{ $row['user']->name }}</span>
                                                    <span class="d-block small text-muted-soft">{{ $row['role'] }}</span>
                                                </span>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge {{ $stateBadges[$row['tone']] ?? 'badge-soft-secondary' }}">{{ $row['state'] }}</span>
                                            @if ($row['detail'])
                                                <div class="small text-muted-soft text-nowrap">{{ $row['detail'] }}</div>
                                            @endif
                                        </td>
                                        <td class="text-end team-figure">{{ $row['inHand'] ?? '—' }}</td>
                                        <td class="text-end team-figure fw-semibold">{{ $row['done'] }}</td>
                                        <td class="text-end team-figure text-nowrap">{{ $row['average'] !== null ? \App\Models\Setting::durationLabel($row['average']) : '—' }}</td>
                                        <td>
                                            @if ($row['onTime'] !== null)
                                                @php $onTimeTone = $row['onTime'] >= 80 ? 'tone-success' : ($row['onTime'] >= 50 ? 'tone-warning' : 'tone-danger'); @endphp
                                                <div class="task-progress {{ $onTimeTone }}">
                                                    <span class="task-progress-track"><span class="task-progress-bar" style="width: {{ $row['onTime'] }}%"></span></span>
                                                    <span class="task-progress-text">{{ $row['onTime'] }}%</span>
                                                </div>
                                            @else
                                                <span class="text-muted-soft">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end team-figure {{ $row['overdue'] > 0 ? 'text-danger fw-semibold' : 'text-muted-soft' }}">{{ $row['overdue'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="card-footer bg-transparent small text-muted-soft">
                        Finished: tasks they completed or sent on. Avg. time: working time per task, days off aside. On time: Sourcing and Data Entry work done within its priority's target.
                    </div>
                @endif
            </div>
        </div>

        {{-- Data Entry: who's on a part, who's idle with parts waiting; and the open RFQs by priority. --}}
        <div class="col-xxl-4">
            @php $floor = $overview['dataEntry']; @endphp
            <div class="card mb-4" id="data-entry-floor">
                <div class="card-header">Data Entry now</div>
                <div class="card-body">
                    <div class="floor-figures">
                        <div class="floor-figure {{ $floor['idleCount'] > 0 ? 'tone-danger' : '' }}">
                            <span class="floor-figure-value">{{ $floor['idleCount'] }}</span>
                            <span class="floor-figure-label">Idle now{{ $floor['idleSeconds'] > 0 ? ' · '.\App\Models\Setting::durationLabel($floor['idleSeconds']) : '' }}</span>
                        </div>
                        <div class="floor-figure">
                            <span class="floor-figure-value">{{ $floor['waiting'] }}</span>
                            <span class="floor-figure-label">Parts waiting</span>
                        </div>
                        <div class="floor-figure">
                            <span class="floor-figure-value">{{ $floor['alertedToday'] }}</span>
                            <span class="floor-figure-label">Alerted today</span>
                        </div>
                    </div>

                    @unless ($floor['alertOn'])
                        <p class="small text-muted-soft mt-3 mb-0"><i class="bi bi-info-circle"></i> The idle alert is off, so idle time isn't tracked — turn it on in <a href="{{ route('admin.settings.edit', ['tab' => 'idle-alert']) }}">Settings → Idle Alert</a>.</p>
                    @endunless

                    <ul class="list-unstyled floor-people mb-0">
                        @forelse ($floor['people'] as $person)
                            <li>
                                <a href="{{ route('admin.users.show', $person['user']) }}" class="dash-person">
                                    <x-avatar :user="$person['user']" class="dash-avatar" />
                                    <span class="text-truncate">{{ $person['user']->name }}</span>
                                </a>
                                <span class="text-end">
                                    <span class="badge {{ $stateBadges[$person['tone']] ?? 'badge-soft-secondary' }}">{{ $person['state'] }}</span>
                                    @if ($person['detail'])
                                        <span class="d-block small text-muted-soft">{{ $person['detail'] }}</span>
                                    @endif
                                </span>
                            </li>
                        @empty
                            <li class="text-muted-soft small">Nobody holds Data Entry yet.</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Open RFQs by priority</span>
                    <span class="text-muted-soft small fw-normal">{{ $openTotal }} open</span>
                </div>
                <div class="card-body">
                    @if ($openTotal === 0)
                        <p class="text-muted-soft small mb-0">No open RFQs.</p>
                    @else
                        <div class="priority-meter" role="img" aria-label="{{ collect($overview['priorities'])->map(fn ($count, $priority) => "{$count} {$priority}")->join(', ') }}">
                            @foreach ($overview['priorities'] as $priority => $count)
                                @if ($count > 0)
                                    <span class="priority-meter-part priority-chip-{{ strtolower($priority) }}" style="flex-grow: {{ $count }}" title="{{ $count }} {{ $priority }}"></span>
                                @endif
                            @endforeach
                        </div>
                        <ul class="priority-legend list-unstyled mb-0">
                            @foreach ($overview['priorities'] as $priority => $count)
                                <li class="priority-chip-{{ strtolower($priority) }}">
                                    <span class="priority-chip-dot"></span>
                                    <span class="flex-grow-1">{{ \App\Models\Rfq::priorityLabelOf($priority) }}</span>
                                    <span class="fw-semibold">{{ $count }}</span>
                                    <span class="text-muted-soft small">{{ round($count / $openTotal * 100) }}%</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- The charts: tasks finished day by day, by role; each person's workload. --}}
        <div class="col-xl-6">
            <div class="card mb-4" id="throughput">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Tasks finished · {{ $period['dates'] }}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-table" data-target="throughputTable" data-chart-target="throughputChart">
                        <i class="bi bi-table"></i> View as table
                    </button>
                </div>
                <div class="card-body">
                    <div id="throughputChart" class="dash-chart" style="height: 300px"
                         data-dash-chart="{{ json_encode(['type' => 'stacked', 'empty' => 'Nothing was finished in this period.'] + $overview['throughput']) }}"></div>
                    <div id="throughputTable" class="table-responsive d-none">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Day</th>
                                    @foreach ($overview['throughput']['series'] as $series)
                                        <th class="text-end">{{ $series['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['throughput']['dates'] as $i => $date)
                                    <tr>
                                        <td>{{ $date }}</td>
                                        @foreach ($overview['throughput']['series'] as $series)
                                            <td class="text-end">{{ $series['values'][$i] }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-6">
            <div class="card mb-4" id="workload">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Workload by person</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-table" data-target="workloadTable" data-chart-target="workloadChart">
                        <i class="bi bi-table"></i> View as table
                    </button>
                </div>
                <div class="card-body">
                    <div id="workloadChart" class="dash-chart" style="height: {{ 70 + max(1, count($overview['workload']['labels'])) * 44 }}px"
                         data-dash-chart="{{ json_encode([
                             'type' => 'people',
                             'empty' => 'Nobody holds Sourcing, Data Entry or GM Assistant yet.',
                             'labels' => $overview['workload']['labels'],
                             'series' => [
                                 ['key' => 'in-hand', 'label' => 'In hand now', 'values' => $overview['workload']['inHand']],
                                 ['key' => 'done', 'label' => 'Finished in the period', 'values' => $overview['workload']['done']],
                             ],
                         ]) }}"></div>
                    <div id="workloadTable" class="table-responsive d-none">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th class="text-end">In hand now</th>
                                    <th class="text-end">Finished in the period</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['workload']['labels'] as $i => $name)
                                    <tr>
                                        <td>{{ $name }}</td>
                                        <td class="text-end">{{ $overview['workload']['inHand'][$i] }}</td>
                                        <td class="text-end">{{ $overview['workload']['done'][$i] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xxl-8">
            @include('admin.dashboard._activity', ['showTotals' => false, 'completedLabel' => 'Closed', 'showRange' => false])
        </div>

        <div class="col-xxl-4">
            <div class="card mb-4">
                <div class="card-header">Latest steps taken</div>
                <div class="card-body">
                    @forelse ($overview['activity'] as $comment)
                        @php $commentAt = $comment->created_at->copy()->setTimezone($timezone); @endphp
                        <a href="{{ route('admin.rfqs.show', $comment->rfq) }}" class="today-feed-item tone-{{ $comment->actionTone() }}">
                            <span class="today-feed-icon"><i class="bi {{ $comment->actionIcon() }}"></i></span>
                            <span class="today-feed-text">
                                {{ $comment->actionLabel() }} <strong>{{ $comment->rfq->rfq_number }}</strong>
                                @if ($comment->author)
                                    <span class="text-muted-soft">· {{ $comment->author->name }}</span>
                                @endif
                            </span>
                            <span class="today-feed-time" title="{{ $commentAt->format('M d, Y g:i A') }}">{{ $commentAt->isToday() ? $commentAt->format('g:i A') : $commentAt->format('M j') }}</span>
                        </a>
                    @empty
                        <p class="text-muted-soft small mb-0">No steps taken yet.</p>
                    @endforelse
                </div>
            </div>

            @include('admin.dashboard._notifications')
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('js/rfq-chart.js') }}?v={{ filemtime(public_path('js/rfq-chart.js')) }}"></script>
    <script src="{{ asset('js/admin-dashboard.js') }}?v={{ filemtime(public_path('js/admin-dashboard.js')) }}"></script>
@endpush
