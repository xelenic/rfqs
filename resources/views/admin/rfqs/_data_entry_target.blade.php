{{--
    A part's time with Data Entry, for their list's Time Left column — just
    the time: once started, its countdown (_countdown_badge) — held where it
    stopped while they've paused it; till then, the whole target, still to
    run. How the target is made up — the line items to add (or the RFQ's
    items) times the time an item takes (Rfq::dataEntryTargetBreakdown()), or
    its priority's — is on hover. See Settings → Data Entry Time.

    Expects: $rfq, $part, $countdown (Rfq::dataEntryCountdown(), or null),
    $workerId (whoever started it, or null). Optional: $pausedCountdown
    (Rfq::pausedDataEntryCountdown()).
--}}
@php
    $pausedCountdown ??= null;
    $target = $rfq->dataEntryTargetSeconds($part);
    $breakdown = $rfq->dataEntryTargetBreakdown($part);
    $perItemOn = \App\Models\Setting::dataEntryPerItem()['enabled'];
    $itemWord = ($breakdown['source'] ?? 'items') === 'line_items' ? 'line item' : 'item';
    $minutesLabel = fn (int $seconds) => rtrim(rtrim(number_format($seconds / 60, 1), '0'), '.').' min';
@endphp
@php
    // How it's made up, for the hover.
    $madeUp = match (true) {
        $breakdown !== null => number_format($breakdown['items']).' '.\Illuminate\Support\Str::plural($itemWord, $breakdown['items']).' × '.$minutesLabel($breakdown['perItem']).' = '.\App\Models\Setting::durationLabel($breakdown['seconds']),
        $perItemOn => "No number of items — {$rfq->priority_level}'s target",
        default => "{$rfq->priority_level}'s target",
    };
@endphp
@if ($countdown)
    @include('admin.rfqs._countdown_badge', ['countdown' => $countdown, 'title' => "Working time left to send it to finalize · {$madeUp}", 'workerId' => $workerId])
@elseif ($pausedCountdown)
    <span class="badge sourcing-countdown is-on-pause {{ \App\Models\Setting::countdownBadgeClass($pausedCountdown['remaining'], $pausedCountdown['target']) }}" title="Paused — it carries on from here when it's resumed · {{ $madeUp }}">
        <i class="bi bi-pause-fill"></i>
        <span class="sourcing-countdown-label">{{ \App\Models\Setting::countdownLabel($pausedCountdown['remaining']) }}</span>
    </span>
@else
    <span class="badge badge-soft-secondary de-target" title="Counts down from Start, in working hours · {{ $madeUp }}">
        <i class="bi bi-stopwatch"></i> {{ \App\Models\Setting::durationLabel($target) }}
    </span>
@endif
