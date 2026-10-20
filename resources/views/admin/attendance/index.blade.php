{{--
    The attendance page (see AttendanceController): every day's submitted sheet,
    newest first — who was off, each with their leave's type (Full-Day Leave,
    Half-Day Leave – First Half, Second Half or Custom Time), hours and
    working time (_leave_type), and who submitted it — and the working days
    still without one. A new sheet, or a correction to
    one, is filled in the popup below (#attendanceModal): every Sourcing, Data
    Entry and GM Assistant person, Present, Half day (the morning or the
    afternoon off, or a custom time of up to 4 working hours —
    Attendance::HALF_DAY, CUSTOM_HALF) or Leave (stored as absent —
    Attendance::ABSENT), with why when they were off.

    Each sheet submitted — new, or corrected — goes to HR Executive, who approves
    it or returns it to Senior Operations with what needs correcting (the
    return popup, #attendanceReturnModal). A person's time only counts on a
    day whose approved sheet has them present. Senior Operations and Admin
    fill sheets in (User::canManageAttendance()); HR Executive and Admin review
    them (User::canApproveAttendance()).

    Each row — anywhere on it but its buttons — opens the sheet's own page
    (show.blade.php), everyone on it one by one; so do its day and View.

    One sheet a day: New attendance sheet opens on the newest day still without
    one ($newSheetDate), and is off once every day has one; the popup turns a
    day that already has one away before it's submitted ($sheetDates, see
    admin.js).

    Hovering (or focusing) the name of anyone off shows their time off —
    leaves and half days this month, this year and in all
    (_time_off_card).

    Expects: $sheets (paginated, with attendances.user, createdBy, updatedBy,
    approvedBy, returnedBy), $timeOff (Attendance::timeOffTally() for
    everyone off on them, and everyone in the sheet popup),
    $people (AttendanceSheet::people()), $missingDays, $sheetDates,
    $newSheetDate, $today, $since (Setting::attendanceSince()).
--}}
@extends('layouts.app')

@section('title', 'Attendance')

