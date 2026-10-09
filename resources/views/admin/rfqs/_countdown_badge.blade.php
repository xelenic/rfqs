{{--
    A countdown against a target — Sourcing's (Rfq::sourcingCountdown()) or
    Data Entry's (Rfq::dataEntryCountdown()) — as it stands now, ticked on in
    admin.js against the page's #countdown-schedule: green, then orange in
    its last quarter, red once it's overdue; paused outside working hours.

    Expects: $countdown (['target' => …, 'remaining' => …], seconds), $title
    (what it counts down to, while it's running).
--}}
@php $isPaused = ! \App\Models\Setting::isWorkingTime(now()); @endphp
<span class="badge sourcing-countdown {{ \App\Models\Setting::countdownBadgeClass($countdown['remaining'], $countdown['target']) }} @if ($isPaused) is-paused @endif"
      data-countdown data-remaining="{{ $countdown['remaining'] }}" data-target="{{ $countdown['target'] }}" data-running-title="{{ $title }}"
      title="{{ $isPaused ? 'Paused — outside working hours' : $title }}">
    <i class="bi {{ $isPaused ? 'bi-pause-circle' : 'bi-stopwatch' }}"></i>
    <span class="sourcing-countdown-label">{{ \App\Models\Setting::countdownLabel($countdown['remaining']) }}</span>
</span>
