{{--
    The attendance page (see AttendanceController): every day's submitted sheet,
    newest first — who was present, who was on leave and why, who submitted it —
    and the working days still without one. A new sheet, or a correction to
    one, is filled in the popup below (#attendanceModal): every Sourcing, Data
    Entry and GM Assistant person, Present, Half day (the morning or the
    afternoon off — Attendance::HALF_DAY) or Leave (stored as absent —
    Attendance::ABSENT), with why when they were off.

    Each sheet submitted — new, or corrected — goes to HR Manager, who approves
    it or returns it to Senior Operations with what needs correcting (the
    return popup, #attendanceReturnModal). A person's time only counts on a
    day whose approved sheet has them present. Senior Operations and Admin
    fill sheets in (User::canManageAttendance()); HR Manager and Admin review
    them (User::canApproveAttendance()).

    One sheet a day: New attendance sheet opens on the newest day still without
    one ($newSheetDate), and is off once every day has one; the popup turns a
    day that already has one away before it's submitted ($sheetDates, see
    admin.js).

    Expects: $sheets (paginated, with attendances.user, createdBy, updatedBy,
    approvedBy, returnedBy),
    $people (AttendanceSheet::people()), $missingDays, $sheetDates,
    $newSheetDate, $today, $since (Setting::attendanceSince()).
--}}
@extends('layouts.app')

@section('title', 'Attendance')

@section('content')
    @php
        $dayLabel = fn (string $date) => $date === $today ? 'Today' : \Carbon\CarbonImmutable::parse($date)->format('D, M j, Y');
        $canKeep = auth()->user()->canManageAttendance();
        // "Morning off (08:30–12:30)" — the time each half day off takes (Settings → Half Day).
        $halfDayOff = \App\Models\Setting::halfDayOff();
        $halfLabel = fn (?string $half) => isset(\App\Models\Attendance::HALVES[$half])
            ? \App\Models\Attendance::HALVES[$half].' ('.$halfDayOff[$half]['start'].'–'.$halfDayOff[$half]['end'].')'
            : 'Half off';
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
        <div class="col-lg-9">
            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Attendance sheets</span>
                    @if (! $canKeep)
                        {{-- HR Manager reviews; Senior Operations fills them in. --}}
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
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Present</th>
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
                                    $presentCount = $sheet->attendances->where('status', \App\Models\Attendance::PRESENT)->count();
                                    $halfDayCount = $sheet->attendances->where('status', \App\Models\Attendance::HALF_DAY)->count();
                                    $marks = $sheet->attendances->mapWithKeys(fn ($line) => [$line->user_id => ['status' => $line->status, 'half_off' => $line->half_off, 'note' => $line->note]]);
                                @endphp
                                <tr data-attendance-sheet="{{ $date }}" @class(['table-warning' => $canApprove && $sheet->isAwaitingApproval()])>
                                    <td class="fw-semibold text-nowrap">{{ $dayLabel($date) }}</td>
                                    <td>
                                        <span class="badge badge-soft-success">{{ $presentCount }} present</span>
                                        @if ($halfDayCount > 0)
                                            <span class="badge badge-soft-orange">{{ $halfDayCount }} half day</span>
                                        @endif
                                    </td>
                                    <td>
                                        @forelse ($absentees as $line)
                                            <div class="small">
                                                <span class="fw-semibold">{{ $line->user?->name ?? 'Unknown' }}</span>
                                                @if ($line->status === \App\Models\Attendance::HALF_DAY)
                                                    <span class="badge badge-soft-orange"><i class="bi bi-circle-half"></i> Half day · {{ \App\Models\Attendance::HALVES[$line->half_off] ?? 'Half off' }}</span>
                                                @endif
                                                {{-- Only on an older sheet: why's no longer asked. --}}
                                                @if ($line->reason)
                                                    <span class="badge badge-soft-danger">{{ $line->reason }}</span>
                                                @endif
                                                @if ($line->note)
                                                    <span class="text-muted-soft">— {{ $line->note }}</span>
                                                @endif
                                            </div>
                                        @empty
                                            <span class="text-muted-soft small">Nobody</span>
                                        @endforelse
                                    </td>
                                    <td class="text-muted-soft small text-nowrap">
                                        {{ $sheet->createdBy?->name ?? 'Unknown' }}
                                        <div>{{ $sheet->created_at->format('M d, g:i A') }}</div>
                                        @if ($sheet->updatedBy && $sheet->updated_at->gt($sheet->created_at))
                                            <div>edited by {{ $sheet->updatedBy->name }}</div>
                                        @endif
                                    </td>
                                    <td class="small">
                                        @if ($sheet->isApproved())
                                            <span class="badge badge-soft-success"><i class="bi bi-check2-circle"></i> Approved</span>
                                            <div class="text-muted-soft text-nowrap">
                                                {{ $sheet->approvedBy ? 'by '.$sheet->approvedBy->name.' · ' : '' }}{{ $sheet->approved_at->format('M d, g:i A') }}
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
                                    <td class="text-end text-nowrap">
                                        @if ($canApprove && $sheet->isAwaitingApproval())
                                            <form action="{{ route('admin.attendance.approve', $sheet) }}" method="POST" class="d-inline"
                                                  data-confirm="Approve attendance for {{ $dayLabel($date) }}? From now, present people's time that day counts and nobody's on leave does.">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn btn-sm btn-success">
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
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted-soft py-4">No attendance submitted yet{{ $canKeep ? ' — start with today\'s' : '' }}.</td>
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

        <div class="col-lg-3">
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

    {{-- The sheet popup — a new day's, or a submitted one to correct. The
         buttons that open it say which (data-action, data-sheet-id,
         data-marks); admin.js fills it in. After a refused submission it
         comes back filled with what was sent. Only for those who keep the
         sheets. --}}
    @if ($canKeep)
    @php
        $failed = $errors->attendance->any();
        $failedSheet = $failed && old('sheet_id') ? \App\Models\AttendanceSheet::query()->find(old('sheet_id')) : null;
    @endphp
    <div class="modal fade" id="attendanceModal" tabindex="-1" aria-labelledby="attendanceModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" id="attendanceForm" novalidate class="modal-content"
                  action="{{ $failedSheet ? route('admin.attendance.update', $failedSheet) : route('admin.attendance.store') }}"
                  data-sheet-dates="{{ json_encode($sheetDates) }}">
                @csrf
                <input type="hidden" name="_method" value="PUT" @disabled(! $failedSheet)>
                <input type="hidden" name="sheet_id" value="{{ $failedSheet?->id }}">

                <div class="modal-header">
                    <h5 class="modal-title" id="attendanceModalLabel">{{ $failedSheet ? 'Edit attendance' : 'New attendance sheet' }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="d-flex align-items-end flex-wrap gap-3 mb-3">
                        <div>
                            <label for="attendance-date" class="form-label">Day</label>
                            <input type="date" name="date" id="attendance-date"
                                   class="form-control @error('date', 'attendance') is-invalid @enderror"
                                   value="{{ $failed ? old('date') : $today }}" data-today="{{ $today }}"
                                   @if ($since) min="{{ $since }}" @endif max="{{ $today }}" @readonly($failedSheet)>
                            @error('date', 'attendance')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="invalid-feedback" id="attendance-date-taken">There's already a sheet for this day — only one per day. Edit it from the list.</div>
                        </div>
                        <p class="text-muted-soft small mb-2">Everyone starts present — mark anyone who was off as Leave, or Half day with the half they were off. It goes to HR Manager for approval; once approved, nobody's time counts while they were off.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0 attendance-table">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th>Role</th>
                                    <th>Attendance</th>
                                    <th>If off</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($people as $person)
                                    @php
                                        $field = fn (string $name) => "attendance.{$person->id}.{$name}";
                                        $status = $failed ? old($field('status'), 'present') : 'present';
                                    @endphp
                                    <tr data-attendance-user="{{ $person->id }}" @class(['is-absent' => $status === 'absent', 'is-half-day' => $status === 'half_day'])>
                                        <td class="fw-semibold">{{ $person->name }}</td>
                                        <td>
                                            @foreach ($person->roles->pluck('name')->intersect(array_keys(\App\Models\RfqStep::ROLE_STEPS)) as $roleName)
                                                <span class="badge badge-soft-secondary">{{ $roleName }}</span>
                                            @endforeach
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group" aria-label="{{ $person->name }}'s attendance">
                                                @foreach (['present' => ['Present', 'btn-outline-success', 'bi-check2'], 'half_day' => ['Half day', 'btn-outline-orange', 'bi-circle-half'], 'absent' => ['Leave', 'btn-outline-danger', 'bi-x-lg']] as $value => [$label, $class, $icon])
                                                    <input type="radio" class="btn-check js-attendance-status" name="attendance[{{ $person->id }}][status]"
                                                           id="attendance-{{ $person->id }}-{{ $value }}" value="{{ $value }}" autocomplete="off" @checked($status === $value)>
                                                    <label class="btn {{ $class }}" for="attendance-{{ $person->id }}-{{ $value }}"><i class="bi {{ $icon }}"></i> {{ $label }}</label>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2 attendance-absence">
                                                <select name="attendance[{{ $person->id }}][half_off]" class="form-select form-select-sm w-auto attendance-half @error($field('half_off'), 'attendance') is-invalid @enderror @if ($status !== 'half_day') d-none @endif"
                                                        aria-label="Which half {{ $person->name }} was off">
                                                    <option value="">Which half…</option>
                                                    @foreach (array_keys(\App\Models\Attendance::HALVES) as $half)
                                                        <option value="{{ $half }}" @selected($failed && old($field('half_off')) === $half)>{{ $halfLabel($half) }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="text" name="attendance[{{ $person->id }}][note]" class="form-control form-control-sm"
                                                       value="{{ $failed ? old($field('note')) : '' }}" maxlength="500" placeholder="Note (optional)" aria-label="Note on {{ $person->name }}'s leave">
                                            </div>
                                            @error($field('half_off'), 'attendance')
                                                <div class="text-danger small attendance-error">{{ $message }}</div>
                                            @enderror
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted-soft py-4">Nobody holds the Sourcing, Data Entry or GM Assistant role yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="attendance-submit" @disabled($people->isEmpty())>
                        <i class="bi bi-check2"></i> <span>{{ $failedSheet ? 'Save attendance' : 'Submit attendance' }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    {{-- A refused submission — back in the popup, as it was sent. --}}
    @if ($failed)
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('attendanceModal')).show();
                });
            </script>
        @endpush
    @endif
    @endif

    {{-- HR Manager's Return — what needs correcting — pointed at a sheet by
         the Return button that opens it (admin.js, .js-attendance-return);
         back open against the same sheet if it's sent without a reason. --}}
    @if ($canApprove)
        @php
            $failedReturn = $errors->attendance_return->any();
            $failedReturnSheet = $failedReturn && old('return_sheet_id') ? \App\Models\AttendanceSheet::query()->find(old('return_sheet_id')) : null;
        @endphp
        <div class="modal fade" id="attendanceReturnModal" tabindex="-1" aria-labelledby="attendanceReturnModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <form method="POST" id="attendanceReturnForm" class="modal-content" novalidate
                      action="{{ $failedReturnSheet ? route('admin.attendance.return', $failedReturnSheet) : '#' }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="return_sheet_id" value="{{ $failedReturnSheet?->id }}">

                    <div class="modal-header">
                        <div>
                            <h5 class="modal-title" id="attendanceReturnModalLabel">Return to Senior Operations</h5>
                            <div class="text-muted-soft small" id="attendanceReturnDay">{{ $failedReturnSheet ? $dayLabel($failedReturnSheet->date->toDateString()) : '' }}</div>
                        </div>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>

                    <div class="modal-body">
                        <label for="attendance-return-reason" class="form-label">What needs correcting</label>
                        <textarea name="reason" id="attendance-return-reason" rows="3" maxlength="1000"
                                  class="form-control @error('reason', 'attendance_return') is-invalid @enderror">{{ $failedReturn ? old('reason') : '' }}</textarea>
                        @error('reason', 'attendance_return')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @else
                            <div class="form-text">Senior Operations sees this with the sheet. It doesn't count until it's corrected and approved.</div>
                        @enderror
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="bi bi-arrow-counterclockwise"></i> Return
                        </button>
                    </div>
                </form>
            </div>
        </div>

        @if ($failedReturnSheet)
            @push('scripts')
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('attendanceReturnModal')).show();
                    });
                </script>
            @endpush
        @endif
    @endif
@endsection
