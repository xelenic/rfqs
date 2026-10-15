{{--
    A person's round picture: their profile photo (User::avatarUrl()), or
    their initial without one — or "?" for nobody. The circle's size and
    colours come from the class it's given (assignee-avatar, user-avatar…);
    .has-photo fills it with the photo.
--}}
@props(['user'])
@php
    $photo = $user?->avatarUrl();
    $otherAttributes = trim((string) $attributes->except('class'));
@endphp
<span class="{{ trim($attributes->get('class').($photo !== null ? ' has-photo' : '')) }}"{!! $otherAttributes !== '' ? ' '.$otherAttributes : '' !!}>@if ($photo)<img src="{{ $photo }}" alt="" loading="lazy" decoding="async">@else{{ $user?->initial() ?? '?' }}@endif</span>
