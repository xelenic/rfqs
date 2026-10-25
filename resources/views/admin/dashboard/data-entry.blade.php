{{--
    A Data Entry person's dashboard — their own work, nothing company-wide
    (DashboardController::dataEntryOverview()): the headline figures; what
    they're on right now against its target — or idle, free, out of hours —
    with the hours they work today; the parts waiting for Data Entry to
    start, Urgent first, and the queue by priority; what they sent to
    finalize over the period, parts or entries span by span, and how much of
    it was within its priority's target, as charts with table twins
    (public/js/admin-dashboard.js); and the last parts they sent. Live: it
    follows changes as they happen (live.js), the charts redrawn after each.

    The period filter scopes what they sent and the charts; the queue and
    what they're on are always as of now.

    Expects: $period, $overview, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $timezone = \App\Models\Setting::timezone();
        $duration = fn (?int $seconds) => $seconds === null ? '—' : \App\Models\Setting::durationLabel($seconds);
        $queueUrl = route('admin.rfqs.index', ['status' => 'Pending']);
        $taskStates = [
            'overdue' => ['Overdue', 'badge-soft-danger', 'tone-danger'],
            'due_soon' => ['Due soon', 'badge-soft-warning', 'tone-warning'],
            'on_track' => ['On track', 'badge-soft-success', 'tone-success'],
            'running' => ['In progress', 'badge-soft-info', 'tone-info'],
        ];
        $waitingTotal = $overview['waiting']->count();
        // Urgent: P1 Critical and P2 Urgent (Rfq::URGENT_PRIORITIES).
        $urgentWaiting = (int) collect(\App\Models\Rfq::URGENT_PRIORITIES)->sum(fn (string $priority) => $overview['waitingByPriority'][$priority] ?? 0);
        $onTimeTone = fn (?int $percent) => match (true) {
            $percent === null => '',
            $percent >= 80 => 'success',
            $percent >= 50 => 'warning',
            default => 'danger',
        };
        $sentLabel = $period['isToday'] ? 'Sent today' : 'Sent to finalize';
        $kpis = [
            ['label' => 'Waiting to start', 'value' => $waitingTotal, 'caption' => $urgentWaiting > 0 ? $urgentWaiting.' urgent' : 'For anyone in Data Entry', 'icon' => 'bi-inbox', 'tone' => 'warning', 'alert' => $urgentWaiting > 0, 'url' => $queueUrl],
            ['label' => $sentLabel, 'value' => $overview['sent'], 'caption' => $period['isToday'] ? 'Parts finished today' : 'Parts in the period', 'icon' => 'bi-send-check', 'tone' => 'success', 'alert' => false, 'url' => '#data-entry-output'],
            ['label' => 'Line items added', 'value' => number_format($overview['entries']), 'caption' => $overview['withEntries'] > 0 ? 'About '.number_format($overview['entries'] / $overview['withEntries'], 1).' a part' : 'Given as you send each part', 'icon' => 'bi-keyboard', 'tone' => 'violet', 'alert' => false, 'url' => '#data-entry-output'],
            ['label' => 'Within target', 'value' => $overview['onTime'] !== null ? $overview['onTime'].'%' : '—', 'caption' => $overview['timedCount'] > 0 ? $overview['onTimeCount'].' of '.$overview['timedCount'].' parts' : 'Nothing timed yet', 'icon' => 'bi-bullseye', 'tone' => $onTimeTone($overview['onTime']), 'alert' => $overview['onTime'] !== null && $overview['onTime'] < 50, 'url' => '#data-entry-targets'],
            ['label' => 'Avg. time a part', 'value' => $duration($overview['average']), 'caption' => 'Working time, from your Start', 'icon' => 'bi-stopwatch', 'tone' => 'teal', 'alert' => false, 'url' => '#data-entry-targets'],
            ['label' => 'Returned to Sourcing', 'value' => $overview['returned'], 'caption' => 'Sent back for rework', 'icon' => 'bi-arrow-counterclockwise', 'tone' => 'danger', 'alert' => false, 'url' => '#data-entry-latest'],
        ];
    @endphp

    @include('admin.dashboard._header', ['subtitle' => "Your Data Entry work — what's waiting, what you're on, and what you've sent."])

    @include('admin.dashboard._period_filter', ['period' => $period, 'note' => "what you've sent and the charts; the queue and what you're on are as of now"])

    {{-- The headline figures, each leading to where it's from. --}}
    <div class="row g-3 mb-4">
        @foreach ($kpis as $kpi)
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
        <div class="col-xxl-8">
            {{-- Right now: the part they're on against its target — or idle, out of hours, free. --}}
            @php
                $current = $overview['current'];
                $idleness = $overview['idleness'];
                $hours = $overview['hoursToday'];
            @endphp
            <div class="card mb-4" id="data-entry-now">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Right now</span>
                    <span class="text-muted-soft small fw-normal">
                        <i class="bi bi-clock"></i>
                        @if ($hours['working'])
                            Your hours today: {{ $hours['start'] }}–{{ $hours['end'] }}@if ($hours['lunch_start'] && $hours['lunch_end']) · lunch {{ $hours['lunch_start'] }}–{{ $hours['lunch_end'] }}@endif
                        @else
                            Today's a day off for you
                        @endif
                    </span>
                </div>
                <div class="card-body">
                    @if ($current)
                        @php [$stateLabel, $stateBadge, $stateTone] = $taskStates[$current['state']]; @endphp
                        <div class="de-now">
                            <span class="de-now-icon tone-success"><i class="bi bi-play-circle-fill"></i></span>
                            <div class="de-now-main">
                                <div class="d-flex align-items-center flex-wrap gap-2">
                                    <a href="{{ $current['rfq']->detailsUrl($current['part'] ?? null, 'Pending') }}" class="fw-semibold">{{ $current['label'] }}</a>
                                    <span class="badge {{ $current['rfq']->priorityBadgeClass() }}">{{ $current['rfq']->priorityLabel() }}</span>
                                    <span class="badge {{ $stateBadge }}">{{ $stateLabel }}</span>
                                </div>
                                <div class="small text-muted-soft text-truncate">{{ $current['rfq']->subject }} · started {{ $current['started_at']->copy()->setTimezone($timezone)->format('M d, Y g:i A') }}</div>
                                @if ($current['target'])
                                    <div class="task-progress {{ $stateTone }} mt-2">
                                        <span class="task-progress-track"><span class="task-progress-bar" style="width: {{ min(100, round($current['seconds'] / max(1, $current['target']) * 100, 1)) }}%"></span></span>
                                        <span class="task-progress-text">
                                            @if ($current['remaining'] < 0)
                                                {{ $duration(-$current['remaining']) }} over {{ $duration($current['target']) }}
                                            @else
                                                {{ $duration($current['seconds']) }} of {{ $duration($current['target']) }} · {{ $duration($current['remaining']) }} left
                                            @endif
                                        </span>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @elseif ($overview['offHours'])
                        <div class="de-now">
                            <span class="de-now-icon tone-info"><i class="bi bi-moon-stars"></i></span>
                            <div class="de-now-main">
                                <div class="fw-semibold">Outside your working hours</div>
                                <div class="small text-muted-soft">{{ $overview['offHours'] }}</div>
                            </div>
                        </div>
                    @elseif ($idleness)
                        <div class="de-now">
                            <span class="de-now-icon {{ $idleness['alerted_at'] ? 'tone-danger' : 'tone-warning' }}"><i class="bi bi-hourglass-bottom"></i></span>
                            <div class="de-now-main">
                                <div class="fw-semibold">Idle for {{ $duration($idleness['idle_seconds']) }}</div>
                                <div class="small text-muted-soft">
                                    @if ($idleness['alerted_at'])
                                        Senior Operations was told at {{ $idleness['alerted_at']->copy()->setTimezone($timezone)->format('g:i A') }}. Start a part to clear it.
                                    @else
                                        {{ trans_choice('1 part is|:count parts are', $idleness['waiting'], ['count' => $idleness['waiting']]) }} waiting. Senior Operations is told after {{ $overview['alert']['minutes'] }} minutes idle.
                                    @endif
                                </div>
                            </div>
                            <a href="{{ $queueUrl }}" class="btn btn-sm btn-primary ms-auto">Start one</a>
                        </div>
                    @elseif ($waitingTotal > 0)
                        <div class="de-now">
                            <span class="de-now-icon tone-primary"><i class="bi bi-inbox"></i></span>
                            <div class="de-now-main">
                                <div class="fw-semibold">Ready when you are</div>
                                <div class="small text-muted-soft">{{ trans_choice('1 part is|:count parts are', $waitingTotal, ['count' => $waitingTotal]) }} waiting to be started.</div>
                            </div>
                            <a href="{{ $queueUrl }}" class="btn btn-sm btn-primary ms-auto">Start one</a>
                        </div>
                    @else
                        <div class="de-now">
                            <span class="de-now-icon tone-success"><i class="bi bi-check2-circle"></i></span>
                            <div class="de-now-main">
                                <div class="fw-semibold">All clear</div>
                                <div class="small text-muted-soft">Nothing's waiting for Data Entry right now.</div>
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- The parts waiting to be started, Urgent first, then longest-waiting. --}}
            <div class="card mb-4" id="data-entry-waiting">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Waiting to start <span class="badge badge-soft-primary ms-1">{{ $waitingTotal }}</span></span>
                    <a href="{{ $queueUrl }}" class="small">Ready for Data Entry <i class="bi bi-arrow-right"></i></a>
                </div>
                @if ($overview['waiting']->isEmpty())
                    <div class="card-body">
                        <p class="text-muted-soft small mb-0">Nothing's waiting — every part is started or sent on.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Part</th>
                                    <th>Subject</th>
                                    <th>Priority</th>
                                    <th>Waiting since</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['waiting']->take(8) as $part)
                                    <tr>
                                        <td class="text-nowrap">
                                            <a href="{{ $part['rfq']->detailsUrl($part['part'] ?? null, 'Pending') }}" class="fw-semibold">{{ $part['label'] }}</a>
                                            @if ($part['forMe'])
                                                <span class="badge badge-soft-primary ms-1">For you</span>
                                            @endif
                                            @if ($part['sentBack'])
                                                <span class="badge badge-soft-danger ms-1">Sent back</span>
                                            @endif
                                        </td>
                                        <td class="text-truncate" style="max-width: 280px;">{{ $part['rfq']->subject }}</td>
                                        <td><span class="badge {{ $part['rfq']->priorityBadgeClass() }}">{{ $part['rfq']->priorityLabel() }}</span></td>
                                        <td class="text-nowrap text-muted-soft">{{ $part['since']?->copy()->setTimezone($timezone)->format('M d, Y g:i A') ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($waitingTotal > 8)
                        <div class="card-footer bg-transparent small">
                            <a href="{{ $queueUrl }}">And {{ $waitingTotal - 8 }} more on Ready for Data Entry</a>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        <div class="col-xxl-4">
            {{-- The queue by priority. --}}
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Queue by priority</span>
                    <span class="text-muted-soft small fw-normal">{{ $waitingTotal }} waiting</span>
                </div>
                <div class="card-body">
                    @if ($waitingTotal === 0)
                        <p class="text-muted-soft small mb-0">Nothing waiting.</p>
                    @else
                        <div class="priority-meter" role="img" aria-label="{{ collect($overview['waitingByPriority'])->map(fn ($count, $priority) => "{$count} {$priority}")->join(', ') }}">
                            @foreach ($overview['waitingByPriority'] as $priority => $count)
                                @if ($count > 0)
                                    <span class="priority-meter-part priority-chip-{{ strtolower($priority) }}" style="flex-grow: {{ $count }}" title="{{ $count }} {{ $priority }}"></span>
                                @endif
                            @endforeach
                        </div>
                        <ul class="priority-legend list-unstyled mb-0">
                            @foreach ($overview['waitingByPriority'] as $priority => $count)
                                <li class="priority-chip-{{ strtolower($priority) }}">
                                    <span class="priority-chip-dot"></span>
                                    <span class="flex-grow-1">{{ \App\Models\Rfq::priorityLabelOf($priority) }}</span>
                                    <span class="fw-semibold">{{ $count }}</span>
                                    <span class="text-muted-soft small">{{ round($count / $waitingTotal * 100) }}%</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @include('admin.dashboard._notifications')
        </div>

        {{-- What they sent, span by span — parts or entries, one at a time. --}}
        <div class="col-xl-7">
            <div class="card mb-4" id="data-entry-output">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Your output · {{ $period['dates'] }}</span>
                    <span class="d-flex align-items-center gap-2">
                        <span class="range-filter chart-metric" role="radiogroup" aria-label="Show">
                            <label class="range-filter-btn">
                                <input type="radio" name="output-metric" value="0" class="js-chart-metric" data-chart="outputChart" checked>
                                <span>Parts</span>
                            </label>
                            <label class="range-filter-btn">
                                <input type="radio" name="output-metric" value="1" class="js-chart-metric" data-chart="outputChart">
                                <span>Line items</span>
                            </label>
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-table" data-target="outputTable" data-chart-target="outputChart">
                            <i class="bi bi-table"></i> View as table
                        </button>
                    </span>
                </div>
                <div class="card-body">
                    <div id="outputChart" class="dash-chart" style="height: 280px"
                         data-dash-chart="{{ json_encode([
                             'type' => 'metric',
                             'empty' => 'You sent nothing to finalize in this period.',
                             'labels' => $overview['output']['labels'],
                             'dates' => $overview['output']['dates'],
                             'series' => [
                                 ['key' => 'parts', 'label' => 'Parts sent to finalize', 'values' => $overview['output']['parts']],
                                 ['key' => 'entries', 'label' => 'Line items added', 'values' => $overview['output']['entries']],
                             ],
                         ]) }}"></div>
                    <div id="outputTable" class="table-responsive d-none">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th class="text-end">Parts sent to finalize</th>
                                    <th class="text-end">Line items added</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['output']['dates'] as $i => $date)
                                    <tr>
                                        <td>{{ $date }}</td>
                                        <td class="text-end">{{ $overview['output']['parts'][$i] }}</td>
                                        <td class="text-end">{{ $overview['output']['entries'][$i] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- How much of it was within its priority's target. --}}
        <div class="col-xl-5">
            @php
                $byPriority = collect($overview['byPriority']);
            @endphp
            <div class="card mb-4" id="data-entry-targets">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Within target, by priority</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-table" data-target="targetsTable" data-chart-target="targetsChart">
                        <i class="bi bi-table"></i> View as table
                    </button>
                </div>
                <div class="card-body">
                    <div id="targetsChart" class="dash-chart" style="height: 230px"
                         data-dash-chart="{{ json_encode([
                             'type' => 'share',
                             'empty' => 'Nothing timed was sent in this period.',
                             'total' => $overview['timedCount'],
                             'labels' => $byPriority->pluck('priority')->map(fn (string $priority) => \App\Models\Rfq::priorityLabelOf($priority))->all(),
                             'series' => [
                                 ['key' => 'on-time', 'label' => 'Within target', 'values' => $byPriority->map(fn (array $row) => $row['timed'] > 0 ? (int) round($row['onTime'] / $row['timed'] * 100) : 0)->all()],
                             ],
                             'display' => $byPriority->map(fn (array $row) => $row['timed'] > 0 ? round($row['onTime'] / $row['timed'] * 100).'% · '.$row['onTime'].' of '.$row['timed'] : 'None sent')->all(),
                             'details' => $byPriority->map(fn (array $row) => 'Target '.$duration($row['target']).($row['average'] !== null ? ' · avg. '.$duration($row['average']) : ''))->all(),
                         ]) }}"></div>
                    <div id="targetsTable" class="table-responsive d-none">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>Priority</th>
                                    <th class="text-end">Sent</th>
                                    <th class="text-end">Avg. time</th>
                                    <th class="text-end">Target</th>
                                    <th class="text-end">Within target</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($byPriority as $row)
                                    <tr>
                                        <td>{{ \App\Models\Rfq::priorityLabelOf($row['priority']) }}</td>
                                        <td class="text-end">{{ $row['sent'] }}</td>
                                        <td class="text-end">{{ $duration($row['average']) }}</td>
                                        <td class="text-end">{{ $duration($row['target']) }}</td>
                                        <td class="text-end">{{ $row['timed'] > 0 ? $row['onTime'].' of '.$row['timed'] : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted-soft mt-2 mb-0">Working time from your Start to Send to Finalize, days you were off aside, against its Data Entry time — the line items to add times the time per item.</p>
                </div>
            </div>
        </div>

        {{-- The last parts they sent, whenever. --}}
        <div class="col-12">
            <div class="card mb-4" id="data-entry-latest">
                <div class="card-header">Your latest sent to finalize</div>
                @if ($overview['latest']->isEmpty())
                    <div class="card-body">
                        <p class="text-muted-soft small mb-0">You haven't sent a part to finalize yet.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Part</th>
                                    <th>Subject</th>
                                    <th>Priority</th>
                                    <th class="text-end">Line items added</th>
                                    <th class="text-end">Time taken</th>
                                    <th>Target</th>
                                    <th class="text-end">Sent</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['latest'] as $part)
                                    @php $sentAt = $part['at']->copy()->setTimezone($timezone); @endphp
                                    <tr>
                                        <td class="text-nowrap"><a href="{{ $part['rfq']->detailsUrl($part['part'] ?? null) }}" class="fw-semibold">{{ $part['label'] }}</a></td>
                                        <td class="text-truncate" style="max-width: 280px;">{{ $part['rfq']->subject }}</td>
                                        <td><span class="badge {{ $part['rfq']->priorityBadgeClass() }}">{{ \App\Models\Rfq::priorityLabelOf($part['priority']) }}</span></td>
                                        <td class="text-end team-figure">{{ $part['entries'] ?? '—' }}</td>
                                        <td class="text-end team-figure text-nowrap">{{ $duration($part['seconds']) }}</td>
                                        <td>
                                            @if ($part['onTime'] === true)
                                                <span class="badge badge-soft-success"><i class="bi bi-check2"></i> Within {{ $duration($part['target']) }}</span>
                                            @elseif ($part['onTime'] === false)
                                                <span class="badge badge-soft-danger"><i class="bi bi-alarm"></i> Over {{ $duration($part['target']) }}</span>
                                            @else
                                                <span class="text-muted-soft">—</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap text-muted-soft" title="{{ $sentAt->format('M d, Y g:i A') }}">{{ $sentAt->isToday() ? $sentAt->format('g:i A') : $sentAt->format('M j') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('js/rfq-chart.js') }}?v={{ filemtime(public_path('js/rfq-chart.js')) }}"></script>
    <script src="{{ asset('js/admin-dashboard.js') }}?v={{ filemtime(public_path('js/admin-dashboard.js')) }}"></script>
@endpush
