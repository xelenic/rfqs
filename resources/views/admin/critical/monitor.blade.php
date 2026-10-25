{{--
    The Critical monitor's content, fetched into layouts/_critical_modal by
    public/js/critical-monitor.js (DashboardController::critical()): the
    headline counts (counted up as they appear), three charts — the share of
    timed tasks on time, the most overdue, Data Entry's idle time against
    the alert — and the lists behind them: overdue tasks, Critical, Urgent and High
    RFQs still open, what's been sent back, and the viewer's unread alerts
    and messages. data-critical-count carries the count for the top bar's
    button.
--}}
@php
    $timezone = \App\Models\Setting::timezone();
    $duration = fn (int $seconds) => \App\Models\Setting::durationLabel($seconds);
    $returnsTotal = $returns->sum('count');
    $mostOverdue = $overdue->take(8);
    $tiles = [
        ['label' => 'Overdue tasks', 'value' => $overdue->count(), 'tone' => 'danger', 'icon' => 'bi-alarm',
            'caption' => $overdue->isNotEmpty() ? 'Longest '.$duration(-$overdue->first()['remaining']).' over' : 'None past target'],
        ['label' => 'Due soon', 'value' => $dueSoon, 'tone' => 'warning', 'icon' => 'bi-hourglass-split',
            'caption' => 'Under a quarter of their time left'],
        ['label' => 'P1–P3 open', 'value' => array_sum($urgentCounts), 'tone' => $urgentCounts['Critical'] + $urgentCounts['Urgent'] > 0 ? 'danger' : 'warning', 'icon' => 'bi-exclamation-triangle',
            'caption' => $urgentCounts['Critical'].' critical · '.$urgentCounts['Urgent'].' urgent · '.$urgentCounts['High'].' high'],
        ['label' => 'Data Entry idle', 'value' => $idle->count(), 'tone' => 'warning', 'icon' => 'bi-hourglass-bottom',
            'caption' => $floor['idleSeconds'] > 0 ? $duration($floor['idleSeconds']).' idle · '.$floor['waiting'].' waiting' : $floor['waiting'].' parts waiting'],
        ['label' => 'Sent back, waiting', 'value' => $returnsTotal, 'tone' => 'violet', 'icon' => 'bi-arrow-counterclockwise',
            'caption' => 'On the Returns pages'],
        ['label' => 'Your unread', 'value' => $unreadAlerts + $unreadMessages, 'tone' => 'primary', 'icon' => 'bi-bell',
            'caption' => trans_choice('1 alert|:count alerts', $unreadAlerts, ['count' => $unreadAlerts]).' · '.trans_choice('1 message|:count messages', $unreadMessages, ['count' => $unreadMessages])],
    ];
