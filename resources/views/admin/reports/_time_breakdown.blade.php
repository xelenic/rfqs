{{--
    How one role's time on an RFQ came about — the popup on hovering its
    figure on the Time Spent report (admin.js, [data-time-breakdown]): the
    formula, then each stretch of its work (Rfq::timeBreakdown()) — the step
    and part, whose time it is and whose working week it ran on, when it ran
    and its clock time, its working time less what isn't counted — and the
    stretches added up to the figure shown. Kept in a <template>, so it's
    only drawn on hover.

    Expects: $id, $rfq, $role, $roleTime (Rfq::timeSpent()'s, for the role),
    $stretches (Rfq::timeBreakdown()'s, for the role).
--}}
@php
    $working = fn (int $seconds) => \App\Models\Setting::durationLabel($seconds);
    $clock = fn (int $seconds) => \App\Models\Setting::elapsedLabel($seconds);
    $when = fn (\Carbon\CarbonInterface $at) => $at->toBusinessTimezone()->format('D, M j · g:i A');
    $counted = collect($stretches)->pluck('counted');
@endphp
<template id="{{ $id }}">
    <div class="tb">
        <div class="tb-head">
            <strong>{{ $role }} · {{ $rfq->rfq_number }}</strong>
            <span class="tb-head-figure">{{ $working($roleTime['seconds']) }}</span>
        </div>

        <div class="tb-formula">
            <div><span class="tb-term">Counted</span> = working time − on leave − awaiting attendance</div>
            <div><span class="tb-term">Working time</span> = the time inside the person's working hours — lunch, nights and days off left out</div>
        </div>

        <ul class="tb-stretches">
            @foreach ($stretches as $i => $stretch)
                <li>
                    <div class="tb-stretch-head">
                        <span>
                            <strong>{{ $i + 1 }}. {{ $stretch['step'] }}</strong>{{ $stretch['part'] ? ' · '.$stretch['part'] : '' }}
                            @if ($stretch['rework'])
                                <span class="tb-tag">rework</span>
                            @endif
                            @if ($stretch['resumed'])
                                <span class="tb-tag">after a stop</span>
                            @endif
                            @if ($stretch['to'] === null)
                                <span class="tb-tag tb-tag-live">in progress</span>
                            @endif
                        </span>
                        <strong>{{ $working($stretch['counted']) }}</strong>
                    </div>
                    <div class="tb-meta">{{ $stretch['worker'] ?? 'Not credited to anyone yet' }} · {{ $stretch['hours'] }}</div>
                    <div class="tb-meta">{{ $when($stretch['from']) }} → {{ $stretch['to'] ? $when($stretch['to']) : 'now' }} · {{ $clock($stretch['elapsed']) }} clock time</div>
                    <div class="tb-calc">
                        {{ $working($stretch['working']) }} working
                        @if ($stretch['absent'] > 0)
                            − {{ $working($stretch['absent']) }} on leave
                        @endif
                        @if ($stretch['awaiting'] > 0)
                            − {{ $working($stretch['awaiting']) }} awaiting attendance
                        @endif
                        @if ($stretch['unattributed'] > 0)
                            − {{ $working($stretch['unattributed']) }} credited to nobody
                        @endif
                        = <strong>{{ $working($stretch['counted']) }}</strong>
                    </div>
                    @if ($stretch['outOfHours'] > 0)
                        <div class="tb-ooh"><i class="bi bi-moon-stars"></i> Finished out of hours — {{ $clock($stretch['outOfHours']) }} of it outside working hours, not counted</div>
                    @endif
                </li>
            @endforeach
        </ul>

        <div class="tb-total">
            <div>
                @if ($counted->count() > 1 && $counted->count() <= 6)
                    {{ $counted->map(fn (int $seconds) => $working($seconds))->join(' + ') }} =
                @elseif ($counted->count() > 6)
                    {{ $counted->count() }} stretches added up =
                @endif
                <strong>{{ $working($roleTime['seconds']) }}</strong> counted
            </div>
            <div class="tb-meta">
                {{ $clock($roleTime['elapsed']) }} clock time
                · {{ $roleTime['rounds'] }} {{ \Illuminate\Support\Str::plural('round', $roleTime['rounds']) }}@if ($roleTime['reworks'] > 0), {{ $roleTime['reworks'] }} {{ \Illuminate\Support\Str::plural('rework', $roleTime['reworks']) }}@endif
                @if ($roleTime['ongoing'])
                    · still counting up to now
                @endif
            </div>
        </div>
    </div>
</template>
