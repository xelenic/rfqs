{{--
    Data Entry's Start on a part with them (RfqController::startDataEntry()).
    On at any hour — but their time on it only counts in working hours: at
    lunch or out of hours, hovering it says so, and when time counts again.
    Off only while they've another part running ($myRunningDataEntry — see
    RfqStep::runningDataEntryOf()), till they send that one to finalize. The
    off-hours windows ahead, each with why, go with the page (once), so
    admin.js can change what the hover says as they come and go without a
    reload. A disabled button takes no hover, so the hint sits on a wrapper
    around it.

    Expects: $rfq, $part (the part number). Optional: $myRunningDataEntry
    (from the controller), $backModal (the quick-detail modal it sits in —
    only its own buttons go on).
--}}
@php
    $runningElsewhere = $myRunningDataEntry ?? null;
    $busyHint = $runningElsewhere
        ? "You're already on {$runningElsewhere->rfq->partNumberLabel($runningElsewhere->part_number)} — send it to finalize before starting another."
        : null;
    // The viewer's working hours, own extra hours included — Admin, starting
    // on someone's behalf, goes by everyone's (the server checks the person).
    $hoursOf = auth()->user()->hasRole('Admin') ? null : auth()->id();
    $offHoursReason = \App\Models\Setting::offHoursReason(now(), $hoursOf);
    $onHint = 'Start — your time on it counts from now.';
    $offHint = fn (string $why) => "Start — outside working hours, your time on it counts once they're back. {$why}";
@endphp
@once
    <script type="application/json" id="off-hours">{!! json_encode(\App\Models\Setting::offHoursWindows(now(), 7, $hoursOf)) !!}</script>
@endonce
<form action="{{ route('admin.rfqs.start-data-entry', $rfq) }}" method="POST" class="d-inline-flex align-items-center gap-2">
    @csrf
    @method('PATCH')
    <input type="hidden" name="part" value="{{ $part }}">
    @include('admin.rfqs._acting_as', ['role' => 'Data Entry'])
    <span @class(['d-inline-block start-hint js-start-hint', 'is-off' => $busyHint]) tabindex="0"
          data-on-hint="{{ $onHint }}" @if ($busyHint) data-busy-hint="{{ $busyHint }}" @endif
          data-start-hint="{{ $busyHint ?? ($offHoursReason ? $offHint($offHoursReason) : $onHint) }}">
        <button type="submit" class="btn btn-sm btn-primary js-start-button" @disabled($busyHint)>
            <i class="bi bi-play-fill"></i> Start
        </button>
    </span>
</form>
