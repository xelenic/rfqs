{{--
    A person on a sheet's page: their photo and name — leading to their own
    page for those who can open it — showing their time off on hover
    (_time_off_cards), and whether they've been deactivated since.

    Expects: $line (an Attendance, with user), $canSeePeople.
--}}
@if ($line->user && $canSeePeople)
    <a href="{{ route('admin.users.show', $line->user) }}" class="dash-person" data-hover-card="time-off-{{ $line->user_id }}">
        <x-avatar :user="$line->user" class="dash-avatar" />
        <span class="text-truncate time-off-name">{{ $line->user->name }}</span>
    </a>
@elseif ($line->user)
    <span class="dash-person" tabindex="0" role="button" data-hover-card="time-off-{{ $line->user_id }}">
        <x-avatar :user="$line->user" class="dash-avatar" />
        <span class="text-truncate time-off-name">{{ $line->user->name }}</span>
    </span>
@else
    <span class="text-muted-soft">Unknown</span>
@endif
@if ($line->user?->isDeactivated())
    <span class="badge badge-soft-secondary">Deactivated</span>
@endif
