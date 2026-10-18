{{--
    Sourcing's monthly performance evaluation (see Admin\PerformanceReportController::
    sourcingMonthly(), App\PerformanceReports::sourcingMonthly()): for each
    person picked, in the month, the RFQs they received, the quotations they
    completed (finalized), the average working time from receiving a part to
    finalizing it, and the items of the RFQs they received. The total, every
    RFQ once, at the bottom.

    Expects: $report (rows, total), $everyone, $chosen, $month ("Y-m"),
    $monthLabel, $thisMonth, $sort, $descending.
--}}
@extends('layouts.app')

@section('title', 'Sourcing Monthly Report')

@section('content')
    @include('admin.reports.performance._nav')

    @php
        $average = fn (?int $seconds) => $seconds === null ? '—' : \App\Models\Setting::durationLabel($seconds);
    @endphp

    <div class="card mb-3">
        <form method="GET" action="{{ route('admin.reports.sourcing-monthly') }}" class="ops-filters report-filters">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $descending ? 'desc' : 'asc' }}">
            <div class="ops-filters-head">
                <span class="ops-filters-title"><i class="bi bi-funnel"></i> Monthly performance evaluation</span>
                @if (request()->hasAny(['people', 'month']))
                    <a href="{{ route('admin.reports.sourcing-monthly') }}" class="ops-filters-clear"><i class="bi bi-x-circle"></i> Clear filters</a>
                @endif
            </div>
            @include('admin.reports.performance._people_filter', ['role' => 'Sourcing'])
            <div class="ops-filter-row">
                <span class="ops-filter-label">Month</span>
                <input type="month" name="month" class="form-control form-control-sm w-auto" value="{{ $month }}" max="{{ $thisMonth }}" aria-label="Month">
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Show report</button>
        </form>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between flex-wrap gap-2">
            <span>{{ $monthLabel }}</span>
            <span class="text-muted-soft small">Average completion time: working hours</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 report-table">
                <thead>
                    <tr>
                        @include('admin.reports.performance._sort_th', ['key' => 'name', 'label' => 'Name'])
                        <th>Month</th>
                        @include('admin.reports.performance._sort_th', ['key' => 'rfqs', 'label' => 'Total RFQs received', 'class' => 'text-end'])
                        <th class="text-end">Total quotations completed</th>
                        <th class="text-end">Average RFQ completion time</th>
                        @include('admin.reports.performance._sort_th', ['key' => 'items', 'label' => 'Total no. of items for month', 'class' => 'text-end'])
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="fw-semibold text-nowrap">{{ $row['user']->name }}</td>
                            <td class="text-nowrap">{{ $monthLabel }}</td>
                            <td class="text-end">{{ number_format($row['received']) }}</td>
                            <td class="text-end">{{ number_format($row['completed']) }}</td>
                            <td class="text-end text-nowrap">{{ $average($row['average']) }}</td>
                            <td class="text-end">{{ number_format($row['items']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted-soft py-4">Nobody holds the Sourcing role yet.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        <th colspan="2">Total <div class="text-muted-soft small fw-normal">Each RFQ once</div></th>
                        <th class="text-end">{{ number_format($report['total']['received']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['completed']) }}</th>
                        <th class="text-end text-nowrap">{{ $average($report['total']['average']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['items']) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @include('admin.reports.performance._how_counted', ['points' => [
        'RFQs received: the RFQs the person was given a part of in the month — counted from when they first had it. An RFQ counts once for each person, and once in the total.',
        'Quotations completed: the RFQs the person finalized (Sourcing\'s Finalize) in the month.',
        'Average RFQ completion time: for each part they finalized in the month, the working time from when they received it to when they finalized it — only working hours count, their own extra hours included.',
        'Total no. of items: the number of items of the RFQs they received in the month.',
    ]])
@endsection
