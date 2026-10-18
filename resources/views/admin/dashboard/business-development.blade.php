{{--
    Business Development's dashboard — its queues, where the pending RFQs
    sit in the workflow, and a summary of a period they pick (today, the
    last 3 days, the week, or their own dates). See
    DashboardController::businessDevelopmentOverview() and summaryPeriod().

    Expects: $period, $overview, $rfqActivity, $recentRfqs, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @include('admin.dashboard._header', ['subtitle' => "Here's where your RFQs stand, and what's happened today."])

    <div class="row g-3 mb-4">
        @foreach ([
            [
                'label' => 'Pending RFQs', 'value' => $overview['pending'], 'icon' => 'bi-hourglass-split', 'iconClass' => 'stat-icon-warning',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                'caption' => $overview['urgent'] > 0 ? $overview['urgent'].' urgent' : 'None urgent',
                'alert' => $overview['urgent'] > 0,
            ],
            [
                'label' => 'In Review', 'value' => $overview['inReview'], 'icon' => 'bi-clipboard2-check', 'iconClass' => 'stat-icon-violet',
                'url' => route('admin.rfqs.index', ['status' => 'Pending']),
                'caption' => 'In the approval chain',
                'alert' => false,
            ],
            [
                'label' => 'Ready to Close', 'value' => $overview['readyToClose'], 'icon' => 'bi-flag', 'iconClass' => 'stat-icon-teal',
                'url' => route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']),
                'caption' => 'Approved by the General Manager',
                'alert' => false,
            ],
            [
                'label' => 'Closed RFQs', 'value' => $overview['closed'], 'icon' => 'bi-check2-circle', 'iconClass' => 'stat-icon-success',
                'url' => route('admin.rfqs.index', ['status' => 'Completed']),
                'caption' => $overview['closedToday'].' closed today',
                'alert' => false,
            ],
        ] as $card)
            <div class="col-sm-6 col-xl-3">
                <a href="{{ $card['url'] }}" class="stat-card-link">
                    <div class="stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">{{ $card['label'] }}</div>
                            <div class="stat-value">{{ $card['value'] }}</div>
                            <div class="stat-caption {{ $card['alert'] ? 'text-danger fw-semibold' : '' }}">{{ $card['caption'] }}</div>
                        </div>
                        <div class="stat-icon {{ $card['iconClass'] }}"><i class="bi {{ $card['icon'] }}"></i></div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    @php
        $timezone = \App\Models\Setting::timezone();
        $todayDate = now($timezone)->toDateString();
        $periodDates = $period['dates'];
        $periodLabels = collect(\App\Http\Controllers\Admin\DashboardController::SUMMARY_PERIODS)->map->label->all() + ['custom' => 'Custom'];
    @endphp
    <div class="card mb-4">
        <div class="card-header summary-card-header">
            <div>
                <span>
                    @if ($period['isToday'])
                        Today's summary
                    @else
                        Summary
                    @endif
                </span>
                <span class="text-muted-soft small fw-normal ms-1">{{ $periodDates }}</span>
            </div>

            {{-- A preset goes straight there; Custom waits for its dates (admin.js, .js-period-filter). --}}
            <form method="GET" action="{{ route('admin.dashboard') }}" id="summaryFilterForm" class="summary-filter js-period-filter"
                  data-period-field="period" data-period-default="today" data-today="{{ $todayDate }}">
                @if (request()->filled('range'))
                    <input type="hidden" name="range" value="{{ request()->integer('range') }}">
                @endif
                <div class="range-filter ops-range" role="radiogroup" aria-label="Summary period">
                    @foreach ($periodLabels as $value => $label)
                        <label class="range-filter-btn">
                            <input type="radio" name="period" value="{{ $value }}" @checked($period['key'] === $value)>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
                <div class="ops-custom-range js-custom-range {{ $period['key'] === 'custom' ? '' : 'd-none' }}" id="summaryCustomRange">
                    <input type="date" name="from" class="form-control form-control-sm" aria-label="From"
                           value="{{ $period['key'] === 'custom' ? $period['from'] : '' }}" max="{{ $period['key'] === 'custom' ? $period['to'] : $todayDate }}">
                    <span class="text-muted-soft">to</span>
                    <input type="date" name="to" class="form-control form-control-sm" aria-label="To"
                           value="{{ $period['key'] === 'custom' ? $period['to'] : '' }}" min="{{ $period['key'] === 'custom' ? $period['from'] : '' }}" max="{{ $todayDate }}">
                    <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                </div>
            </form>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="today-tiles">
                        @foreach ([
                            ['Created', $overview['summary']['created'], 'bi-plus-circle', 'primary', null],
                            ['Approved by GM', $overview['summary']['approved'], 'bi-award', 'violet', null],
                            ['Returned', $overview['summary']['returned'], 'bi-arrow-counterclockwise', 'danger', $overview['summary']['returnedToBd'] > 0 ? $overview['summary']['returnedToBd'].' to Business Development' : null],
                            ['Closed', $overview['summary']['closed'], 'bi-flag-fill', 'success', null],
                        ] as [$label, $value, $icon, $tone, $note])
                            <div class="today-tile tone-{{ $tone }}">
                                <span class="today-tile-icon"><i class="bi {{ $icon }}"></i></span>
                                <span class="today-tile-value">{{ $value }}</span>
                                <span class="today-tile-label">{{ $label }}</span>
                                @if ($note)
                                    <span class="today-tile-note">{{ $note }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-7">
                    <h3 class="today-heading">
                        @if ($period['isToday'])
                            Today's activity
                        @else
                            Activity
                        @endif
                    </h3>
                    @forelse ($overview['feed'] as $event)
                        @php $eventAt = $event['at']->copy()->setTimezone($timezone); @endphp
                        <a href="{{ route('admin.rfqs.show', $event['rfq']) }}" class="today-feed-item tone-{{ $event['tone'] }}">
                            <span class="today-feed-icon"><i class="bi {{ $event['icon'] }}"></i></span>
                            <span class="today-feed-text">
                                <strong>{{ $event['rfq']->rfq_number }}</strong> {{ $event['text'] }}
                                @if ($event['actor'])
                                    <span class="text-muted-soft">· {{ $event['actor'] }}</span>
                                @endif
                            </span>
                            <span class="today-feed-time">{{ $eventAt->format($period['from'] === $period['to'] ? 'g:i A' : 'M j, g:i A') }}</span>
                        </a>
                    @empty
                        <p class="text-muted-soft small mb-0">{{ $period['isToday'] ? 'Nothing has happened yet today.' : 'Nothing happened in this period.' }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            @include('admin.dashboard._activity', ['showTotals' => false, 'completedLabel' => 'Closed'])

            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Pending RFQs by stage</span>
                    <span class="text-muted-soft small fw-normal">{{ $overview['pending'] }} pending</span>
                </div>
                <div class="card-body">
                    @php
                        $busiestStage = max(1, collect($overview['stages'])->max('count'));
                    @endphp
                    <ul class="pipeline list-unstyled mb-0">
                        @foreach ($overview['stages'] as $stage)
                            <li class="pipeline-row tone-{{ $stage['tone'] }}">
                                <span class="pipeline-label">{{ $stage['label'] }}</span>
                                <span class="pipeline-track">
                                    <span class="pipeline-bar" style="width: {{ round($stage['count'] / $busiestStage * 100, 1) }}%"></span>
                                </span>
                                <span class="pipeline-count">{{ $stage['count'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            @include('admin.dashboard._recent_rfqs')
        </div>

        <div class="col-lg-4">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Ready to close</span>
                    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']) }}" class="small fw-semibold">View all <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="card-body">
                    {{-- A part the General Manager has approved, closed on its own; an
                         RFQ kept whole (or at its closing stage with no part to show),
                         closed as a whole. --}}
                    @forelse ($overview['readyToCloseRfqs'] as $item)
                        <div class="dash-item">
                            <div>
                                <a href="{{ route('admin.rfqs.show', $item['rfq']) }}" class="fw-semibold">{{ $item['rfq_number'] }}</a>
                                <div class="small">{{ $item['subject'] }}</div>
                                <div class="text-muted-soft small">
                                    Approved {{ $item['approved_at']?->toBusinessTimezone()->format('M d, Y g:i A') ?? '—' }}@if ($item['approved_by']) by {{ $item['approved_by'] }}@endif
                                </div>
                            </div>
                            @include('admin.rfqs._close_button', [
                                'rfq' => $item['rfq'],
                                'part' => $item['part'],
                                'label' => $item['rfq_number'],
                                'hint' => $item['part'] !== null ? 'It moves into Closed RFQs.' : 'It moves out of Pending into Closed RFQs.',
                            ])
                        </div>
                    @empty
                        <p class="text-muted-soft small mb-0">Nothing is waiting to be closed.</p>
                    @endforelse
                </div>
            </div>

            @include('admin.dashboard._notifications')
            @include('admin.rfqs._close_modal')
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('js/rfq-chart.js') }}?v={{ filemtime(public_path('js/rfq-chart.js')) }}"></script>
@endpush
