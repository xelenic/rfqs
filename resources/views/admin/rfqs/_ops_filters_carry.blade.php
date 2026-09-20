{{--
    Hidden copies of Senior Operations' current filters for the search box
    that sits beside them, so searching keeps them (and the tab you're on).
    See _ops_filters.blade.php. Expects: $opsFilters.
--}}
<input type="hidden" name="tab" value="{{ $opsFilters['tab'] }}">
@if ($opsFilters['range'] !== 'all')
    <input type="hidden" name="range" value="{{ $opsFilters['range'] }}">
    @if ($opsFilters['from'])
        <input type="hidden" name="from" value="{{ $opsFilters['from'] }}">
    @endif
    @if ($opsFilters['to'])
        <input type="hidden" name="to" value="{{ $opsFilters['to'] }}">
    @endif
@endif
@foreach ($opsFilters['priority'] as $priority)
    <input type="hidden" name="priority[]" value="{{ $priority }}">
@endforeach
@foreach (['category', 'member', 'part_status'] as $field)
    @if ($opsFilters[$field] !== null)
        <input type="hidden" name="{{ $field }}" value="{{ $opsFilters[$field] }}">
    @endif
@endforeach
@if ($opsFilters['sort'] !== 'newest')
    <input type="hidden" name="sort" value="{{ $opsFilters['sort'] }}">
@endif
