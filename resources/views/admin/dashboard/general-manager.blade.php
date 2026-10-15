{{--
    The General Manager's dashboard — their approvals and the business at a
    glance, nothing about people, roles or permissions
    (DashboardController::generalManagerOverview()): the headline figures;
    every part waiting on their approval, Urgent first; the open RFQs by
    priority and how many wait at each stage; their decisions over the
    period — approved and sent back, as a chart with its table twin
    (public/js/admin-dashboard.js); the RFQs created and closed over it
    (_activity); and their latest decisions. Live: it follows changes as they
    happen (live.js), the charts redrawn after each.

    The period filter scopes their decisions, the closed count and the
    charts; what waits and what's open is always as of now.

    Expects: $period, $overview, $rfqActivity, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $timezone = \App\Models\Setting::timezone();
        $queueUrl = route('admin.rfqs.index', ['status' => 'Pending']);
        $waitingTotal = $overview['waiting']->count();
        $openTotal = array_sum($overview['priorities']);
        $busiestStage = max(1, collect($overview['pipeline'])->max('count'));
        $decisions = $overview['decisions'];
        $kpis = [
            ['label' => 'Awaiting your approval', 'value' => $waitingTotal, 'caption' => $overview['waitingUrgent'] > 0 ? $overview['waitingUrgent'].' urgent' : 'Submitted by GM Assistant', 'icon' => 'bi-patch-question', 'tone' => 'warning', 'alert' => $overview['waitingUrgent'] > 0, 'url' => '#gm-waiting'],
            ['label' => $period['isToday'] ? 'Approved today' : 'Approved', 'value' => $overview['approved'], 'caption' => trans_choice('1 RFQ fully approved|:count RFQs fully approved', $overview['approvedRfqs'], ['count' => $overview['approvedRfqs']]), 'icon' => 'bi-patch-check', 'tone' => 'success', 'alert' => false, 'url' => '#gm-decisions'],
            ['label' => 'Sent back', 'value' => $overview['returned'], 'caption' => $overview['returned'] > 0 ? 'Most to '.array_key_first($overview['returnedTo']) : 'Nothing sent back', 'icon' => 'bi-arrow-counterclockwise', 'tone' => 'danger', 'alert' => false, 'url' => '#gm-decisions'],
            ['label' => 'Avg. time to approve', 'value' => $overview['average'] !== null ? \App\Models\Setting::durationLabel($overview['average']) : '—', 'caption' => 'Working time from GM Assistant', 'icon' => 'bi-stopwatch', 'tone' => 'teal', 'alert' => false, 'url' => '#gm-decisions'],
            ['label' => 'Open RFQs', 'value' => $overview['open'], 'caption' => $overview['openUrgent'].' urgent', 'icon' => 'bi-folder2-open', 'tone' => '', 'alert' => $overview['openUrgent'] > 0, 'url' => '#gm-pipeline'],
            ['label' => $period['isToday'] ? 'Closed today' : 'Closed', 'value' => $overview['closed'], 'caption' => 'By Business Development', 'icon' => 'bi-flag-fill', 'tone' => 'violet', 'alert' => false, 'url' => '#gm-activity'],
        ];
    @endphp

    @include('admin.dashboard._header', ['subtitle' => "Your approvals, and where the business stands."])

    @include('admin.dashboard._period_filter', ['period' => $period, 'note' => "your decisions, what's closed and the charts; what waits on you and what's open are as of now"])

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
        {{-- Every part waiting on their approval, Urgent first, then longest-waiting. --}}
        <div class="col-xxl-8">
            <div class="card mb-4" id="gm-waiting">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Waiting for your approval <span class="badge badge-soft-primary ms-1">{{ $waitingTotal }}</span></span>
                    <a href="{{ $queueUrl }}" class="small">Your approvals <i class="bi bi-arrow-right"></i></a>
                </div>
                @if ($overview['waiting']->isEmpty())
                    <div class="card-body">
                        <p class="text-muted-soft small mb-0"><i class="bi bi-check2-circle"></i> Nothing waits on you — every part GM Assistant submitted has been decided.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Part</th>
                                    <th>Subject</th>
                                    <th>WC Number</th>
                                    <th>Priority</th>
                                    <th>Waiting</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($overview['waiting']->take(8) as $part)
                                    <tr>
                                        <td class="text-nowrap"><a href="{{ route('admin.rfqs.show', $part['rfq']) }}?status=Pending" class="fw-semibold">{{ $part['label'] }}</a></td>
                                        <td class="text-truncate" style="max-width: 280px;">{{ $part['rfq']->subject }}</td>
                                        <td class="text-nowrap">{{ $part['rfq']->wc_number ?? '—' }}</td>
                                        <td><span class="badge {{ $part['rfq']->priorityBadgeClass() }}">{{ $part['rfq']->priority_level }}</span></td>
                                        <td class="text-nowrap text-muted-soft" @if ($part['since']) title="Submitted {{ $part['since']->toBusinessTimezone()->format('M d, Y g:i A') }}" @endif>{{ $part['since']?->diffForHumans(null, true) ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($waitingTotal > 8)
                        <div class="card-footer bg-transparent small">
                            <a href="{{ $queueUrl }}">And {{ $waitingTotal - 8 }} more on your approvals</a>
                        </div>
                    @endif
                @endif
            </div>
        </div>

        <div class="col-xxl-4">
            {{-- The open RFQs by priority. --}}
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
                                    <span class="flex-grow-1">{{ $priority }}</span>
                                    <span class="fw-semibold">{{ $count }}</span>
                                    <span class="text-muted-soft small">{{ round($count / $openTotal * 100) }}%</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @include('admin.dashboard._notifications')
        </div>

        {{-- Their decisions, span by span: approved and sent back. --}}
        <div class="col-xl-7">
            <div class="card mb-4" id="gm-decisions">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Your decisions · {{ $period['dates'] }}</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-table" data-target="decisionsTable" data-chart-target="decisionsChart">
                        <i class="bi bi-table"></i> View as table
                    </button>
                </div>
                <div class="card-body">
                    <div id="decisionsChart" class="dash-chart" style="height: 280px"
                         data-dash-chart="{{ json_encode(['type' => 'stacked', 'empty' => 'You made no decisions in this period.'] + $decisions) }}"></div>
                    <div id="decisionsTable" class="table-responsive d-none">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr>
                                    <th>When</th>
                                    @foreach ($decisions['series'] as $series)
                                        <th class="text-end">{{ $series['label'] }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($decisions['dates'] as $i => $date)
                                    <tr>
                                        <td>{{ $date }}</td>
                                        @foreach ($decisions['series'] as $series)
                                            <td class="text-end">{{ $series['values'][$i] }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if ($overview['returnedTo'] !== [])
                        <p class="small text-muted-soft mt-2 mb-0">Sent back to: {{ collect($overview['returnedTo'])->map(fn (int $count, string $stage) => "{$stage} {$count}")->join(' · ') }}</p>
                    @endif
                </div>
            </div>
        </div>

        {{-- How many open RFQs wait at each stage — the sidebar's counts. --}}
        <div class="col-xl-5">
            <div class="card mb-4" id="gm-pipeline">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Where open RFQs are</span>
                    <span class="text-muted-soft small fw-normal">{{ collect($overview['pipeline'])->sum('count') }} waiting</span>
                </div>
                <div class="card-body">
                    <ul class="pipeline list-unstyled mb-0">
                        @foreach ($overview['pipeline'] as $stage)
                            <li class="pipeline-row tone-{{ $stage['tone'] }}">
                                <span class="pipeline-label" title="{{ $stage['role'] }}">{{ $stage['label'] }}</span>
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

        {{-- The RFQs created over the period, still open and closed. --}}
        <div class="col-xxl-8" id="gm-activity">
            @include('admin.dashboard._activity', ['showTotals' => false, 'completedLabel' => 'Closed', 'showRange' => false])
        </div>

        {{-- Their latest decisions, whenever. --}}
        <div class="col-xxl-4">
            <div class="card mb-4">
                <div class="card-header">Your latest decisions</div>
                <div class="card-body">
                    @forelse ($overview['latest'] as $decision)
                        @php $decidedAt = \Illuminate\Support\Carbon::instance($decision['at'])->toBusinessTimezone(); @endphp
                        <a href="{{ route('admin.rfqs.show', $decision['rfq']) }}" class="today-feed-item tone-{{ $decision['approved'] ? 'success' : 'danger' }}">
                            <span class="today-feed-icon"><i class="bi {{ $decision['approved'] ? 'bi-patch-check-fill' : 'bi-arrow-counterclockwise' }}"></i></span>
                            <span class="today-feed-text">
                                {{ $decision['approved'] ? 'Approved' : 'Sent back' }} <strong>{{ $decision['label'] }}</strong>
                                @if ($decision['detail'])
                                    <span class="text-muted-soft">· {{ $decision['detail'] }}</span>
                                @endif
                            </span>
                            <span class="today-feed-time" title="{{ $decidedAt->format('M d, Y g:i A') }}">{{ $decidedAt->isToday() ? $decidedAt->format('g:i A') : $decidedAt->format('M j') }}</span>
                        </a>
                    @empty
                        <p class="text-muted-soft small mb-0">You haven't approved or sent back anything yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('js/rfq-chart.js') }}?v={{ filemtime(public_path('js/rfq-chart.js')) }}"></script>
    <script src="{{ asset('js/admin-dashboard.js') }}?v={{ filemtime(public_path('js/admin-dashboard.js')) }}"></script>
@endpush
