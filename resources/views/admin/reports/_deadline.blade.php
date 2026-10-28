{{--
    How one RFQ stands against its targets (Rfq::deadlines()), judged as the
    dashboards judge it: each task someone's on now — Sourcing, Data Entry,
    Finalize or GM Assistant — with the working time it has left, or how far
    past its target it is, red overdue, amber due soon, green on track
    (Rfq::timeAgainstTarget()); then each step that finished a round past its
    target, and by how much in all; else on time.

    Expects: $deadline (null if no timed step has had it), $rfq.
--}}
@php
    $tones = ['overdue' => 'badge-soft-danger', 'due_soon' => 'badge-soft-warning', 'on_track' => 'badge-soft-success'];
@endphp
@if ($deadline === null || ($deadline['running'] === [] && $deadline['late'] === [] && $deadline['on_time'] === 0))
    <span class="text-muted-soft">—</span>
@else
    <div class="d-flex flex-column align-items-start gap-1">
        @foreach ($deadline['running'] as $task)
            <span class="badge {{ $tones[$task['state']] }}" data-deadline-state="{{ $task['state'] }}"
                  title="Working time left against its {{ \App\Models\Setting::hoursLabel(intdiv($task['target'], 60)) }} target — as on the dashboards">
                <i class="bi bi-stopwatch"></i>
                {{ $rfq->isSplit() && $task['part'] ? 'P'.$task['part'].' · ' : '' }}{{ \App\Models\RfqStep::LABELS[$task['step']] }} · {{ \App\Models\Setting::countdownLabel($task['remaining']) }}
            </span>
        @endforeach
        @foreach ($deadline['late'] as $step => $late)
            <span class="badge badge-soft-danger" data-deadline-state="late"
                  title="{{ \App\Models\RfqStep::LABELS[$step] }} finished {{ $late['rounds'] > 1 ? $late['rounds'].' rounds' : 'a round' }} past its target">
                <i class="bi bi-exclamation-triangle"></i>
                {{ \App\Models\RfqStep::LABELS[$step] }} · Finished {{ \App\Models\Setting::durationLabel($late['late_by']) }} late{{ $late['rounds'] > 1 ? ' ('.$late['rounds'].' times)' : '' }}
            </span>
        @endforeach
        @if ($deadline['running'] === [] && $deadline['late'] === [])
            <span class="badge badge-soft-success"><i class="bi bi-check2"></i> On time</span>
        @endif
    </div>
@endif
