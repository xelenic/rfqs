{{--
    The RFQ activity block: the time-range filter, the chart of RFQs created
    over time — still pending versus done — and its table twin, and
    optionally the selected period's two totals.

    Expects: $rfqActivity. Optional: $showTotals (default true) and
    $completedLabel (default "Completed") — what the finished series is called.
--}}
@php
    $completedLabel = $completedLabel ?? 'Completed';
@endphp
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <h2 class="h6 fw-bold mb-0">RFQ Activity</h2>

    <div class="range-filter" role="group" aria-label="Time range">
        @foreach (\App\Http\Controllers\Admin\DashboardController::RANGES as $r)
            @php
                $rangeLabel = match ($r) {
                    1 => '1 Day',
                    7 => '7 Days',
                    30 => '1 Month',
                    90 => '3 Months',
                };
            @endphp
            <a href="{{ route('admin.dashboard', ['range' => $r]) }}"
               class="range-filter-btn {{ $rfqActivity['range'] === $r ? 'active' : '' }}">
                {{ $rangeLabel }}
            </a>
        @endforeach
    </div>
</div>

@if ($showTotals ?? true)
    <div class="row g-3 mb-3">
        <div class="col-sm-6 col-xl-6">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">Pending RFQs</div>
                    <div class="stat-value">{{ $rfqActivity['pendingTotal'] }}</div>
                </div>
                <div class="stat-icon stat-icon-warning"><i class="bi bi-hourglass-split"></i></div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-6">
            <div class="stat-card d-flex align-items-center justify-content-between">
                <div>
                    <div class="stat-label">{{ $completedLabel }} RFQs</div>
                    <div class="stat-value">{{ $rfqActivity['completedTotal'] }}</div>
                </div>
                <div class="stat-icon stat-icon-success"><i class="bi bi-check2-circle"></i></div>
            </div>
        </div>
    </div>
@endif

<div class="card mb-4">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>RFQs created over time</span>
        <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-table"
                data-target="rfqActivityTable" data-chart-target="rfqActivityChart">
            <i class="bi bi-table"></i> View as table
        </button>
    </div>
    <div class="card-body">
        <div id="rfqActivityChart" class="rfq-chart" data-chart="{{ json_encode($rfqActivity + ['seriesLabels' => ['completed' => $completedLabel]]) }}"></div>

        <div id="rfqActivityTable" class="table-responsive d-none">
            <table class="table table-sm mb-0">
                <thead>
                    <tr>
                        <th>Period</th>
                        <th>Pending</th>
                        <th>{{ $completedLabel }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rfqActivity['labels'] as $i => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            <td>{{ $rfqActivity['pending'][$i] }}</td>
                            <td>{{ $rfqActivity['completed'][$i] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
