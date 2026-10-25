{{--
    How a part's time left with GM Assistant comes about — the popup on
    hovering (or focusing) its Time Left (admin.js, [data-hover-card]), laid
    out as the Time Spent report's (_time_breakdown): the target's formula,
    then the line items it goes by — which count, who gave it, in which role
    and when — the time a line item takes, where the countdown stands (not
    started, running from GM Assistant's Start, or paused) and the working
    time used and left (Rfq::gmAssistantTimeExplained()). Where the badge
    ticks on ([data-countdown]), admin.js puts what it says now in
    [data-countdown-left] / [data-countdown-used], and whether it's counting
    now ([data-countdown-off-hours]). Kept in a <template>, so
    it's only drawn on hover.

    Expects: $id (the template's id), $rfq, $part (the part number).
--}}
@php
    $how = $rfq->gmAssistantTimeExplained((int) $part);
    $duration = fn (int $seconds) => \App\Models\Setting::durationLabel($seconds);
    $when = fn (?\Carbon\CarbonInterface $at) => $at?->toBusinessTimezone()->format('M d, Y g:i A');
    $perItem = $how['perItem'] !== null ? rtrim(rtrim(number_format($how['perItem'] / 60, 1), '0'), '.').' min' : null;
    $lineItems = $how['lineItems'];
@endphp
<template id="{{ $id }}">
    <div class="tb">
        <div class="tb-head">
            <strong>GM Assistant · {{ $rfq->partNumberLabel((int) $part) }}</strong>
            <span class="tb-head-figure" data-countdown-left>{{ \App\Models\Setting::countdownLabel($how['remaining']) }}</span>
        </div>

        <div class="tb-formula">
            @if ($how['byPriority'])
                <div><span class="tb-term">Target</span> = {{ $rfq->priorityLabel() }}'s GM Assistant target = <strong>{{ $duration($how['target']) }}</strong></div>
            @else
                <div><span class="tb-term">Target</span> = {{ number_format($lineItems['count']) }} {{ $lineItems['label'] }} × {{ $perItem }} a line item = <strong>{{ $duration($how['target']) }}</strong></div>
            @endif
            <div><span class="tb-term">Time left</span> = target − working time since GM Assistant's Start</div>
            <div><span class="tb-term">Working time</span> = the time inside working hours — lunch, nights, days off and pauses left out</div>
        </div>

        <ul class="tb-stretches">
            <li>
                <div class="tb-stretch-head">
                    <strong>Line items</strong>
                    <strong>{{ $lineItems ? number_format($lineItems['count']) : '—' }}</strong>
                </div>
                @if ($lineItems)
                    <div class="tb-meta">{{ $lineItems['label'] }} · {{ $lineItems['by'] ?? $lineItems['role'] }} ({{ $lineItems['role'] }}){{ $lineItems['at'] ? ' · '.$when($lineItems['at']) : '' }}</div>
                @endif
                @if ($how['byPriority'])
                    <div class="tb-meta">{{ $lineItems ? 'Not finalized by the Head of Business Development, and time per line item is off — so it goes by priority.' : 'No line items to go by — so it goes by priority.' }}</div>
                @elseif ($lineItems['label'] !== 'Finalized Line Items')
                    <div class="tb-meta">No Finalized Line Items from the Head of Business Development — so it goes by these.</div>
                @endif
            </li>
            <li>
                <div class="tb-stretch-head">
                    <strong>Time a line item takes</strong>
                    <strong>{{ $perItem ?? '—' }}</strong>
                </div>
                <div class="tb-meta">{{ $how['byPriority'] ? 'Not used — the target is by priority, Settings → GM Assistant Targets' : 'Settings → GM Assistant Time' }}</div>
            </li>
            <li>
                <div class="tb-stretch-head">
                    <strong>Countdown</strong>
                    <strong>{{ ['waiting' => 'Not started', 'running' => 'Running', 'paused' => 'Paused'][$how['state']] }}</strong>
                </div>
                <div class="tb-meta">
                    @switch($how['state'])
                        @case('waiting')
                            Starts when GM Assistant presses Start — till then it's the whole target.
                            @break
                        @case('paused')
                            Paused {{ $when($how['pausedAt']) }}{{ $how['pauseReason'] ? ': '.$how['pauseReason'] : '' }} — carries on from here when it's resumed.
                            @break
                        @default
                            Started {{ $when($how['startedAt']) }}{{ $how['startedBy'] ? ' by '.$how['startedBy'] : '' }}.
                    @endswitch
                </div>
                @if ($how['state'] === 'running')
                    <div class="tb-meta tb-ooh {{ \App\Models\Setting::isWorkingTime(now(), $rfq->assigneeForPart((int) $part)?->pivot->gm_assistant_started_by) ? 'd-none' : '' }}" data-countdown-off-hours>Not counting now — outside working hours.</div>
                @endif
            </li>
            <li>
                <div class="tb-stretch-head">
                    <span>Working time used</span>
                    <strong data-countdown-used>{{ $duration($how['used']) }}</strong>
                </div>
                <div class="tb-stretch-head">
                    <span>Time left</span>
                    <strong data-countdown-left>{{ \App\Models\Setting::countdownLabel($how['remaining']) }}</strong>
                </div>
            </li>
        </ul>
    </div>
</template>
