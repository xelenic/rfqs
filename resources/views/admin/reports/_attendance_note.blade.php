{{--
    Under a time on the Time Spent report or an RFQ's Time spent card: what of
    it isn't counted yet — its day's attendance sheet isn't made — and what
    won't be, a day its person was absent. See Attendance.

    Expects: $time (a role's, or the whole RFQ's, from Rfq::timeSpent()).
--}}
@if ($time['awaiting'] > 0)
    <div class="small time-awaiting-note" title="Not counted until that day's attendance sheet is made and approved by HR Manager">
        <i class="bi bi-hourglass"></i> {{ \App\Models\Setting::durationLabel($time['awaiting']) }} awaiting attendance
    </div>
@endif
@if ($time['absent'] > 0)
    <div class="small text-muted-soft text-decoration-line-through" title="On leave that day — not counted">
        {{ \App\Models\Setting::durationLabel($time['absent']) }} on leave
    </div>
@endif
