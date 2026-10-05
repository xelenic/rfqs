{{--
    How one RFQ stands against Sourcing's deadline (Rfq::sourcingDeadline()):
    the time left on a round still open, or how far past it is; else whether
    its finished rounds ran late or were on time.

    Expects: $deadline (null if Sourcing hasn't had it yet).
--}}
@if ($deadline === null)
    <span class="text-muted-soft">—</span>
@elseif ($deadline['remaining'] !== null)
    <span class="badge {{ \App\Models\Setting::countdownBadgeClass($deadline['remaining'], $deadline['target']) }}"
          title="Working time left on Sourcing's open round, against its {{ \App\Models\Setting::hoursLabel(intdiv($deadline['target'], 60)) }} target">
        <i class="bi bi-stopwatch"></i> {{ \App\Models\Setting::countdownLabel($deadline['remaining']) }}
    </span>
    @if ($deadline['late_rounds'] > 0)
        <div class="small text-danger">Earlier round {{ \App\Models\Setting::durationLabel($deadline['late_by']) }} late</div>
    @endif
@elseif ($deadline['late_rounds'] > 0)
    <span class="badge badge-soft-danger" title="Sourcing finished past its {{ \App\Models\Setting::hoursLabel(intdiv($deadline['target'], 60)) }} target">
        <i class="bi bi-exclamation-triangle"></i> Finished {{ \App\Models\Setting::durationLabel($deadline['late_by']) }} late
    </span>
@else
    <span class="badge badge-soft-success"><i class="bi bi-check2"></i> On time</span>
@endif
