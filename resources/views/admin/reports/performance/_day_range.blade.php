{{--
    The days a daily report covers — from and to, with quick picks — up to
    App\PerformanceReports::MAX_DAYS, no later than today.

    Expects: $from, $to.
--}}
@php
    $today = \Carbon\CarbonImmutable::parse(\App\Models\Setting::today());
    $presets = [
        'Today' => [$today, $today],
        'Yesterday' => [$today->subDay(), $today->subDay()],
        'This week' => [$today->startOfWeek(), $today],
        'Last 7 days' => [$today->subDays(6), $today],
        'This month' => [$today->startOfMonth(), $today],
        'Last month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
    ];
@endphp
<div class="ops-filter-row">
    <span class="ops-filter-label">Dates</span>
    <div class="ops-custom-range">
        <input type="date" name="from" class="form-control form-control-sm" value="{{ $from }}" max="{{ $today->toDateString() }}" aria-label="From">
        <span class="text-muted-soft">to</span>
        <input type="date" name="to" class="form-control form-control-sm" value="{{ $to }}" max="{{ $today->toDateString() }}" aria-label="To">
    </div>
    <div class="d-flex flex-wrap gap-1">
        @foreach ($presets as $label => [$presetFrom, $presetTo])
            @php $isPreset = $presetFrom->toDateString() === $from && $presetTo->toDateString() === $to; @endphp
            <a href="{{ request()->fullUrlWithQuery(['from' => $presetFrom->toDateString(), 'to' => $presetTo->toDateString()]) }}"
               @class(['btn btn-sm', 'btn-primary' => $isPreset, 'btn-outline-secondary' => ! $isPreset])>{{ $label }}</a>
        @endforeach
    </div>
</div>
