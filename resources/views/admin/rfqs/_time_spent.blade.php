{{--
    The RFQ's "Time spent" card: the working time Sourcing, Data Entry and GM
    Assistant have each spent on it, every round of rework added up, with each
    part's share on a split — see Rfq::timeSpent(). Only the working hours set
    in Settings count; the elapsed (clock) time beside it counts everything,
    so work done outside working hours still shows. A person's time only
    counts on a day the attendance sheet has them present (Attendance) —
    what's waiting on a sheet, or was a day absent, is noted under it. A role
    that finished a
    step outside working hours (RfqStep::isOutOfHoursWork()) is highlighted,
    with how much of it fell outside them. A role still working on it counts
    up to now.

    Expects: $rfq (with steps loaded).
--}}
@php $timeSpent = $rfq->timeSpent(); @endphp
<div class="card mt-3">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span>Time spent</span>
        <span class="text-muted-soft small fw-normal" title="Working time counts only the working hours set in Settings; elapsed is clock time, nights and days off included.">Working · elapsed</span>
    </div>
    <div class="card-body">
        @foreach ($timeSpent['roles'] as $role => $time)
            <div @class(['time-spent-role', 'time-out-of-hours' => $time['out_of_hours_count'] > 0])>
                <div class="d-flex align-items-center justify-content-between gap-2">
                    <span class="fw-semibold small">
                        {{ $role }}
                        @if ($time['ongoing'])
                            <span class="badge badge-soft-warning ms-1" title="Still with {{ $role }} — counted up to now">In progress</span>
                        @endif
                    </span>
                    <span class="text-nowrap text-end">
                        @if ($time['rounds'] > 0)
                            <span class="fw-semibold">{{ \App\Models\Setting::durationLabel($time['seconds']) }}</span>
                            <span class="text-muted-soft small" title="Clock time, nights and days off included">· {{ \App\Models\Setting::elapsedLabel($time['elapsed']) }}</span>
                        @else
                            <span class="fw-semibold">—</span>
                        @endif
                    </span>
                </div>
                @php
                    $details = collect($rfq->isSplit() ? $time['parts'] : [])
                        ->map(fn (int $minutes, int $part) => 'P'.$part.' '.\App\Models\Setting::hoursLabel($minutes));
                    if ($time['reworks'] > 0) {
                        $details->push($time['reworks'].' '.\Illuminate\Support\Str::plural('rework', $time['reworks']));
                    }
                @endphp
                @if ($details->isNotEmpty())
                    <div class="text-muted-soft small">{{ $details->implode(' · ') }}</div>
                @endif
                @include('admin.reports._attendance_note', ['time' => $time])
                @if ($time['out_of_hours_count'] > 0)
                    <div class="small fw-semibold time-out-of-hours-note" title="{{ $time['out_of_hours_count'] }} {{ \Illuminate\Support\Str::plural('step', $time['out_of_hours_count']) }} finished outside working hours">
                        <i class="bi bi-moon-stars"></i> {{ \App\Models\Setting::elapsedLabel($time['out_of_hours']) }} out of working hours
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    <div class="card-footer bg-white d-flex align-items-start justify-content-between">
        <span class="text-muted-soft small">
            Total
            @include('admin.reports._attendance_note', ['time' => $timeSpent])
        </span>
        <span class="text-nowrap">
            <span class="fw-semibold" id="rfq-time-spent-total">{{ \App\Models\Setting::durationLabel($timeSpent['seconds']) }}</span>
            <span class="text-muted-soft small" title="Clock time, nights and days off included">· {{ \App\Models\Setting::elapsedLabel($timeSpent['elapsed']) }} elapsed</span>
        </span>
    </div>
</div>
