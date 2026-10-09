{{--
    Data Entry's Start on a part with them — their time on it counts from
    here (RfqController::startDataEntry()). Only in working hours: off at
    lunch and out of hours, here and on the server. And one part at a time:
    off while they've another running ($myRunningDataEntry — see
    RfqStep::runningDataEntryOf()), till they send that one to finalize.
    Hovering it says why it's off — which part's running, or a day off,
    lunch, before or after hours, and when it's back. The off-hours windows
    ahead, each with why, go with the page (once), so admin.js can turn it
    on and off, and change why, as they come and go without a reload. A
    disabled button takes no hover, so the hint sits on a wrapper around it.

    Expects: $rfq, $part (the part number). Optional: $myRunningDataEntry
    (from the controller), $backModal (the quick-detail modal it sits in —
    only its own buttons go on).
--}}
@php
    $runningElsewhere = $myRunningDataEntry ?? null;
    $busyHint = $runningElsewhere
        ? "You're already on {$runningElsewhere->rfq->partNumberLabel($runningElsewhere->part_number)} — send it to finalize before starting another."
        : null;
    $offHoursReason = \App\Models\Setting::offHoursReason(now());
    $onHint = 'Start — your time on it counts from now.';
@endphp
@once
    <script type="application/json" id="off-hours">{!! json_encode(\App\Models\Setting::offHoursWindows(now())) !!}</script>
@endonce
<form action="{{ route('admin.rfqs.start-data-entry', $rfq) }}" method="POST" class="d-inline-flex align-items-center gap-2">
    @csrf
    @method('PATCH')
    <input type="hidden" name="part" value="{{ $part }}">
    @include('admin.rfqs._acting_as', ['role' => 'Data Entry'])
    <span @class(['d-inline-block start-hint js-start-hint', 'is-off' => $busyHint || $offHoursReason]) tabindex="0"
          data-on-hint="{{ $onHint }}" @if ($busyHint) data-busy-hint="{{ $busyHint }}" @endif
          data-start-hint="{{ $busyHint ?? $offHoursReason ?? $onHint }}">
        <button type="submit" class="btn btn-sm btn-primary js-working-hours-only" @disabled($busyHint || $offHoursReason)>
            <i class="bi bi-play-fill"></i> Start
        </button>
    </span>
</form>
