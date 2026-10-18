{{--
    A column heading that sorts the report by it — again to turn the order
    round. Counts start from the most, names and dates from the first.

    Expects: $key, $label, $sort, $descending. Optional: $class, $title.
--}}
@php
    $isSorted = $sort === $key;
    $startsDescending = in_array($key, ['rfqs', 'items'], true);
    $nextDescending = $isSorted ? ! $descending : $startsDescending;
@endphp
<th class="{{ $class ?? '' }}" @isset($title) title="{{ $title }}" @endisset @if ($isSorted) aria-sort="{{ $descending ? 'descending' : 'ascending' }}" @endif>
    <a href="{{ request()->fullUrlWithQuery(['sort' => $key, 'dir' => $nextDescending ? 'desc' : 'asc', 'page' => null]) }}" class="report-sort {{ $isSorted ? 'is-sorted' : '' }}">
        {{ $label }}
        <i class="bi {{ $isSorted ? ($descending ? 'bi-sort-down' : 'bi-sort-up') : 'bi-arrow-down-up' }}"></i>
    </a>
</th>
