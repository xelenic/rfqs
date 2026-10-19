{{--
    GM Assistant's Start on a part with them (RfqController::startGmAssistant()),
    as Data Entry's is (_start_data_entry_button): on at any hour, their time
    counting only in working hours — hovering it says so at lunch or out of
    hours (admin.js, from the off-hours windows the page brings once). Off
    only while they've another part running ($myRunningGmAssistant — see
    RfqStep::runningGmAssistantOf()), till they submit or pause it.

    Expects: $rfq, $part. Optional: $myRunningGmAssistant (from the controller).
--}}
@php
    $runningElsewhere = $myRunningGmAssistant ?? null;
    $busyHint = $runningElsewhere
        ? "You're already on {$runningElsewhere->rfq->partNumberLabel($runningElsewhere->part_number)} — submit it, or pause it, before starting another."
        : null;
    $hoursOf = auth()->user()->hasRole('Admin') ? null : auth()->id();
    $offHoursReason = \App\Models\Setting::offHoursReason(now(), $hoursOf);
    $onHint = 'Start — your time on it counts from now.';
    $offHint = fn (string $why) => "Start — outside working hours, your time on it counts once they're back. {$why}";
@endphp
@once
    <script type="application/json" id="off-hours">{!! json_encode(\App\Models\Setting::offHoursWindows(now(), 7, $hoursOf)) !!}</script>
@endonce
<form action="{{ route('admin.rfqs.start-gm-assistant', $rfq) }}" method="POST" class="d-inline-flex align-items-center gap-2">
    @csrf
    @method('PATCH')
    <input type="hidden" name="part" value="{{ $part }}">
    @include('admin.rfqs._acting_as', ['role' => 'GM Assistant'])
    <span @class(['d-inline-block start-hint js-start-hint', 'is-off' => $busyHint]) tabindex="0"
          data-on-hint="{{ $onHint }}" @if ($busyHint) data-busy-hint="{{ $busyHint }}" @endif
          data-start-hint="{{ $busyHint ?? ($offHoursReason ? $offHint($offHoursReason) : $onHint) }}">
        <button type="submit" class="btn btn-sm btn-primary js-start-button" @disabled($busyHint)>
            <i class="bi bi-play-fill"></i> Start
        </button>
    </span>
</form>
