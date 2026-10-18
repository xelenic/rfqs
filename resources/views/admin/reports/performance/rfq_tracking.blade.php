{{--
    The RFQ tracking sheet (see Admin\PerformanceReportController::rfqTracking()):
    every RFQ, by when it came in — its numbers, subject and priority, who in
    Sourcing finalized it, and whether it's open or closed (on hold, cancelled
    or waiting for the customer's clarification noted beside Open). Filtered by
    the dates it came in, RFQ number, WC number, priority, Sourcing member (who
    holds or finalized a part) and status; sorted by date, RFQ number, WC
    number or priority; with how many it lists, and their items, at the bottom.

    Expects: $rfqs (paginated, with assignees), $finalizers (id => name),
    $waitingOnCustomer (RFQ id => bool), $summary (rfqs, closed, items),
    $filters, $sourcing, $sort, $descending.
--}}
@extends('layouts.app')

@section('title', 'RFQ Tracking Sheet')

@section('content')
    @include('admin.reports.performance._nav')

    <div class="card mb-3">
        <form method="GET" action="{{ route('admin.reports.rfq-tracking') }}" class="ops-filters report-filters">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="dir" value="{{ $descending ? 'desc' : 'asc' }}">
            <div class="ops-filters-head">
                <span class="ops-filters-title"><i class="bi bi-funnel"></i> Daily tracking sheet</span>
                @if (request()->hasAny(['from', 'to', 'rfq', 'wc', 'priority', 'person', 'status']))
                    <a href="{{ route('admin.reports.rfq-tracking') }}" class="ops-filters-clear"><i class="bi bi-x-circle"></i> Clear filters</a>
                @endif
            </div>

            <div class="ops-filter-row">
                <span class="ops-filter-label">Date</span>
                <div class="ops-custom-range">
                    <input type="date" name="from" class="form-control form-control-sm" value="{{ $filters['from'] }}" aria-label="Received from">
                    <span class="text-muted-soft">to</span>
                    <input type="date" name="to" class="form-control form-control-sm" value="{{ $filters['to'] }}" aria-label="Received up to">
                </div>
            </div>

            <div class="ops-filter-row">
                <span class="ops-filter-label" id="tracking-priority-label">Priority</span>
                <div class="ops-chips" role="group" aria-labelledby="tracking-priority-label">
                    @foreach (array_reverse(\App\Models\Rfq::PRIORITIES) as $priority)
                        <label class="priority-chip priority-chip-{{ strtolower($priority) }}">
                            <input type="checkbox" name="priority[]" value="{{ $priority }}" @checked(in_array($priority, $filters['priority'], true))>
                            <span class="priority-chip-dot"></span>
                            <span>{{ $priority }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="ops-filter-selects">
                <label class="ops-select">
                    <span>RFQ number</span>
                    <input type="search" name="rfq" class="form-control form-control-sm" value="{{ $filters['rfq'] }}" placeholder="e.g. RFQ1001">
                </label>
                <label class="ops-select">
                    <span>WC number</span>
                    <input type="search" name="wc" class="form-control form-control-sm" value="{{ $filters['wc'] }}" placeholder="e.g. WC-101">
                </label>
                <label class="ops-select">
                    <span>Sourcing member</span>
                    <select name="person" class="form-select form-select-sm">
                        <option value="">Anyone</option>
                        @foreach ($sourcing as $person)
                            <option value="{{ $person->id }}" @selected($filters['person'] === $person->id)>{{ $person->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="ops-select">
                    <span>Status</span>
                    <select name="status" class="form-select form-select-sm">
                        @foreach (['all' => 'Open and closed', 'open' => 'Open', 'closed' => 'Closed'] as $value => $label)
                            <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            <button type="submit" class="btn btn-sm btn-primary mt-2"><i class="bi bi-arrow-repeat"></i> Show sheet</button>
        </form>
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-hover mb-0 report-table">
                <thead>
                    <tr>
                        @include('admin.reports.performance._sort_th', ['key' => 'date', 'label' => 'Date'])
                        @include('admin.reports.performance._sort_th', ['key' => 'rfq', 'label' => 'RFQ Number'])
                        @include('admin.reports.performance._sort_th', ['key' => 'wc', 'label' => 'WC Number'])
                        <th>Subject</th>
                        @include('admin.reports.performance._sort_th', ['key' => 'priority', 'label' => 'Priority'])
                        <th>Finalized by Sourcing</th>
                        <th>RFQ Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rfqs as $rfq)
                        @php
                            $finalizedBy = $rfq->assignees->pluck('pivot.finalized_by')->filter()->unique()->map(fn ($id) => $finalizers[$id] ?? null)->filter();
                            $isClosed = $rfq->isClosed();
                        @endphp
                        <tr>
                            <td class="text-nowrap">{{ $rfq->created_at->toBusinessTimezone()->format('d-m-Y') }}</td>
                            <td class="fw-semibold text-nowrap"><a href="{{ route('admin.rfqs.show', $rfq) }}">{{ $rfq->rfq_number }}</a></td>
                            <td class="text-nowrap">{{ $rfq->wc_number }}</td>
                            <td>{{ $rfq->subject }}</td>
                            <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                            <td>{{ $finalizedBy->isNotEmpty() ? $finalizedBy->join(', ') : '—' }}</td>
                            <td class="text-nowrap">
                                <span class="badge {{ $isClosed ? 'badge-soft-success' : 'badge-soft-primary' }}">{{ $isClosed ? 'Closed' : 'Open' }}</span>
                                @if ($waitingOnCustomer[$rfq->id] ?? false)
                                    <span class="badge badge-soft-orange">Waiting for customer</span>
                                @elseif (in_array($rfq->status, [\App\Models\Rfq::ON_HOLD, \App\Models\Rfq::CANCELLED], true))
                                    <span class="badge badge-soft-secondary">{{ $rfq->status }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted-soft py-4">No RFQs match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="report-total">
                        @php
                            $summaryParts = [
                                number_format($summary['rfqs']).($summary['rfqs'] === 1 ? ' RFQ' : ' RFQs'),
                                number_format($summary['rfqs'] - $summary['closed']).' open',
                                number_format($summary['closed']).' closed',
                                number_format($summary['items']).' '.\Illuminate\Support\Str::plural('item', $summary['items']),
                            ];
                        @endphp
                        <th colspan="7">{{ implode(' · ', $summaryParts) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if ($rfqs->hasPages())
            <div class="card-footer bg-white d-print-none">{{ $rfqs->links() }}</div>
        @endif
    </div>

    @include('admin.reports.performance._how_counted', ['points' => [
        'Date: when the RFQ was received (registered). Finalized by Sourcing: whoever finalized its parts, or — while nobody has yet.',
        'Open: not closed yet, whether it\'s in progress, on hold, cancelled or waiting for the customer\'s clarification. Closed: Business Development closed it.',
        'Sourcing member: RFQs with a part they hold, or finalized.',
    ]])
@endsection
