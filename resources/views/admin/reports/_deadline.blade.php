{{--
    How one RFQ stands against Sourcing's deadline (Rfq::sourcingDeadline()) —
    each part's time with its member, every round added up: the time left on
    a part still with them, or how far past it is; else whether the parts
    they finished ran late or were on time.

    Expects: $deadline (null if Sourcing hasn't had it yet).
--}}
@if ($deadline === null)
    <span class="text-muted-soft">—</span>
@elseif ($deadline['remaining'] !== null)
    <span class="badge {{ \App\Models\Setting::countdownBadgeClass($deadline['remaining'], $deadline['target']) }}"
          title="Working time Sourcing has left on it, every round counted, against its {{ \App\Models\Setting::hoursLabel(intdiv($deadline['target'], 60)) }} target">
        <i class="bi bi-stopwatch"></i> {{ \App\Models\Setting::countdownLabel($deadline['remaining']) }}
    </span>
    @if ($deadline['late_parts'] > 0)
        <div class="small text-danger">A finished part {{ \App\Models\Setting::durationLabel($deadline['late_by']) }} late</div>
    @endif
@elseif ($deadline['late_parts'] > 0)
    <span class="badge badge-soft-danger" title="Sourcing finished past its {{ \App\Models\Setting::hoursLabel(intdiv($deadline['target'], 60)) }} target">
        <i class="bi bi-exclamation-triangle"></i> Finished {{ \App\Models\Setting::durationLabel($deadline['late_by']) }} late
    </span>
@else
    <span class="badge badge-soft-success"><i class="bi bi-check2"></i> On time</span>
@endif