@section('content')
    @php
        $dayLabel = fn (string $date) => $date === $today ? 'Today' : \Carbon\CarbonImmutable::parse($date)->format('D, M j, Y');
        $canKeep = auth()->user()->canManageAttendance();
        $canApprove = auth()->user()->canApproveAttendance();
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
        <div class="col-xxl-9">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Attendance sheets</span>
                    @if (! $canKeep)
                        {{-- HR Executive reviews; Senior Operations fills them in. --}}
                    @elseif ($newSheetDate)
                        <button type="button" class="btn btn-sm btn-primary js-attendance-open"
                                data-bs-toggle="modal" data-bs-target="#attendanceModal"
                                data-action="{{ route('admin.attendance.store') }}" data-date="{{ $newSheetDate }}">
                            <i class="bi bi-plus-lg"></i> New attendance sheet
                        </button>
                    @else
                        {{-- Today's is done, and every day before it — one sheet a day. --}}
                        <span class="d-inline-block" tabindex="0" title="Today's sheet is done — edit it from the list">
                            <button type="button" class="btn btn-sm btn-primary" disabled>
                                <i class="bi bi-check2-all"></i> Today's sheet is done
                            </button>
                        </span>
                    @endif
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Leave</th>
                                <th>Submitted by</th>
                                <th>HR approval</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sheets as $sheet)
                                @php
                                    $date = $sheet->date->toDateString();
                                    $absentees = $sheet->attendances->whereIn('status', [\App\Models\Attendance::ABSENT, \App\Models\Attendance::HALF_DAY])->sortBy(fn ($line) => $line->user?->name);
                                    $marks = $sheet->attendances->mapWithKeys(fn ($line) => [$line->user_id => ['status' => $line->status, 'half_off' => $line->half_off, 'note' => $line->note]
                                        + ($line->half_off === \App\Models\Attendance::CUSTOM_HALF ? ['half_off_start' => $line->half_off_start, 'half_off_end' => $line->half_off_end] : [])]);
                                @endphp
                                {{-- The whole row opens the sheet's page (admin.js, [data-row-href]). --}}
                                <tr data-attendance-sheet="{{ $date }}" data-row-href="{{ route('admin.attendance.show', $sheet) }}" @class(['is-clickable-row', 'table-warning' => $canApprove && $sheet->isAwaitingApproval()])>
                                    <td class="fw-semibold text-nowrap">
                                        <a href="{{ route('admin.attendance.show', $sheet) }}" class="text-reset">{{ $dayLabel($date) }}</a>
                                    </td>
                                    <td>
                                        @forelse ($absentees as $line)
                                            <div class="small attendance-leave-line">
                                                @if ($line->user && isset($timeOff[$line->user_id]))
                                                    <span class="fw-semibold time-off-name" tabindex="0" role="button" data-hover-card="time-off-{{ $line->user_id }}">{{ $line->user->name }}</span>
                                                @else
                                                    <span class="fw-semibold">{{ $line->user?->name ?? 'Unknown' }}</span>
                                                @endif
                                                @include('admin.attendance._leave_type', ['line' => $line, 'date' => $date])
                                                {{-- Only on an older sheet: why's no longer asked. --}}
                                                @if ($line->reason)
                                                    <span class="badge badge-soft-danger">{{ $line->reason }}</span>
                                                @endif
                                                @if ($line->note)
                                                    <span class="text-muted-soft">— {{ $line->note }}</span>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="text-muted-soft small">No leave — everyone was present</span>
                                        @endforelse
                                    </td>
                                    <td class="text-muted-soft small">
                                        {{ $sheet->createdBy?->name ?? 'Unknown' }}
                                        <div>{{ $sheet->created_at->toBusinessTimezone()->format('M d, g:i A') }}</div>
                                        @if ($sheet->updatedBy && $sheet->updated_at->gt($sheet->created_at))
                                            <div>edited by {{ $sheet->updatedBy->name }}</div>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($sheet->isApproved())
                                            <span class="badge badge-soft-success"><i class="bi bi-check2-circle"></i> Approved</span>
                                            <div class="text-muted-soft">
                                                {{ $sheet->approvedBy ? 'by '.$sheet->approvedBy->name.' · ' : '' }}{{ $sheet->approved_at->toBusinessTimezone()->format('M d, g:i A') }}
                                            </div>
                                        @elseif ($sheet->isReturned())
                                            <span class="badge badge-soft-danger"><i class="bi bi-arrow-counterclockwise"></i> Returned</span>
                                            <div class="text-muted-soft">
                                                {{ $sheet->returnedBy ? 'by '.$sheet->returnedBy->name.': ' : '' }}{{ $sheet->return_reason }}
                                            </div>
                                        @else
                                            <span class="badge badge-soft-warning"><i class="bi bi-hourglass-split"></i> Awaiting HR approval</span>
                                            <div class="text-muted-soft">Doesn't count until it's approved.</div>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- One above another, so they stay in view beside a long Leave list. --}}
                                        <div class="d-flex flex-column align-items-stretch gap-1 text-nowrap">
                                            <a href="{{ route('admin.attendance.show', $sheet) }}" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-eye"></i> View
                                            </a>
                                            @if ($canApprove && $sheet->isAwaitingApproval())
                                                <form action="{{ route('admin.attendance.approve', $sheet) }}" method="POST" class="d-inline"
                                                      data-confirm="Approve attendance for {{ $dayLabel($date) }}? From now, present people's time that day counts and nobody's on leave does.">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn btn-sm btn-success w-100">
                                                        <i class="bi bi-check2-circle"></i> Approve
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-outline-danger js-attendance-return"
                                                        data-bs-toggle="modal" data-bs-target="#attendanceReturnModal"
                                                        data-action="{{ route('admin.attendance.return', $sheet) }}"
                                                        data-sheet-id="{{ $sheet->id }}"
                                                        data-date-label="{{ $dayLabel($date) }}">
                                                    <i class="bi bi-arrow-counterclockwise"></i> Return
                                                </button>
                                            @endif
                                            @if ($canKeep)
                                                <button type="button" class="btn btn-sm btn-outline-secondary js-attendance-open"
                                                        data-bs-toggle="modal" data-bs-target="#attendanceModal"
                                                        data-action="{{ route('admin.attendance.update', $sheet) }}"
                                                        data-sheet-id="{{ $sheet->id }}" data-date="{{ $date }}"
                                                        data-date-label="{{ $dayLabel($date) }}"
                                                        data-marks="{{ $marks->toJson() }}">
                                                    <i class="bi bi-pencil"></i> {{ $sheet->isReturned() ? 'Correct' : 'Edit' }}
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted-soft py-4">No attendance submitted yet{{ $canKeep ? ' — start with today\'s' : '' }}.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($sheets->hasPages())
                    <div class="card-footer bg-white">
                        {{ $sheets->links() }}
                    </div>
                @endif
            </div>
        </div>

        <div class="col-xxl-3">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>No sheet yet</span>
                    @if ($missingDays)
                        <span class="badge badge-soft-warning">{{ count($missingDays) }}</span>
                    @endif
                </div>
                <div class="list-group list-group-flush">
                    @forelse ($missingDays as $day)
                        <div class="list-group-item d-flex align-items-center justify-content-between" data-missing-day="{{ $day }}">
                            {{ $day === $today ? 'Today' : \Carbon\CarbonImmutable::parse($day)->format('D, M j') }}
                            @if ($canKeep)
                                <button type="button" class="btn btn-sm btn-outline-primary js-attendance-open"
                                        data-bs-toggle="modal" data-bs-target="#attendanceModal"
                                        data-action="{{ route('admin.attendance.store') }}" data-date="{{ $day }}">
                                    <i class="bi bi-plus-lg"></i> Add
                                </button>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item text-muted-soft small">
                            {{ $since === null ? 'Attendance isn\'t being counted.' : 'Every working day has its sheet.' }}
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Each named person's time off, drawn on hovering their name. --}}
    @include('admin.attendance._time_off_cards', ['hoverPeople' => $sheets->getCollection()->flatMap->attendances->pluck('user')->merge($canKeep ? $people : [])])

    {{-- The sheet popup, and HR Executive's Return (_sheet_modal, _return_modal). --}}
    @if ($canKeep)
        @include('admin.attendance._sheet_modal')
    @endif

    @if ($canApprove)
        @include('admin.attendance._return_modal')
    @endif
@endsection
