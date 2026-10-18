{{--
    Who a person report covers: one, several, or — none picked — everyone
    holding the role (deactivated accounts too, for the work they did).

    Expects: $everyone, $chosen (ids), $role (what they're called).
--}}
<div class="ops-filter-row">
    <span class="ops-filter-label" id="report-people-label">{{ $role }}</span>
    <div class="ops-chips" role="group" aria-labelledby="report-people-label">
        @forelse ($everyone as $person)
            <label class="report-chip">
                <input type="checkbox" name="people[]" value="{{ $person->id }}" @checked(count($chosen) < $everyone->count() && in_array($person->id, $chosen, true))>
                <span>{{ $person->name }}{{ $person->isDeactivated() ? ' (deactivated)' : '' }}</span>
            </label>
        @empty
            <span class="text-muted-soft small">Nobody holds the {{ $role }} role yet.</span>
        @endforelse
    </div>
    @if ($everyone->isNotEmpty())
        <span class="text-muted-soft small">{{ count($chosen) < $everyone->count() ? count($chosen).' of '.$everyone->count().' picked' : 'Everyone — pick people to narrow it' }}</span>
    @endif
</div>
