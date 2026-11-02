{{--
    The Sourcing Summary, laid out as the business's sheet (see
    Admin\PerformanceReportController::sourcingSummary(), App\PerformanceReports::
    sourcingSummary()): for each person picked, over a day or a range, the RFQs
    they finalized and still have pending, with their items, those waiting for
    the customer's clarification and the supplier calls they entered, with a
    total; then the RFQs expected of them and those they received. The figures
    typed in by hand are yellow, as on the sheet (SourcingDayFigure). On one day,
    Senior Operations and Admin can change them right here ($canEditFigures).

    Expects: $report (rows, total, asOf), $everyone, $chosen, $from, $to,
    $clamped, $canEditFigures.
--}}
@extends('layouts.app')

@section('title', 'Sourcing Summary')

@section('content')
    @include('admin.reports.performance._nav')

    @php
        $dayLabel = fn (string $day) => \Carbon\CarbonImmutable::parse($day)->format('d-m-Y');
        $isRange = $from !== $to;
        $typedIn = fn (?int $figure) => $figure === null ? '—' : number_format($figure);
    @endphp

    <div class="card mb-3">
        <form method="GET" action="{{ route('admin.reports.sourcing-summary') }}" class="ops-filters report-filters">
            <div class="ops-filters-head">
                <span class="ops-filters-title"><i class="bi bi-funnel"></i> Sourcing summary</span>
                @if (request()->hasAny(['people', 'from', 'to']))
                    <a href="{{ route('admin.reports.sourcing-summary') }}" class="ops-filters-clear"><i class="bi bi-x-circle"></i> Clear filters</a>
                @endif
            </div>
            @include('admin.reports.performance._people_filter', ['role' => 'Sourcing'])
            @include('admin.reports.performance._day_range')
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat"></i> Show report</button>
        </form>
    </div>

    @if ($clamped)
        <div class="alert alert-info small"><i class="bi bi-info-circle"></i> This report covers up to {{ \App\PerformanceReports::MAX_DAYS }} days — showing {{ $dayLabel($from) }} to {{ $dayLabel($to) }}.</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger small" role="alert"><i class="bi bi-exclamation-triangle"></i> {{ $errors->first() }}</div>
    @endif

    {{-- One form across both tables: each box says it belongs to it. --}}
    @if ($canEditFigures)
        <form method="POST" action="{{ route('admin.reports.sourcing-summary.update') }}" id="sourcing-figures-form">
            @csrf
            @method('PUT')
            <input type="hidden" name="day" value="{{ $from }}">
        </form>
    @endif

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between flex-wrap gap-2">
            <span>{{ $isRange ? $dayLabel($from).' to '.$dayLabel($to) : $dayLabel($from) }}</span>
            <span class="text-muted-soft small">Pending and waiting: as at the end of {{ $dayLabel($report['asOf']) }}</span>
        </div>
        <div class="table-responsive">
            <table class="table mb-0 report-table" data-sourcing-summary>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="text-end">RFQ Finalized Successfully</th>
                        <th class="text-end">Total items in Finalized RFQ</th>
                        <th class="text-end">No of Pending RFQ</th>
                        <th class="text-end">Total items in Pending RFQ</th>
                        <th class="text-end">RFQ pending for Customer Clarifications</th>
                        @foreach (\App\Models\SourcingDayFigure::CALL_FIELDS as $callLabel)
                            <th class="text-end report-typed-in">{{ $callLabel }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="fw-semibold text-nowrap">{{ $row['user']->name }}</td>
                            <td class="text-end">{{ number_format($row['finalized']) }}</td>
                            <td class="text-end">{{ number_format($row['finalized_items']) }}</td>
                            <td class="text-end">{{ number_format($row['pending']) }}</td>
                            <td class="text-end">{{ number_format($row['pending_items']) }}</td>
                            <td class="text-end">{{ number_format($row['customer']) }}</td>
                            @foreach (\App\Models\SourcingDayFigure::CALL_FIELDS as $callField => $callLabel)
                                <td class="text-end report-typed-in">
                                    @if ($canEditFigures)
                                        <input type="number" form="sourcing-figures-form" name="figures[{{ $row['user']->id }}][{{ $callField }}]" value="{{ old("figures.{$row['user']->id}.{$callField}", $row[$callField]) }}"
                                               min="0" max="{{ \App\Models\SourcingDayFigure::MAX_FIGURE }}" step="1" inputmode="numeric" class="form-control form-control-sm report-typed-in-input" aria-label="{{ $callLabel }}, {{ $row['user']->name }}">
                                    @else
                                        {{ $typedIn($row[$callField]) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted-soft py-4">Nobody's in Sourcing yet.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        <th>
                            Total
                            <div class="text-muted-soft small fw-normal">Each RFQ once</div>
                        </th>
                        <th class="text-end">{{ number_format($report['total']['finalized']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['finalized_items']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['pending']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['pending_items']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['customer']) }}</th>
                        @foreach (\App\Models\SourcingDayFigure::CALL_FIELDS as $callField => $callLabel)
                            <th class="text-end report-typed-in">{{ $typedIn($report['total'][$callField]) }}</th>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="card mb-3" style="max-width: 44rem;">
        <div class="table-responsive">
            <table class="table mb-0 report-table" data-sourcing-expected>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th class="text-end report-typed-in">Number of expected RFQ</th>
                        <th class="text-end">Number of Received RFQ</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr>
                            <td class="fw-semibold text-nowrap">{{ $row['user']->name }}</td>
                            <td class="text-end report-typed-in">
                                @if ($canEditFigures)
                                    <input type="number" form="sourcing-figures-form" name="figures[{{ $row['user']->id }}][expected_rfqs]" value="{{ old("figures.{$row['user']->id}.expected_rfqs", $row['expected_rfqs']) }}"
                                           min="0" max="{{ \App\Models\SourcingDayFigure::MAX_FIGURE }}" step="1" inputmode="numeric" class="form-control form-control-sm report-typed-in-input" aria-label="Number of expected RFQ, {{ $row['user']->name }}">
                                @else
                                    {{ $typedIn($row['expected_rfqs']) }}
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($row['received']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if ($canEditFigures)
        <div class="d-flex justify-content-end mb-3 report-save">
            <button type="submit" form="sourcing-figures-form" class="btn btn-primary"><i class="bi bi-check2"></i> Save figures</button>
        </div>
    @elseif ($isRange && auth()->user()->canEditSourcingFigures())
        <p class="text-muted-soft small report-save"><i class="bi bi-info-circle"></i> The yellow figures are added up over these days. Pick a single day to change them.</p>
    @endif

    @include('admin.reports.performance._how_counted', ['points' => [
        'An RFQ counts once for each person who had a part of it, and once in the total — its items are the RFQ\'s number of items.',
        'Finalized: the RFQs the person finalized (Sourcing\'s Finalize) in these dates.',
        'Pending: at the end of the last day, the RFQs they\'d been given and not yet finalized — those waiting for the customer\'s clarification are left out and counted on their own, and cancelled ones aren\'t counted.',
        'Received: the RFQs that first reached them in these dates.',
        'Yellow figures are typed in, not worked out: each Sourcing member enters their supplier calls on their dashboard, and Senior Operations sets the expected RFQs here. A dash means nothing has been entered.',
    ]])
@endsection
