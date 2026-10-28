{{--
    The bar above every RFQ list: search (WC number, RFQ number, subject),
    when the RFQs were created — all time, today, the last 3 days, the last
    month (30 days), or dates of your own (RfqController::createdRange()) —
    and their priority, one or several — and, on a plain list, what to group
    it by (WC number, priority, status: RfqController::LIST_GROUPS). A preset
    applies as it's picked, Custom once its dates are, a priority after a
    moment (so a few clicks go as one), a grouping at once, the search on
    Enter (admin.js, .js-period-filter). It carries
    where you are — the list, a role's second view, Admin's role page, Data
    Entry's tab, Senior Operations' filters — so filtering keeps you there.
    On Senior Operations' Unassigned page, whose own filters have the dates
    and priority, it's the search alone. On All RFQs, what to show
    (?show=, RfqController::ALL_RFQS_VIEWS) — where the General Manager's
    dashboard cards lead — keeping the card's dates (?show_from, ?show_to) —
    and its status (?state=, Rfq::STATUS_FILTERS: pending, overdue, due soon,
    on hold, cancelled, closed), where Senior Operations' dashboard cards lead.

    Expects: $statusFilter, $lensRole, $opsFilters, $listRange, $listPriority,
    $search, $scopedToDataEntry, $dataEntryTab, $canGroup, $groupBy,
    $allRfqsViews, $allRfqsView, $allRfqsStatuses and $allRfqsStatus (null but
    on All RFQs).
--}}
@php
    $filterToday = now(\App\Models\Setting::timezone())->toDateString();
    $isCustomRange = $listRange && $listRange['range'] === 'custom';
    $rangeLabels = ['all' => 'All time'] + \App\Http\Controllers\Admin\RfqController::LIST_RANGES + ['custom' => 'Custom'];
    // Senior Operations' own filters have their Clear, which keeps the search.
    $isFiltered = ($listRange && ($search !== '' || $listRange['range'] !== 'all' || $listPriority !== [])) || ($allRfqsView ?? null) !== null || ($allRfqsStatus ?? null) !== null;
@endphp
<form method="GET" action="{{ route('admin.rfqs.index') }}" id="rfqListFilterForm" class="list-filter js-period-filter"
      data-period-field="range" data-period-default="all" data-today="{{ $filterToday }}">
    @if ($statusFilter)
        <input type="hidden" name="status" value="{{ $statusFilter }}">
    @endif
    {{-- Keep the queue being searched: a role's second view
         (Returns, Review, …) and Admin's per-role page. --}}
    @if (in_array(request('view'), \App\Models\Rfq::QUEUE_VIEWS, true))
        <input type="hidden" name="view" value="{{ request('view') }}">
    @endif
    @if ($lensRole)
        <input type="hidden" name="role" value="{{ \Illuminate\Support\Str::slug($lensRole) }}">
    @endif
    @if ($opsFilters)
        @include('admin.rfqs._ops_filters_carry')
    @endif
    {{-- Data Entry's or Sourcing's tab, kept up by admin.js as it changes. --}}
    @if ($scopedToDataEntry)
        <input type="hidden" name="tab" id="deTabInput" value="completed" @disabled($dataEntryTab !== 'completed')>
    @elseif ($sourcingCounts ?? null)
        <input type="hidden" name="tab" id="deTabInput" value="{{ $sourcingTab === 'todo' ? 'with-data-entry' : $sourcingTab }}" @disabled($sourcingTab === 'todo')>
    @endif

    <div class="list-filter-search">
        <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search WC number, RFQ number, subject..." aria-label="Search RFQs">
        <button class="btn btn-sm btn-outline-secondary" type="submit" aria-label="Search"><i class="bi bi-search"></i></button>
    </div>

    @if ($listRange)
        <div class="range-filter ops-range" role="radiogroup" aria-label="Created">
            @foreach ($rangeLabels as $value => $label)
                <label class="range-filter-btn">
                    <input type="radio" name="range" value="{{ $value }}" @checked($listRange['range'] === $value)>
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>

        <div class="ops-custom-range js-custom-range {{ $isCustomRange ? '' : 'd-none' }}">
            <input type="date" name="from" class="form-control form-control-sm" aria-label="Created from"
                   value="{{ $listRange['from'] }}" max="{{ $listRange['to'] ?? $filterToday }}">
            <span class="text-muted-soft">to</span>
            <input type="date" name="to" class="form-control form-control-sm" aria-label="Created up to"
                   value="{{ $listRange['to'] }}" min="{{ $listRange['from'] }}" max="{{ $filterToday }}">
        </div>
    @endif

    @if ($listPriority !== null)
        <div class="ops-chips" role="group" aria-label="Priority">
            @foreach (array_reverse(\App\Models\Rfq::PRIORITIES) as $priority)
                <label class="priority-chip priority-chip-{{ strtolower($priority) }}">
                    <input type="checkbox" name="priority[]" value="{{ $priority }}" @checked(in_array($priority, $listPriority, true))>
                    <span class="priority-chip-dot"></span>
                    <span>{{ \App\Models\Rfq::priorityLabelOf($priority) }}</span>
                </label>
            @endforeach
        </div>
    @endif

    {{-- All RFQs: what to show, and the dates a dashboard card gave it. --}}
    @if ($allRfqsViews ?? null)
        <label class="list-filter-group" title="What to show">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <select name="show" class="form-select form-select-sm" aria-label="Show">
                <option value="">Show: All RFQs</option>
                @foreach ($allRfqsViews as $value => $label)
                    <option value="{{ $value }}" @selected(($allRfqsView['show'] ?? null) === $value)>Show: {{ $label }}</option>
                @endforeach
            </select>
        </label>
        @if ($allRfqsView['from'] ?? null)
            <input type="hidden" name="show_from" value="{{ $allRfqsView['from'] }}">
        @endif
        @if ($allRfqsView['to'] ?? null)
            <input type="hidden" name="show_to" value="{{ $allRfqsView['to'] }}">
        @endif
    @endif

    {{-- All RFQs: their status — overdue and due soon by the tasks running now. --}}
    @if ($allRfqsStatuses ?? null)
        <label class="list-filter-group" title="Status">
            <i class="bi bi-flag" aria-hidden="true"></i>
            <select name="state" class="form-select form-select-sm" aria-label="Status">
                <option value="">Status: All</option>
                @foreach ($allRfqsStatuses as $value => $label)
                    <option value="{{ $value }}" @selected($allRfqsStatus === $value)>Status: {{ $label }}</option>
                @endforeach
            </select>
        </label>
    @endif

    @if ($canGroup)
        <label class="list-filter-group" title="Group the list">
            <i class="bi bi-collection" aria-hidden="true"></i>
            <select name="group" class="form-select form-select-sm" aria-label="Group by">
                <option value="">No grouping</option>
                @foreach (\App\Http\Controllers\Admin\RfqController::LIST_GROUPS as $value => $label)
                    <option value="{{ $value }}" @selected($groupBy === $value)>Group by: {{ $label }}</option>
                @endforeach
            </select>
        </label>
    @endif

    @if ($isFiltered)
        <a href="{{ request()->fullUrlWithoutQuery(['search', 'range', 'from', 'to', 'priority', 'show', 'show_from', 'show_to', 'state', 'page', 'sourcing_page', 'completed_page', 'assigned_page', 'review_page']) }}" class="list-filter-clear">
            <i class="bi bi-x-circle"></i> Clear
        </a>
    @endif
</form>
