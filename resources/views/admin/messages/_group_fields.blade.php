{{--
    A group chat's name, what it's for and who's in it — in the New group or
    Edit group popup (messages/groups): the people ticked from everyone
    active, searched by name or role, and — setting one up — whether the
    Admin's in it themselves.

    Expects: $prefix (the fields' id prefix), $bag (the popup's error bag),
    $people, $name, $description, $members (the ids ticked), $includeMe
    (null to leave the Include me box out — editing, where the Admin is one
    of the people).
--}}
<div class="mb-3">
    <label for="{{ $prefix }}-name" class="form-label">Name</label>
    <input type="text" name="name" id="{{ $prefix }}-name" maxlength="100" required
           class="form-control @error('name', $bag) is-invalid @enderror" value="{{ $name }}" placeholder="e.g. Pumps project team">
    @error('name', $bag)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>
<div class="mb-3">
    <label for="{{ $prefix }}-description" class="form-label">What it's for <span class="text-muted-soft fw-normal">(optional)</span></label>
    <input type="text" name="description" id="{{ $prefix }}-description" maxlength="255"
           class="form-control @error('description', $bag) is-invalid @enderror" value="{{ $description }}" placeholder="e.g. Sourcing and Data Entry on the pumps RFQs">
    @error('description', $bag)
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="d-flex align-items-baseline justify-content-between">
    <label class="form-label" for="{{ $prefix }}-search">Who's in it</label>
    <span class="small text-muted-soft js-people-picked">{{ count($members) === 1 ? '1 picked' : count($members).' picked' }}</span>
</div>
<input type="search" id="{{ $prefix }}-search" class="form-control form-control-sm mb-2 js-people-filter" placeholder="Search by name or role…" autocomplete="off">
<div class="new-message-people @if ($errors->getBag($bag)->has('members') || $errors->getBag($bag)->has('members.*')) is-invalid @endif" aria-label="Who's in it">
    @foreach ($people as $person)
        @php $personRoles = $person->roles->pluck('name')->sort()->implode(', '); @endphp
        <label class="new-message-person" data-person="{{ $person->id }}" data-search="{{ mb_strtolower($person->name.' '.$personRoles) }}">
            <input type="checkbox" name="members[]" value="{{ $person->id }}" class="form-check-input" @checked(in_array($person->id, $members, true))>
            <x-avatar :user="$person" class="assignee-avatar" />
            <span class="new-message-person-name">{{ $person->name }}{{ $person->is(auth()->user()) ? ' (you)' : '' }}</span>
            @if ($personRoles !== '')
                <span class="badge badge-soft-secondary rfq-comment-role">{{ $personRoles }}</span>
            @endif
        </label>
    @endforeach
    <p class="text-muted-soft small text-center my-3 d-none js-people-none">No one matches that.</p>
</div>
@if ($errors->getBag($bag)->has('members') || $errors->getBag($bag)->has('members.*'))
    <div class="invalid-feedback d-block">{{ $errors->getBag($bag)->first('members') ?: $errors->getBag($bag)->first('members.*') }}</div>
@endif

@if ($includeMe !== null)
    <div class="form-check mt-3">
        <input type="hidden" name="include_me" value="0">
        <input class="form-check-input" type="checkbox" name="include_me" value="1" id="{{ $prefix }}-include-me" @checked($includeMe)>
        <label class="form-check-label" for="{{ $prefix }}-include-me">Include me — I'm in the group too</label>
    </div>
@endif
