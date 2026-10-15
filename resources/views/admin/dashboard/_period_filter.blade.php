{{--
    The period a dashboard covers — Today, 7 / 30 / 90 days, or dates of
    your own (DashboardController::ADMIN_PERIODS, summaryPeriod()): a preset
    goes straight there; Custom waits for its dates (admin.js,
    .js-period-filter). Admin's and Data Entry's dashboards.

    Expects: $period (summaryPeriod()), $note (what the period scopes, and
    what it doesn't).
--}}
@php
    $todayDate = now(\App\Models\Setting::timezone())->toDateString();
    $periodLabels = collect(\App\Http\Controllers\Admin\DashboardController::ADMIN_PERIODS)->map->label->all() + ['custom' => 'Custom'];
    $isCustomPeriod = $period['key'] === 'custom';
@endphp
<form method="GET" action="{{ route('admin.dashboard') }}" id="dashboardPeriodForm" class="dashboard-period js-period-filter"
      data-period-field="period" data-period-default="{{ \App\Http\Controllers\Admin\DashboardController::ADMIN_DEFAULT_PERIOD }}" data-today="{{ $todayDate }}">
    <span class="dashboard-period-label"><i class="bi bi-calendar3"></i> Period</span>
    <div class="range-filter ops-range" role="radiogroup" aria-label="Dashboard period">
        @foreach ($periodLabels as $value => $label)
            <label class="range-filter-btn">
                <input type="radio" name="period" value="{{ $value }}" @checked($period['key'] === $value)>
                <span>{{ $label }}</span>
            </label>
        @endforeach
    </div>
    <div class="ops-custom-range js-custom-range {{ $isCustomPeriod ? '' : 'd-none' }}">
        <input type="date" name="from" class="form-control form-control-sm" aria-label="From"
               value="{{ $isCustomPeriod ? $period['from'] : '' }}" max="{{ $isCustomPeriod ? $period['to'] : $todayDate }}">
        <span class="text-muted-soft">to</span>
        <input type="date" name="to" class="form-control form-control-sm" aria-label="To"
               value="{{ $isCustomPeriod ? $period['to'] : '' }}" min="{{ $isCustomPeriod ? $period['from'] : '' }}" max="{{ $todayDate }}">
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
    </div>
    <span class="dashboard-period-dates">
        <strong>{{ $period['dates'] }}</strong>
        <span class="text-muted-soft">· {{ $note }}</span>
    </span>
</form>
