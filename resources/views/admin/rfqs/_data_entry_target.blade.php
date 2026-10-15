{{--
    A part's time with Data Entry, for their list's Time Left column: once
    started, its countdown (_countdown_badge); till then, the whole target,
    still to run. Under it, how the target is made up — per item, its items
    times the time an item takes (Rfq::dataEntryTargetBreakdown()); else its
    priority's (Settings → Data Entry Targets).

    Expects: $rfq, $part, $countdown (Rfq::dataEntryCountdown(), or null),
    $workerId (whoever started it, or null).
--}}
@php
    $target = $rfq->dataEntryTargetSeconds($part);
    $breakdown = $rfq->dataEntryTargetBreakdown($part);
    $perItemOn = \App\Models\Setting::dataEntryPerItem()['enabled'];
    $minutesLabel = fn (int $seconds) => rtrim(rtrim(number_format($seconds / 60, 1), '0'), '.').' min';
@endphp
@if ($countdown)
    @include('admin.rfqs._countdown_badge', ['countdown' => $countdown, 'title' => 'Working time left to send it to finalize', 'workerId' => $workerId])
@else
    <span class="badge badge-soft-secondary de-target" title="Counts down from Start, in working hours">
        <i class="bi bi-stopwatch"></i> {{ \App\Models\Setting::durationLabel($target) }}
    </span>
@endif
<div class="de-target-formula">
    @if ($breakdown)
        {{ number_format($breakdown['items']) }} {{ \Illuminate\Support\Str::plural('item', $breakdown['items']) }} × {{ $minutesLabel($breakdown['perItem']) }} = {{ \App\Models\Setting::durationLabel($breakdown['seconds']) }}
    @elseif ($perItemOn)
        No number of items — {{ $rfq->priority_level }}'s target
    @else
        {{ $rfq->priority_level }}'s target
    @endif
    @unless ($countdown)
        · from Start
    @endunless
</div>
