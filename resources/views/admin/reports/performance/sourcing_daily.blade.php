{{--
    Sourcing's daily (and weekly) report — the Procurement Daily Report (see
    Admin\PerformanceReportController::sourcingDaily(), App\PerformanceReports::
    sourcingDaily()): for each person picked, each working day in the range,
    the RFQs they finalized and their items, and at the end of the day the RFQs
    still pending with them and their items, and those waiting for the
    customer's clarification. Each person's figures across the range follow
    when it's more than a day; the total, every RFQ once, is at the bottom.

    Expects: $report (rows, people, total, asOf), $everyone, $chosen, $from,
    $to, $clamped, $sort, $descending.
--}}
@extends('layouts.app')

@section('title', 'Sourcing Daily Report')

@section('content')
    @include('admin.reports.performance._nav')

    @php
        $dayLabel = fn (string $day) => \Carbon\CarbonImmutable::parse($day)->format('d-m-Y');
        $isRange = $from !== $to;
    @endphp

    <div class="card mb-3">
        <form method="GET" action="{{ route('admin.reports.sourcing-daily') }}" class="ops-filters report-filters">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $descending ? 'desc' : 'asc' }}">
            <div class="ops-filters-head">
                <span class="ops-filters-title"><i class="bi bi-funnel"></i> Procurement daily report</span>
                @if (request()->hasAny(['people', 'from', 'to']))
                    <a href="{{ route('admin.reports.sourcing-daily') }}" class="ops-filters-clear"><i class="bi bi-x-circle"></i> Clear filters</a>
                @endif
            </div>
            @include('admin.reports.performance._people_filter', ['role' => 'Sourcing'])
            @include('admin.reports.performance._day_range')
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Show report</button>
        </form>
    </div>

    @if ($clamped)
        <div class="alert alert-info small"><i class="bi bi-info-circle"></i> A daily report covers up to {{ \App\PerformanceReports::MAX_DAYS }} days — showing {{ $dayLabel($from) }} to {{ $dayLabel($to) }}.</div>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between flex-wrap gap-2">
            <span>{{ $isRange ? $dayLabel($from).' to '.$dayLabel($to) : $dayLabel($from) }}</span>
            <span class="text-muted-soft small">Pending and waiting: as at the end of each day</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 report-table">
                <thead>
                    <tr>
                        @include('admin.reports.performance._sort_th', ['key' => 'name', 'label' => 'Name'])
                        @include('admin.reports.performance._sort_th', ['key' => 'date', 'label' => 'Date'])
                        @include('admin.reports.performance._sort_th', ['key' => 'rfqs', 'label' => 'Finalized RFQs', 'class' => 'text-end'])
                        @include('admin.reports.performance._sort_th', ['key' => 'items', 'label' => 'No. of items in finalized RFQs', 'class' => 'text-end'])
                        <th class="text-end">Pending RFQs</th>
                        <th class="text-end">No. of items in pending RFQs</th>
                        <th class="text-end">Waiting for customer clarification</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="fw-semibold text-nowrap">{{ $row['user']->name }}</td>
                            <td class="text-nowrap">{{ $dayLabel($row['date']) }} <span class="text-muted-soft small">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('D') }}</span></td>
                            <td class="text-end">{{ number_format($row['finalized']) }}</td>
                            <td class="text-end">{{ number_format($row['finalized_items']) }}</td>
                            <td class="text-end">{{ number_format($row['pending']) }}</td>
                            <td class="text-end">{{ number_format($row['pending_items']) }}</td>
                            <td class="text-end">{{ number_format($row['customer']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted-soft py-4">No working days for the people picked in these dates.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        <th colspan="2">
                            Total
                            <div class="text-muted-soft small fw-normal">Each RFQ once · pending and waiting as at {{ $dayLabel($report['asOf']) }}</div>
                        </th>
                        <th class="text-end">{{ number_format($report['total']['finalized']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['finalized_items']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['pending']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['pending_items']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['customer']) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @if ($isRange && $report['people']->count() > 0)
        <div class="card mb-3">
            <div class="card-header">By person, {{ $dayLabel($from) }} to {{ $dayLabel($to) }}</div>
            <div class="table-responsive">
                <table class="table mb-0 report-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="text-end">Finalized RFQs</th>
                            <th class="text-end">No. of items in finalized RFQs</th>
                            <th class="text-end">Pending RFQs <span class="text-muted-soft small fw-normal">at {{ $dayLabel($report['asOf']) }}</span></th>
                            <th class="text-end">No. of items in pending RFQs</th>
                            <th class="text-end">Waiting for customer clarification</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['people'] as $summary)
                            <tr>
                                <td class="fw-semibold text-nowrap">{{ $summary['user']->name }}</td>
                                <td class="text-end">{{ number_format($summary['finalized']) }}</td>
                                <td class="text-end">{{ number_format($summary['finalized_items']) }}</td>
                                <td class="text-end">{{ number_format($summary['pending']) }}</td>
                                <td class="text-end">{{ number_format($summary['pending_items']) }}</td>
                                <td class="text-end">{{ number_format($summary['customer']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @include('admin.reports.performance._how_counted', ['points' => [
        'An RFQ counts once for each person who had a part of it, and once in a total — its items are the RFQ\'s number of items.',
        'Finalized: the RFQs the person finalized (Sourcing\'s Finalize) that day.',
        'Pending: at the end of the day, the RFQs they\'d been given and not yet finalized — those waiting for the customer\'s clarification are left out and counted on their own, and cancelled ones aren\'t counted.',
        'Waiting for customer clarification: on hold with "Waiting for customer clarification" ticked when it was put on hold.',
        'Each working day of each person is listed, and any other day they finalized something on.',
    ]])
@endsection
