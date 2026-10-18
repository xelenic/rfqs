{{--
    The performance reports' tabs (Admin\PerformanceReportController), with
    Print. The person reports carry who's picked from one to the next.
--}}
@php
    $reports = [
        'admin.reports.sourcing-daily' => ['Sourcing · Daily', 'bi-calendar-day'],
        'admin.reports.data-entry-daily' => ['Data Entry · Daily', 'bi-keyboard'],
        'admin.reports.sourcing-monthly' => ['Sourcing · Monthly', 'bi-calendar3'],
        'admin.reports.rfq-tracking' => ['RFQ Tracking Sheet', 'bi-table'],
    ];
@endphp
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 report-tabs">
    <ul class="nav nav-tabs flex-grow-1" aria-label="Performance reports">
        @foreach ($reports as $route => [$label, $icon])
            <li class="nav-item">
                <a href="{{ route($route) }}" @class(['nav-link', 'active' => request()->routeIs($route)]) @if (request()->routeIs($route)) aria-current="page" @endif>
                    <i class="bi {{ $icon }}"></i> {{ $label }}
                </a>
            </li>
        @endforeach
    </ul>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>
