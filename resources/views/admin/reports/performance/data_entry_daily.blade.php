{{--
    Data Entry's daily report (see Admin\PerformanceReportController::
    dataEntryDaily(), App\PerformanceReports::dataEntryDaily()): for each
    person picked, each working day in the range, the RFQs they completed
    (sent to finalize), those RFQs' items, the line items they added, and
    their working time on Data Entry. Each person's figures across the range
    follow when it's more than a day; the total, every RFQ once, at the bottom.

    Expects: $report (rows, people, total), $everyone, $chosen, $from, $to,
    $clamped, $sort, $descending.
--}}
@extends('layouts.app')

@section('title', 'Data Entry Daily Report')

@section('content')
    @include('admin.reports.performance._nav')

    @php
        $dayLabel = fn (string $day) => \Carbon\CarbonImmutable::parse($day)->format('d-m-Y');
        $isRange = $from !== $to;
        $time = fn (int $seconds) => $seconds > 0 ? \App\Models\Setting::durationLabel($seconds) : '—';
    @endphp

    <div class="card mb-3">
        <form method="GET" action="{{ route('admin.reports.data-entry-daily') }}" class="ops-filters report-filters">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $descending ? 'desc' : 'asc' }}">
            <div class="ops-filters-head">
                <span class="ops-filters-title"><i class="bi bi-funnel"></i> Data entry report</span>
                @if (request()->hasAny(['people', 'from', 'to']))
                    <a href="{{ route('admin.reports.data-entry-daily') }}" class="ops-filters-clear"><i class="bi bi-x-circle"></i> Clear filters</a>
                @endif
            </div>
            @include('admin.reports.performance._people_filter', ['role' => 'Data Entry'])
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
            <span class="text-muted-soft small">Time spent: working time on Data Entry that day</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 report-table">
                <thead>
                    <tr>
                        @include('admin.reports.performance._sort_th', ['key' => 'name', 'label' => 'Name'])
                        @include('admin.reports.performance._sort_th', ['key' => 'date', 'label' => 'Date'])
                        @include('admin.reports.performance._sort_th', ['key' => 'rfqs', 'label' => 'Completed RFQs', 'class' => 'text-end'])
                        @include('admin.reports.performance._sort_th', ['key' => 'items', 'label' => 'No. items in completed RFQs', 'class' => 'text-end'])
                        <th class="text-end">Line items added</th>
                        <th class="text-end">Time spent</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['rows'] as $row)
                        <tr>
                            <td class="fw-semibold text-nowrap">{{ $row['user']->name }}</td>
                            <td class="text-nowrap">{{ $dayLabel($row['date']) }} <span class="text-muted-soft small">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('D') }}</span></td>
                            <td class="text-end">{{ number_format($row['completed']) }}</td>
                            <td class="text-end">{{ number_format($row['items']) }}</td>
                            <td class="text-end">{{ number_format($row['line_items']) }}</td>
                            <td class="text-end text-nowrap">{{ $time($row['seconds']) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted-soft py-4">No working days for the people picked in these dates.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        <th colspan="2">Total <div class="text-muted-soft small fw-normal">Each RFQ once</div></th>
                        <th class="text-end">{{ number_format($report['total']['completed']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['items']) }}</th>
                        <th class="text-end">{{ number_format($report['total']['line_items']) }}</th>
                        <th class="text-end text-nowrap">{{ $time($report['total']['seconds']) }}</th>
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
                            <th class="text-end">Completed RFQs</th>
                            <th class="text-end">No. items in completed RFQs</th>
                            <th class="text-end">Line items added</th>
                            <th class="text-end">Time spent</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['people'] as $summary)
                            <tr>
                                <td class="fw-semibold text-nowrap">{{ $summary['user']->name }}</td>
                                <td class="text-end">{{ number_format($summary['completed']) }}</td>
                                <td class="text-end">{{ number_format($summary['items']) }}</td>
                                <td class="text-end">{{ number_format($summary['line_items']) }}</td>
                                <td class="text-end text-nowrap">{{ $time($summary['seconds']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    @include('admin.reports.performance._how_counted', ['points' => [
        'Completed: the RFQs the person sent to finalize (Data Entry\'s Send to Finalize) that day — each once, however many parts.',
        'No. items: the completed RFQs\' number of items. Line items added: what they entered when sending each part to finalize.',
        'Time spent: their working time on Data Entry that day, from Start to Send to Finalize, less any pause — only working hours count, their own extra hours included.',
        'Each working day of each person is listed, and any other day they worked on Data Entry.',
    ]])
@endsection
