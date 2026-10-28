{{--
    Every task someone's on right now, overdue first
    (DashboardController::ongoingTasks()): the RFQ or part, the step, who
    has it, its priority, when it last changed, its time against target
    (_time_vs_target) and where it stands — on track, due soon, overdue, or
    in progress with no target. On Admin's dashboard and Senior Operations'.
    A person's name opens their page for whoever can see Users.

    Expects: $ongoing.
--}}
@php
    $timezone = \App\Models\Setting::timezone();
    $taskStates = [
        'overdue' => ['Overdue', 'badge-soft-danger', 'tone-danger'],
        'due_soon' => ['Due soon', 'badge-soft-warning', 'tone-warning'],
        'on_track' => ['On track', 'badge-soft-success', 'tone-success'],
        'running' => ['In progress', 'badge-soft-info', 'tone-info'],
    ];
    $canOpenPeople = auth()->user()->can('users.view');
@endphp
<div class="card mb-4" id="ongoing-tasks">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span>Today's ongoing tasks <span class="badge badge-soft-primary ms-1">{{ $ongoing->count() }}</span></span>
        <span class="task-legend">
            @foreach (['on_track', 'due_soon', 'overdue'] as $state)
                <span class="{{ $taskStates[$state][2] }}"><i class="task-legend-dot"></i>{{ $taskStates[$state][0] }}</span>
            @endforeach
        </span>
    </div>
    @if ($ongoing->isEmpty())
        <div class="card-body">
            <p class="text-muted-soft small mb-0">Nobody's on a task right now.</p>
        </div>
    @else
        <div class="table-responsive dash-scroll">
            <table class="table table-hover mb-0 task-sheet">
                <thead>
                    <tr>
                        <th>Task</th>
                        <th>Assignee</th>
                        <th>Priority</th>
                        <th title="When the task last changed — reached its step, was resumed, or its part was updated">Last Updated</th>
                        <th>Time vs target</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ongoing as $task)
                        @php [$stateLabel, $stateBadge, $stateTone] = $taskStates[$task['state']]; @endphp
                        <tr>
                            <td class="task-sheet-task">
                                <a href="{{ $task['rfq']->detailsUrl($task['part'] ?? null, 'Pending') }}" class="fw-semibold">{{ $task['label'] }}</a>
                                <div class="small text-muted-soft text-truncate">{{ $task['task'] }} · {{ $task['rfq']->subject }}</div>
                            </td>
                            <td>
                                @if ($task['worker'] && $canOpenPeople)
                                    <a href="{{ route('admin.users.show', $task['worker']) }}" class="dash-person">
                                        <x-avatar :user="$task['worker']" class="dash-avatar" />
                                        <span class="text-truncate">{{ $task['worker']->name }}</span>
                                    </a>
                                @elseif ($task['worker'])
                                    <span class="dash-person">
                                        <x-avatar :user="$task['worker']" class="dash-avatar" />
                                        <span class="text-truncate">{{ $task['worker']->name }}</span>
                                    </span>
                                @else
                                    <span class="dash-person text-muted-soft">
                                        <span class="dash-avatar is-team"><i class="bi bi-people"></i></span>
                                        {{ $task['role'] }} team
                                    </span>
                                @endif
                            </td>
                            <td><span class="badge {{ $task['rfq']->priorityBadgeClass() }}">{{ $task['rfq']->priorityLabel() }}</span></td>
                            <td class="text-nowrap text-muted-soft">{{ $task['updated_at']->copy()->setTimezone($timezone)->format('M d, Y g:i A') }}</td>
                            <td>
                                @if ($task['target'])
                                    @include('admin.dashboard._time_vs_target', ['timing' => $task])
                                @else
                                    <span class="task-progress-text">{{ \App\Models\Setting::durationLabel($task['seconds']) }} so far · no target</span>
                                @endif
                            </td>
                            <td><span class="badge {{ $stateBadge }}">{{ $stateLabel }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
