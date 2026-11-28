{{--
    Sourcing's monthly KPI report — the business's Monthly Employee RFQ
    Performance & KPI sheet (Admin\PerformanceReportController::sourcingKpi(),
    App\PerformanceReports::sourcingKpi()): a row a person for the month,
    under the sheet's letter codes and headings (KPI_COLUMNS), with the total.
    Download it as an Excel workbook, with live formulas (sourcingKpiExport()).

    Expects: $report (rows, total, lastDay), $everyone, $chosen, $month
    ("Y-m"), $monthLabel, $thisMonth.
--}}
@extends('layouts.app')

@section('title', 'Sourcing KPI Report')

@section('content')
    @include('admin.reports.performance._nav')

    @php
        $columns = \App\Http\Controllers\Admin\PerformanceReportController::KPI_COLUMNS;
        $percent = fn (float $rate) => number_format($rate, 2).'%';
        $average = fn (?int $seconds) => $seconds === null ? '—' : \App\Models\Setting::durationLabel($seconds);
        $isThisMonth = $month === $thisMonth;
        $exportQuery = array_filter(['month' => $month, 'people' => request()->query('people')]);
    @endphp

    <div class="card mb-3">
        <form method="GET" action="{{ route('admin.reports.sourcing-kpi') }}" class="ops-filters report-filters">
            <div class="ops-filters-head">
                <span class="ops-filters-title"><i class="bi bi-funnel"></i> Monthly RFQ performance &amp; KPI</span>
                @if (request()->hasAny(['people', 'month']))
                    <a href="{{ route('admin.reports.sourcing-kpi') }}" class="ops-filters-clear"><i class="bi bi-x-circle"></i> Clear filters</a>
                @endif
            </div>
            @include('admin.reports.performance._people_filter', ['role' => 'Sourcing'])
            <div class="ops-filter-row">
                <span class="ops-filter-label">Month</span>
                <input type="month" name="month" class="form-control form-control-sm w-auto" value="{{ $month }}" max="{{ $thisMonth }}" aria-label="Month">
            </div>
            <div class="d-flex flex-wrap gap-2">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Show report</button>
                <a href="{{ route('admin.reports.sourcing-kpi.export', $exportQuery) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-excel"></i> Download Excel</a>
            </div>
        </form>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between flex-wrap gap-2">
            <span>{{ $monthLabel }}</span>
            <span class="text-muted-soft small">
                @if ($isThisMonth)
                    Working days to {{ \Carbon\CarbonImmutable::parse($report['lastDay'])->format('M d') }} so far ·
                @endif
                Completion time: working hours
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 report-table report-kpi-table">
                <thead>
                    <tr class="report-kpi-codes">
                        <th></th>
                        @foreach ($columns as [$code])
                            <th class="text-end">{{ $code }}</th>
                        @endforeach
                    </tr>
                    <tr>
                        <th>Employee</th>
                        @foreach ($columns as [, $heading])
                            <th class="text-end">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="fw-semibold text-nowrap">
                                {{ $row['user']->name }}
                                @if ($row['joinedOn'] === null)
                                    <span class="badge badge-soft-secondary ms-1" title="Their join date isn't on their user page, so every working day expects the full {{ \App\Models\Setting::kpiSettledRate() }} RFQs">Join date not set</span>
                                @endif
                                @if ($row['user']->isDeactivated())
                                    <span class="badge badge-soft-secondary ms-1">Deactivated</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($row['days']) }}</td>
                            <td class="text-end">{{ number_format($row['expected']) }}</td>
                            <td class="text-end">{{ number_format($row['received']) }}</td>
                            <td class="text-end">{{ number_format($row['completed']) }}</td>
                            <td class="text-end">{{ $percent($row['rateOfExpected']) }}</td>
                            <td class="text-end">{{ $percent($row['rateOfReceived']) }}</td>
                            <td class="text-end text-nowrap">{{ $average($row['average']) }}</td>
                            <td class="text-end">{{ number_format($row['items']) }}</td>
                            <td class="text-end">{{ number_format($row['rejected']) }}</td>
                            <td class="text-end">{{ number_format($row['overdue']) }}</td>
                            <td class="text-end">{{ number_format($row['callsPerDay'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['won']) }}</td>
                            <td class="text-end">{{ $percent($row['winRate']) }}</td>
                            <td class="text-end">{{ number_format($row['unattendedCalls']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($columns) + 1 }}" class="text-center text-muted-soft py-4">Nobody in Sourcing to show for {{ $monthLabel }}.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        <th>Total <div class="text-muted-soft small fw-normal">Everyone's added up</div></th>
                        <th class="text-end">{{ number_format($report['total']['days']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['expected']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['received']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['completed']) }}</th>
                        <th class="text-end">{{ $percent($report['total']['rateOfExpected']) }}</th>
                        <th class="text-end">{{ $percent($report['total']['rateOfReceived']) }}</th>
                        <th class="text-end text-nowrap">{{ $average($report['total']['average']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['items']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['rejected']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['overdue']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['callsPerDay'], 2) }}</th>
                        <th class="text-end">{{ number_format($report['total']['won']) }}</th>
                        <th class="text-end">{{ $percent($report['total']['winRate']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['unattendedCalls']) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    @php
        $tiers = collect(\App\Models\Setting::KPI_DAILY_RFQS);
        $tierFrom = 1;
        $tierText = $tiers->map(function (int $rfqs, int $upTo) use (&$tierFrom) {
            $text = "days {$tierFrom}–{$upTo}: {$rfqs}";
            $tierFrom = $upTo + 1;

            return $text;
        })->push("day {$tierFrom} on: ".\App\Models\Setting::kpiSettledRate())->join(', ');
    @endphp
    @include('admin.reports.performance._how_counted', ['points' => [
        'a — Working days: the days of their working week in the month that aren\'t company holidays (Settings → Monthly KPI), from the day they joined (their user page), and before any deactivation. In the month we\'re in, up to today.',
        "b — Expected RFQs: worked out day by day, by how many working days they'd been with the company that day ({$tierText} a day). Without a join date, every day expects the full rate.",
        'c — RFQs received: the RFQs they were given a part of in the month, from when they first had it. d — Quotations completed: the RFQs they finalized in the month.',
        'e and f — d as a percentage of b, and of c; 0 when there\'s nothing to divide by.',
        'Average completion time: for each part they finalized in the month, the working time from receiving it to finalizing it. Items: the items of the RFQs they received.',
        'Rejected RFQs: the RFQs a reviewer rejected back to them at Sourcing in the month — a Return for changes doesn\'t count.',
        'Overdue RFQs: the RFQs whose Sourcing time went over its target, counted in the month their Sourcing work on it finished — or, still with them, in the month we\'re in.',
        'g — Successful calls a day: the successful supplier calls they entered for the month, divided by a. k — The unattended calls from suppliers they entered.',
        'h — Winning quotations: the RFQs they finalized that the General Manager approved in the month. i — h as a percentage of d.',
        'Total: everyone\'s figures added up, an RFQ once for each person who had it; its rates are worked out from its sums. The Excel download has the same figures, with the rates as live formulas.',
    ]])
@endsection
