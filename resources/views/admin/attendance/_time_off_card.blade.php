{{--
    The time off one person has taken — leaves and half days this month,
    this year and in all (since attendance started), with the days off they come to,
    their last leave and half day, and what's on sheets HR Executive hasn't
    approved yet — shown on hovering (or focusing) their name on
    the attendance sheets (admin.js, [data-hover-card]). Kept in a
    <template>, so it's only drawn on hover.

    Expects: $id, $person (a User), $tally (theirs, from
    Attendance::timeOffTally()).
--}}
@php
    $periods = ['month' => 'This month', 'year' => 'This year', 'all' => 'In all'];
    $day = fn (?string $date) => $date ? \Carbon\CarbonImmutable::parse($date)->format('D, M j, Y') : 'None';
    $pending = $tally['pending'];
@endphp
<template id="{{ $id }}">
    <div class="tb">
        <div class="tb-head">
            <strong>{{ $person->name }}</strong>
            <span class="tb-meta">Time off</span>
        </div>

        <table class="time-off-tally">
            <thead>
                <tr>
                    <th></th>
                    <th>Leaves</th>
                    <th>Half days</th>
                    <th>Days off</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($periods as $period => $label)
                    <tr>
                        <th>{{ $label }}</th>
                        <td>{{ $tally[$period]['leaves'] }}</td>
                        <td>{{ $tally[$period]['half_days'] }}</td>
                        <td>{{ \App\Models\Attendance::daysOffLabel($tally[$period]) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="tb-total">
            <div><span class="tb-term">Last leave</span> {{ $day($tally['last_leave']) }}</div>
            <div><span class="tb-term">Last half day</span> {{ $day($tally['last_half_day']) }}</div>
            @if ($pending['leaves'] + $pending['half_days'] > 0)
                <div class="tb-ooh">
                    + {{ collect([
                        $pending['leaves'] > 0 ? $pending['leaves'].' '.\Illuminate\Support\Str::plural('leave', $pending['leaves']) : null,
                        $pending['half_days'] > 0 ? $pending['half_days'].' '.\Illuminate\Support\Str::plural('half day', $pending['half_days']) : null,
                    ])->filter()->join(' and ') }} on sheets not yet approved — not counted yet
                </div>
            @endif
            <div class="tb-meta mt-1">Counted from the sheets HR Executive approved.</div>
        </div>
    </div>
</template>
