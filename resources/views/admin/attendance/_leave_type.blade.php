{{--
    One person's leave on a sheet, as HR Executive reads it before approving:
    its type (Attendance::leaveTypeLabel()) — red for a full day, orange for
    a half — then the hours they're off (hoursOff()) and how much working
    time that is (workingSecondsOff(), lunch left out).

    Expects: $line (an Attendance that isn't present), $date ("Y-m-d", its
    sheet's day).
--}}
@php
    $isFullDay = $line->status === \App\Models\Attendance::ABSENT;
    $hoursOff = $line->hoursOff($date);
    $secondsOff = $line->workingSecondsOff($date);
@endphp
<span class="badge {{ $isFullDay ? 'badge-soft-danger' : 'badge-soft-orange' }}"><i class="bi {{ $isFullDay ? 'bi-x-lg' : 'bi-circle-half' }}"></i> {{ $line->leaveTypeLabel() }}</span>
@if ($hoursOff || $secondsOff > 0)
    <span class="text-muted-soft text-nowrap">{{ collect([$hoursOff ? $hoursOff['start'].'–'.$hoursOff['end'] : null, $secondsOff > 0 ? \App\Models\Setting::durationLabel($secondsOff) : null])->filter()->join(' · ') }}</span>
@endif