@endphp
<div class="critical-monitor" data-critical-count="{{ $count }}" data-refresh="{{ $refreshSeconds }}"
     data-updated="{{ now($timezone)->format('g:i:s A') }}">

    {{-- What's critical, in numbers. --}}
    <div class="critical-tiles">
        @foreach ($tiles as $tile)
            <div @class(['critical-tile', 'tone-'.$tile['tone'], 'is-hot' => $tile['value'] > 0 && in_array($tile['tone'], ['danger', 'warning'], true)])>
                <div class="critical-tile-head">
                    <span>{{ $tile['label'] }}</span>
                    <i class="bi {{ $tile['icon'] }}" aria-hidden="true"></i>
                </div>
                <div class="critical-tile-value" data-count-up="{{ $tile['value'] }}">{{ $tile['value'] }}</div>
                <div class="critical-tile-caption">{{ $tile['caption'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- The charts: on time, the most overdue, Data Entry's idle time. --}}
    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">On-time health</div>
                <div class="critical-chart" style="height: 220px"
                     data-critical-chart="{{ json_encode(['type' => 'gauge', 'value' => $health, 'caption' => $timedCount > 0 ? "{$timedCount} timed tasks running" : '', 'empty' => 'No timed task is running right now.']) }}"></div>
                <p class="critical-panel-note">Sourcing and Data Entry tasks running now that aren't past their target.</p>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">Most overdue</div>
                <div class="critical-chart" style="height: {{ 60 + max(1, $mostOverdue->count()) * 30 }}px; min-height: 200px"
                     data-critical-chart="{{ json_encode([
                         'type' => 'bars',
                         'tone' => 'danger',
                         'labels' => $mostOverdue->map(fn (array $task) => $task['label'].' · '.($task['worker']?->name ?? $task['role'].' team'))->values()->all(),
                         'values' => $mostOverdue->map(fn (array $task) => round(-$task['remaining'] / 3600, 1))->values()->all(),
                         'display' => $mostOverdue->map(fn (array $task) => $duration(-$task['remaining']).' over')->values()->all(),
                         'unit' => 'hours over target',
                         'empty' => 'Nothing is past its target.',
                     ]) }}"></div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">Data Entry idle</div>
                @if ($floor['alertOn'])
                    <div class="critical-chart" style="height: {{ 60 + max(1, $floor['people']->count()) * 30 }}px; min-height: 200px"
                         data-critical-chart="{{ json_encode([
                             'type' => 'bars',
                             'tone' => 'warning',
                             'labels' => $floor['people']->map(fn (array $person) => $person['user']->name)->values()->all(),
                             'values' => $floor['people']->map(fn (array $person) => intdiv($person['seconds'], 60))->values()->all(),
                             'display' => $floor['people']->map(fn (array $person) => $person['state'] === 'Idle' ? $duration($person['seconds']).' idle' : $person['state'])->values()->all(),
                             'threshold' => $alertMinutes,
                             'thresholdLabel' => 'Alert at '.$alertMinutes.'m',
                             'unit' => 'minutes idle',
                             'empty' => 'Nobody holds Data Entry.',
                             'allClear' => 'Nobody in Data Entry is idle right now.',
                         ]) }}"></div>
                @else
                    <p class="critical-panel-note mt-2">The idle alert is off, so idle time isn't tracked.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        {{-- Every task past its target, the furthest past first. --}}
        <div class="col-lg-7">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">
                    Overdue tasks <span class="badge badge-soft-danger ms-1">{{ $overdue->count() }}</span>
                </div>
                @forelse ($overdue as $task)
                    <a href="{{ $task['rfq']->detailsUrl($task['part'] ?? null, 'Pending') }}" class="critical-row">
                        <span class="critical-row-mark tone-danger"><i class="bi bi-alarm"></i></span>
                        <span class="critical-row-main">
                            <strong>{{ $task['label'] }}</strong> · {{ $task['task'] }}
                            <span class="critical-row-sub">{{ $task['worker']?->name ?? $task['role'].' team' }} · {{ $task['rfq']->subject }}</span>
                        </span>
                        <span class="badge {{ $task['rfq']->priorityBadgeClass() }}">{{ $task['rfq']->priorityLabel() }}</span>
                        <span class="critical-row-figure text-danger">{{ $duration(-$task['remaining']) }} over</span>
                    </a>
                @empty
                    <p class="critical-empty"><i class="bi bi-check2-circle"></i> Nothing is past its target.</p>
                @endforelse
            </div>
        </div>

        {{-- The P1 Critical, P2 Urgent and P3 High RFQs still open, the most pressing first, then longest-waiting. --}}
        <div class="col-lg-5">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">
                    Critical, urgent &amp; high priority <span class="badge badge-soft-warning ms-1">{{ array_sum($urgentCounts) }}</span>
                </div>
                @forelse ($urgent as $item)
                    <a href="{{ route('admin.rfqs.show', $item['rfq']) }}?status=Pending" class="critical-row">
                        <span class="critical-row-mark {{ $item['rfq']->isUrgent() ? 'tone-danger' : 'tone-warning' }}"><i class="bi bi-exclamation-triangle"></i></span>
                        <span class="critical-row-main">
                            <strong>{{ $item['rfq']->rfq_number }}</strong> · {{ $item['where'] }}
                            <span class="critical-row-sub">{{ $item['rfq']->subject }}</span>
                        </span>
                        <span class="badge {{ $item['rfq']->priorityBadgeClass() }}">{{ $item['rfq']->priorityLabel() }}</span>
                        <span class="critical-row-figure">{{ $item['rfq']->created_at->copy()->setTimezone($timezone)->format('M d, Y g:i A') }}</span>
                    </a>
                @empty
                    <p class="critical-empty"><i class="bi bi-check2-circle"></i> No Critical, Urgent or High RFQ is open.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="row g-3">
        {{-- Sent back, and waiting on whoever it was sent back to. --}}
        <div class="col-lg-4">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">Sent back, waiting</div>
                @foreach ($returns as $return)
                    @php $tag = $return['url'] ? 'a' : 'div'; @endphp
                    <{{ $tag }} @if ($return['url']) href="{{ $return['url'] }}" @endif class="critical-row">
                        <span class="critical-row-main">{{ $return['label'] }}</span>
                        <span @class(['critical-row-figure', 'text-danger fw-bold' => $return['count'] > 0, 'text-muted-soft' => $return['count'] === 0])>{{ $return['count'] }}</span>
                    </{{ $tag }}>
                @endforeach
            </div>
        </div>

        {{-- The viewer's own unread alerts and messages. --}}
        <div class="col-lg-4">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">
                    Your alerts <span class="badge badge-soft-primary ms-1">{{ $unreadAlerts }}</span>
                </div>
                @forelse ($alerts as $alert)
                    <a href="{{ $alert['url'] }}" class="critical-row">
                        <span class="critical-row-mark tone-warning"><i class="bi {{ $alert['icon'] }}"></i></span>
                        <span class="critical-row-main">
                            {{ $alert['text'] }}
                            <span class="critical-row-sub">{{ $alert['at']->copy()->setTimezone($timezone)->format('M d, Y g:i A') }}</span>
                        </span>
                    </a>
                @empty
                    <p class="critical-empty"><i class="bi bi-check2-circle"></i> No unread alerts.</p>
                @endforelse
            </div>
        </div>
        <div class="col-lg-4">
            <div class="critical-panel h-100">
                <div class="critical-panel-title">
                    Your messages <span class="badge badge-soft-primary ms-1">{{ $unreadMessages }}</span>
                </div>
                @forelse ($messages as $message)
                    <a href="{{ $message['url'] }}" class="critical-row">
                        <span class="critical-row-mark tone-primary"><i class="bi {{ $message['icon'] }}"></i></span>
                        <span class="critical-row-main">
                            {{ $message['text'] }}
                            <span class="critical-row-sub">{{ $message['at']->copy()->setTimezone($timezone)->format('M d, Y g:i A') }} · {{ $message['note'] }}</span>
                        </span>
                    </a>
                @empty
                    <p class="critical-empty"><i class="bi bi-check2-circle"></i> No unread messages.</p>
                @endforelse
            </div>
        </div>
    </div>
</div>
