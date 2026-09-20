{{--
    The filters above Senior Operations' Unassigned and Assigned tabs: when the
    RFQ was created, priority, category, who has a part and where that part
    stands, and how to sort. A plain GET form that applies itself as you change
    it (admin.js, #opsFilterForm), carrying the search box, the tab you're on
    and — for Admin on this page — the role. See
    RfqController::operationsFilters().

    Expects: $opsFilters, $jobCategories, $sourcingUsers, $search, $lensRole,
    $opsClearUrl.
--}}
@php
    $rangeLabels = ['all' => 'All time']
        + collect(\App\Http\Controllers\Admin\RfqController::OPERATIONS_RANGES)
            ->mapWithKeys(fn (int $days, string $key) => [$key => $days === 1 ? 'Today' : $days.' days'])->all()
        + ['custom' => 'Custom'];
@endphp
<form method="GET" action="{{ route('admin.rfqs.index') }}" id="opsFilterForm" class="ops-filters">
    <input type="hidden" name="status" value="Pending">
    @if ($lensRole)
        <input type="hidden" name="role" value="{{ \Illuminate\Support\Str::slug($lensRole) }}">
    @endif
    @if ($search !== '')
        <input type="hidden" name="search" value="{{ $search }}">
    @endif
    <input type="hidden" name="tab" id="opsFilterTab" value="{{ $opsFilters['tab'] }}">

    <div class="ops-filters-head">
        <span class="ops-filters-title"><i class="bi bi-funnel"></i> Filter</span>
        @if ($opsFilters['count'] > 0)
            <span class="badge badge-soft-primary">{{ $opsFilters['count'] }} active</span>
            <a href="{{ $opsClearUrl }}" class="ops-filters-clear"><i class="bi bi-x-circle"></i> Clear filters</a>
        @endif
    </div>

    <div class="ops-filter-row">
        <span class="ops-filter-label" id="ops-range-label">Created</span>
        <div class="range-filter ops-range" role="radiogroup" aria-labelledby="ops-range-label">
            @foreach ($rangeLabels as $value => $label)
                <label class="range-filter-btn">
                    <input type="radio" name="range" value="{{ $value }}" @checked($opsFilters['range'] === $value)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>

        <div class="ops-custom-range {{ $opsFilters['range'] === 'custom' ? '' : 'd-none' }}" id="opsCustomRange">
            <input type="date" name="from" class="form-control form-control-sm" value="{{ $opsFilters['from'] }}"
                   max="{{ $opsFilters['to'] ?? now()->toDateString() }}" aria-label="Created from">
            <span class="text-muted-soft">to</span>
            <input type="date" name="to" class="form-control form-control-sm" value="{{ $opsFilters['to'] }}"
                   min="{{ $opsFilters['from'] }}" max="{{ now()->toDateString() }}" aria-label="Created up to">
        </div>
    </div>

    <div class="ops-filter-row">
        <span class="ops-filter-label" id="ops-priority-label">Priority</span>
        <div class="ops-chips" role="group" aria-labelledby="ops-priority-label">
            @foreach (array_reverse(\App\Models\Rfq::PRIORITIES) as $priority)
                <label class="priority-chip priority-chip-{{ strtolower($priority) }}">
                    <input type="checkbox" name="priority[]" value="{{ $priority }}" @checked(in_array($priority, $opsFilters['priority'], true))>
                    <span class="priority-chip-dot"></span>
                    <span>{{ $priority }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <div class="ops-filter-selects">
        <label class="ops-select">
            <span>Category</span>
            <select name="category" class="form-select form-select-sm">
                <option value="">All categories</option>
                @foreach ($jobCategories as $jobCategory)
                    <option value="{{ $jobCategory->name }}" @selected($opsFilters['category'] === $jobCategory->name)>{{ $jobCategory->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="ops-select">
            <span>Sourcing member</span>
            <select name="member" class="form-select form-select-sm">
                <option value="">Anyone</option>
                @foreach ($sourcingUsers as $sourcingUser)
                    <option value="{{ $sourcingUser->id }}" @selected($opsFilters['member'] === $sourcingUser->id)>{{ $sourcingUser->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="ops-select">
            <span>Part status</span>
            <select name="part_status" class="form-select form-select-sm">
                <option value="">Any status</option>
                @foreach (\App\Models\RfqAssignment::PROGRESS_LABELS as $state => $label)
                    <option value="{{ $state }}" @selected($opsFilters['part_status'] === $state)>{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <label class="ops-select">
            <span>Sort by</span>
            <select name="sort" class="form-select form-select-sm">
                @foreach (\App\Http\Controllers\Admin\RfqController::OPERATIONS_SORTS as $value => $label)
                    <option value="{{ $value }}" @selected($opsFilters['sort'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
    </div>

    <noscript>
        <button type="submit" class="btn btn-sm btn-primary mt-3">Apply filters</button>
    </noscript>
</form>
