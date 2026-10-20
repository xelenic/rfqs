{{--
    One time-off card (_time_off_card) for each person the page names — on
    the sheets, and in the sheet popup — each drawn on hovering their name
    ([data-hover-card="time-off-{id}"]).

    Expects: $hoverPeople (Users, any repeats dropped here), $timeOff
    (Attendance::timeOffTally() for them).
--}}
@foreach ($hoverPeople->filter()->unique('id')->filter(fn ($person) => isset($timeOff[$person->id])) as $person)
    @include('admin.attendance._time_off_card', ['id' => 'time-off-'.$person->id, 'person' => $person, 'tally' => $timeOff[$person->id]])
@endforeach
