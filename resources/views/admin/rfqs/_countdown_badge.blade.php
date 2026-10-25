{{--
    A countdown against a target — Sourcing's (Rfq::sourcingCountdown()) or
    Data Entry's (Rfq::dataEntryCountdown()) — as it stands now, ticked on in
    admin.js against the page's #countdown-schedule: green, then orange in
    its last quarter, red once it's overdue; paused outside working hours.

    Expects: $countdown (['target' => …, 'remaining' => …], seconds), $title
    (what it counts down to, while it's running). Optional: $workerId — whose
    working hours it runs on, own extra hours included (ExtraWorkingHour).
    $hoverCard — true where a hover card around it explains it
    (_gm_assistant_time_card), so it has no title of its own.
--}}
@php
    $workerId ??= null;
    $hoverCard ??= false;
    $isPaused = ! \App\Models\Setting::isWorkingTime(now(), $workerId);
@endphp
<span class="badge sourcing-countdown {{ \App\Models\Setting::countdownBadgeClass($countdown['remaining'], $countdown['target']) }} @if ($isPaused) is-paused @endif"
      data-countdown data-remaining="{{ $countdown['remaining'] }}" data-target="{{ $countdown['target'] }}" data-running-title="{{ $title }}" data-worker="{{ $workerId }}"
      @unless ($hoverCard) title="{{ $isPaused ? 'Paused — outside working hours' : $title }}" @endunless>
    <i class="bi {{ $isPaused ? 'bi-pause-circle' : 'bi-stopwatch' }}"></i>
    <span class="sourcing-countdown-label">{{ \App\Models\Setting::countdownLabel($countdown['remaining']) }}</span>
</span>
