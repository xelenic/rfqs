{{--
    The daily attendance sheet (see AttendanceController): for one day — today,
    or an earlier one since attendance started — either the button to make
    its sheet, or the sheet itself: every Sourcing, Data Entry and GM Assistant
    person, with the time tracked as theirs that day, Present or Absent, and
    why when absent. A person's time only counts on a day the sheet has them
    present. Beside it, the working days still without a sheet, and the last
    few made.

    Expects: $date, $today, $since (Setting::attendanceSince()), $sheet (or
    null), $marks (user id => Attendance), $people, $tracked (user id =>
    seconds that day), $missingDays, $recentSheets.
--}}
@extends('layouts.app')

@section('title', 'Attendance')

@section('content')
    @php
        $isToday = $date === $today;
        $dayLabel = $isToday ? 'Today' : \Carbon\CarbonImmutable::parse($date)->format('D, M j, Y');
    @endphp

    @if ($since === null)
        <div class="alert alert-info d-flex align-items-start gap-2">
            <i class="bi bi-info-circle"></i>
            <div>
                Attendance isn't counted yet — all tracked time counts straight away.
                @if (auth()->user()->hasRole('Admin'))
                    Choose the day it starts in <a href="{{ route('admin.settings.edit', ['tab' => 'working-hours']) }}">Settings → Working Hours</a>.
                @endif
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <form method="GET" class="d-flex align-items-center gap-2">
                        <label for="attendance-date" class="small text-muted-soft mb-0">Day</label>
                        <input type="date" name="date" id="attendance-date" class="form-control form-control-sm w-auto"
                               value="{{ $date }}" @if ($since) min="{{ $since }}" @endif max="{{ $today }}" onchange="this.form.submit()">
                        <span class="fw-semibold">{{ $dayLabel }}</span>
                    </form>
                    @if ($sheet)
                        <span class="text-muted-soft small">
                            Made by {{ $sheet->createdBy?->name ?? 'Unknown' }}
                            @if ($sheet->updatedBy)
                                · last saved by {{ $sheet->updatedBy->name }} {{ $sheet->updated_at->diffForHumans() }}
                            @endif
                        </span>
                    @endif
                </div>

                @if ($sheet === null)
                    <div class="card-body text-center py-5">
                        <i class="bi bi-clipboard-plus display-6 text-muted-soft"></i>
                        <p class="mt-2 mb-3">No attendance sheet for {{ $isToday ? 'today' : $dayLabel }} yet. Until there is, nobody's time {{ $isToday ? 'today' : 'that day' }} counts.</p>
                        <form method="POST" action="{{ route('admin.attendance.store') }}">
                            @csrf
                            <input type="hidden" name="date" value="{{ $date }}">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-clipboard-check"></i> Make attendance sheet</button>
                        </form>
                        <div class="form-text">Everyone starts present — then mark who's absent.</div>
                    </div>
                @else
                    <form method="POST" action="{{ route('admin.attendance.update', $sheet) }}" novalidate>
                        @csrf
                        @method('PUT')
                        <div class="table-responsive">
                            <table class="table align-middle mb-0 attendance-table">
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th>Role</th>
                                        <th class="text-end">Time tracked</th>
                                        <th>Attendance</th>
                                        <th>If absent</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($people as $person)
                                        @php
                                            $mark = $marks->get($person->id);
                                            $field = fn (string $name) => "attendance.{$person->id}.{$name}";
                                            $status = old($field('status'), $mark?->status ?? \App\Models\Attendance::PRESENT);
                                        @endphp
                                        <tr data-attendance-user="{{ $person->id }}" @class(['is-absent' => $status === 'absent'])>
                                            <td class="fw-semibold">
                                                {{ $person->name }}
                                                @if ($mark === null)
                                                    <span class="badge badge-soft-warning" title="Not on the sheet yet — saving adds them">New</span>
                                                @endif
                                            </td>
                                            <td>
                                                @foreach ($person->roles->pluck('name')->intersect(array_keys(\App\Models\RfqStep::ROLE_STEPS)) as $roleName)
                                                    <span class="badge badge-soft-secondary">{{ $roleName }}</span>
                                                @endforeach
                                            </td>
                                            <td class="text-end text-nowrap">{{ \App\Models\Setting::durationLabel($tracked[$person->id] ?? 0) }}</td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group" aria-label="{{ $person->name }}'s attendance">
                                                    @foreach (['present' => ['Present', 'btn-outline-success', 'bi-check2'], 'absent' => ['Absent', 'btn-outline-danger', 'bi-x-lg']] as $value => [$label, $class, $icon])
                                                        <input type="radio" class="btn-check js-attendance-status" name="attendance[{{ $person->id }}][status]"
                                                               id="attendance-{{ $person->id }}-{{ $value }}" value="{{ $value }}" autocomplete="off" @checked($status === $value)>
                                                        <label class="btn {{ $class }}" for="attendance-{{ $person->id }}-{{ $value }}"><i class="bi {{ $icon }}"></i> {{ $label }}</label>
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <div class="d-flex gap-2 attendance-absence">
                                                    <select name="attendance[{{ $person->id }}][reason]" class="form-select form-select-sm w-auto @error($field('reason'), 'attendance') is-invalid @enderror"
                                                            aria-label="Why {{ $person->name }} was absent">
                                                        <option value="">Why…</option>
                                                        @foreach (\App\Models\Attendance::REASONS as $reason)
                                                            <option value="{{ $reason }}" @selected(old($field('reason'), $mark?->reason) === $reason)>{{ $reason }}</option>
                                                        @endforeach
                                                    </select>
                                                    <input type="text" name="attendance[{{ $person->id }}][note]" class="form-control form-control-sm"
                                                           value="{{ old($field('note'), $mark?->note) }}" maxlength="500" placeholder="Note (optional)" aria-label="Note on {{ $person->name }}'s absence">
                                                </div>
                                                @error($field('reason'), 'attendance')
                                                    <div class="text-danger small">{{ $message }}</div>
                                                @enderror
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5" class="text-center text-muted-soft py-4">Nobody holds the Sourcing, Data Entry or GM Assistant role yet.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                        @if ($people->isNotEmpty())
                            <div class="card-body border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                                <span class="text-muted-soft small">A person's time {{ $isToday ? 'today' : 'that day' }} only counts if they're marked present.</span>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save attendance</button>
                            </div>
                        @endif
                    </form>
                @endif
            </div>
        </div>

        <div class="col-lg-3">
            @if ($since)
                <div class="card mb-3">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>No sheet yet</span>
                        @if ($missingDays)
                            <span class="badge badge-soft-warning">{{ count($missingDays) }}</span>
                        @endif
                    </div>
                    <div class="list-group list-group-flush">
                        @forelse ($missingDays as $day)
                            <a href="{{ route('admin.attendance.index', ['date' => $day]) }}"
                               @class(['list-group-item list-group-item-action', 'active' => $day === $date])>
                                {{ $day === $today ? 'Today' : \Carbon\CarbonImmutable::parse($day)->format('D, M j') }}
                            </a>
                        @empty
                            <div class="list-group-item text-muted-soft small">Every working day has its sheet.</div>
                        @endforelse
                    </div>
                </div>
            @endif

            <div class="card">
                <div class="card-header">Recent sheets</div>
                <div class="list-group list-group-flush">
                    @forelse ($recentSheets as $recent)
                        @php $recentDate = $recent->date->toDateString(); @endphp
                        <a href="{{ route('admin.attendance.index', ['date' => $recentDate]) }}"
                           @class(['list-group-item list-group-item-action d-flex justify-content-between align-items-center', 'active' => $recentDate === $date])>
                            {{ $recentDate === $today ? 'Today' : $recent->date->format('D, M j') }}
                            <span class="small">
                                <span class="text-success">{{ $recent->present_count }} in</span>
                                @if ($recent->absent_count > 0)
                                    · <span class="text-danger">{{ $recent->absent_count }} off</span>
                                @endif
                            </span>
                        </a>
                    @empty
                        <div class="list-group-item text-muted-soft small">No sheets made yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
