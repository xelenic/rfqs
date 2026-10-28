{{--
    A task's working time against its target, as the dashboards' "Time vs
    target" shows it (Rfq::timeAgainstTarget()): a bar —
    green on track, amber due soon (under a quarter of the target left), red
    overdue — and "15h 34m of 20h", or "7h 50m over 1h" once it's past.
    With $for, a line under it says whose target it is and where it stands
    ("Finalize target · Overdue").

    Expects: $timing (seconds, target, remaining, state); optionally $for.
--}}
@php
    [$stateLabel, $tone] = [
        'overdue' => ['Overdue', 'tone-danger'],
        'due_soon' => ['Due soon', 'tone-warning'],
        'on_track' => ['On track', 'tone-success'],
    ][$timing['state']] ?? ['In progress', 'tone-info'];
@endphp
<div class="task-progress {{ $tone }}" data-time-state="{{ $timing['state'] }}">
    <span class="task-progress-track"><span class="task-progress-bar" style="width: {{ min(100, round($timing['seconds'] / max(1, $timing['target']) * 100, 1)) }}%"></span></span>
    <span class="task-progress-text">
        @if ($timing['remaining'] < 0)
            {{ \App\Models\Setting::durationLabel(-$timing['remaining']) }} over {{ \App\Models\Setting::durationLabel($timing['target']) }}
        @else
            {{ \App\Models\Setting::durationLabel($timing['seconds']) }} of {{ \App\Models\Setting::durationLabel($timing['target']) }}
        @endif
    </span>
    @isset($for)
        <span class="task-progress-for"><span class="text-nowrap">{{ $for }} target ·</span> <span class="task-progress-state text-nowrap">{{ $stateLabel }}</span></span>
    @endisset
</div>
