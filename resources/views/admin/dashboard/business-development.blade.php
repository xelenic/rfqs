{{--
    Business Development's dashboard — its queues, where the pending RFQs
    sit in the workflow, and what happened today. See
    DashboardController::businessDevelopmentOverview().

    Expects: $overview, $rfqActivity, $recentRfqs, $notifications.
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
                'caption' => $overview['today']['closed'].' closed today',
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

    <div class="card mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>Today's summary</span>
            <span class="text-muted-soft small fw-normal">{{ now()->format('l, M j') }}</span>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="today-tiles">
                        @foreach ([
                            ['New RFQs', $overview['today']['created'], 'bi-plus-circle', 'primary'],
                            ['Approved by GM', $overview['today']['approved'], 'bi-award', 'violet'],
                            ['Sent back', $overview['today']['sentBack'], 'bi-arrow-counterclockwise', 'danger'],
                            ['Closed', $overview['today']['closed'], 'bi-flag-fill', 'success'],
                        ] as [$label, $value, $icon, $tone])
                            <div class="today-tile tone-{{ $tone }}">
                                <span class="today-tile-icon"><i class="bi {{ $icon }}"></i></span>
                                <span class="today-tile-value">{{ $value }}</span>
                                <span class="today-tile-label">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-7">
                    <h3 class="today-heading">Today's activity</h3>
                    @forelse ($overview['feed'] as $event)
                        <a href="{{ route('admin.rfqs.show', $event['rfq']) }}" class="today-feed-item tone-{{ $event['tone'] }}">
                            <span class="today-feed-icon"><i class="bi {{ $event['icon'] }}"></i></span>
                            <span class="today-feed-text">
                                <strong>{{ $event['rfq']->rfq_number }}</strong> {{ $event['text'] }}
                                @if ($event['actor'])
                                    <span class="text-muted-soft">· {{ $event['actor'] }}</span>
                                @endif
                            </span>
                            <span class="today-feed-time">{{ $event['at']->format('g:i A') }}</span>
                        </a>
                    @empty
                        <p class="text-muted-soft small mb-0">Nothing has happened yet today.</p>
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
                                    Approved {{ $item['approved_at']?->diffForHumans() ?? '—' }}@if ($item['approved_by']) by {{ $item['approved_by'] }}@endif
                                </div>
                            </div>
                            @if ($item['part'] !== null)
                                <form action="{{ route('admin.rfqs.close-part', $item['rfq']) }}" method="POST"
                                      data-confirm="Close {{ $item['rfq_number'] }}? It moves into Closed RFQs.">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="part" value="{{ $item['part'] }}">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-flag"></i> Close
                                    </button>
                                </form>
                            @else
                                <form action="{{ route('admin.rfqs.close', $item['rfq']) }}" method="POST"
                                      data-confirm="Close this RFQ? It moves out of Pending into Closed RFQs.">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-flag"></i> Close
                                    </button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="text-muted-soft small mb-0">Nothing is waiting to be closed.</p>
                    @endforelse
                </div>
            </div>

            @include('admin.dashboard._notifications')
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/rfq-chart.js') }}"></script>
@endpush
