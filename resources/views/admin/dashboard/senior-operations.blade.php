{{--
    Senior Operations' dashboard — where every RFQ stands
    (DashboardController::seniorOperationsOverview()): a card for each
    status — pending, overdue, due soon, on hold, cancelled, closed — each
    opening All RFQs with that Status filter (?state=, Rfq::STATUS_FILTERS),
    counted as the list counts them; the RFQs created over time; the latest
    RFQs, marked Overdue or Due soon where a task is; and their alerts.

    Expects: $overview, $rfqActivity, $recentRfqs, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @include('admin.dashboard._header', ['subtitle' => "Where every RFQ stands — what's pending, what's past its target, and what's stopped or closed."])

    {{-- Every RFQ by status, each card leading to the RFQs it counted. --}}
    <div class="row g-3 mb-4" id="rfq-statuses">
        @foreach ($overview['statuses'] as $card)
            <div class="col-6 col-md-4 col-xxl-2">
                <a href="{{ $card['url'] }}" class="stat-card-link" data-status-card="{{ $card['key'] }}">
                    <div class="stat-card kpi-card">
                        <div class="kpi-card-head">
                            <span class="stat-label">{{ $card['label'] }}</span>
                            <span class="stat-icon {{ $card['tone'] ? 'stat-icon-'.$card['tone'] : '' }}"><i class="bi {{ $card['icon'] }}"></i></span>
                        </div>
                        <div class="stat-value">{{ $card['value'] }}</div>
                        <div class="stat-caption {{ $card['alert'] ? 'text-danger fw-semibold' : '' }}">{{ $card['caption'] }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            @include('admin.dashboard._activity')
            @include('admin.dashboard._recent_rfqs', ['targetStates' => $overview['targetStates']])
        </div>

        <div class="col-lg-4">
            @include('admin.dashboard._notifications')
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('js/rfq-chart.js') }}?v={{ filemtime(public_path('js/rfq-chart.js')) }}"></script>
@endpush
