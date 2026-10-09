{{--
    Data Entry's own Ready for Data Entry page: while parts wait and they've
    nothing running, a countdown to Senior Operations being told they
    haven't started (User::dataEntryIdleness(), the idle alert in Settings),
    ticking to the second in admin.js against the page's
    #countdown-schedule — paused outside working hours, as the alert is.
    Once it's been sent (App\Console\Commands\AlertIdleDataEntry), it says
    so instead; live updates bring that in as it happens.

    Expects: $idle (User::dataEntryIdleness()).
--}}
@php
    $waitingLabel = $idle['waiting'] === 1 ? '1 part is waiting' : "{$idle['waiting']} parts are waiting";
@endphp
@if ($idle['alerted_at'])
    <div class="idle-banner is-alerted mb-3" role="status">
        <span class="idle-banner-icon"><i class="bi bi-bell-fill" aria-hidden="true"></i></span>
        <div class="idle-banner-body">
            <div class="idle-banner-title">Senior Operations has been told you haven't started</div>
            <div class="idle-banner-text">
                We sent them a message at {{ $idle['alerted_at']->setTimezone(\App\Models\Setting::timezone())->format('g:i A') }}
                — {{ $waitingLabel }}. Press Start on one to get going.
            </div>
        </div>
    </div>
@else
    @php
        $remaining = $idle['alert_after'] - $idle['idle_seconds'];
        $isPaused = ! \App\Models\Setting::isWorkingTime(now());
        $state = match (true) {
            $remaining <= 0 => 'due',
            $isPaused => 'paused',
            default => 'running',
        };
        $texts = [
            'running' => 'Start a part before the clock runs out, or Senior Operations gets a message that you haven\'t started.',
            'paused' => 'Paused outside working hours. Start a part once you\'re back, before the clock runs out.',
            'due' => 'Time\'s up — Senior Operations is being told you haven\'t started.',
        ];
    @endphp
    <div @class(['idle-banner js-idle-countdown mb-3', 'is-due' => $state === 'due', 'is-paused' => $state === 'paused', 'is-urgent' => $state === 'running' && $remaining <= $idle['alert_after'] / 4]) role="timer" aria-live="off"
         data-remaining="{{ $remaining }}" data-alert-after="{{ $idle['alert_after'] }}"
         data-running-text="{{ $texts['running'] }}" data-paused-text="{{ $texts['paused'] }}" data-due-text="{{ $texts['due'] }}">
        <span class="idle-banner-icon"><i class="bi bi-hourglass-split" aria-hidden="true"></i></span>
        <div class="idle-banner-body">
            <div class="idle-banner-title">You haven't started anything yet — {{ $waitingLabel }}</div>
            <div class="idle-banner-text js-idle-text" data-state="{{ $state }}">{{ $texts[$state] }}</div>
            <div class="idle-banner-track" aria-hidden="true">
                <div class="idle-banner-bar js-idle-bar" style="width: {{ max(0, min(100, round($remaining / max(1, $idle['alert_after']) * 100))) }}%"></div>
            </div>
        </div>
        <span class="idle-banner-clock js-idle-clock">{{ \App\Models\Setting::clockLabel($remaining) }}</span>
    </div>
@endif
