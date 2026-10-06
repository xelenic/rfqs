{{--
    The attendance page (see AttendanceController): every day's submitted sheet,
    newest first — who was present, who was on leave and why, who submitted it —
    and the working days still without one. A new sheet, or a correction to
    one, is filled in the popup below (#attendanceModal): every Sourcing, Data
    Entry and GM Assistant person, Present or Leave (stored as absent —
    Attendance::ABSENT), with why when on leave. A
    person's time only counts on a day the sheet has them present.

    One sheet a day: New attendance sheet opens on the newest day still without
    one ($newSheetDate), and is off once every day has one; the popup turns a
    day that already has one away before it's submitted ($sheetDates, see
    admin.js).

    Expects: $sheets (paginated, with attendances.user, createdBy, updatedBy),
    $people (AttendanceSheet::people()), $missingDays, $sheetDates,
    $newSheetDate, $today, $since (Setting::attendanceSince()).
--}}
@extends('layouts.app')

@section('title', 'Attendance')

@section('content')
    @php
        $dayLabel = fn (string $date) => $date === $today ? 'Today' : \Carbon\CarbonImmutable::parse($date)->format('D, M j, Y');
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
                    @if ($newSheetDate)
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
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($sheets as $sheet)
                                @php
                                    $date = $sheet->date->toDateString();
                                    $absentees = $sheet->attendances->where('status', \App\Models\Attendance::ABSENT)->sortBy(fn ($line) => $line->user?->name);
                                    $presentCount = $sheet->attendances->where('status', \App\Models\Attendance::PRESENT)->count();
                                    $marks = $sheet->attendances->mapWithKeys(fn ($line) => [$line->user_id => ['status' => $line->status, 'reason' => $line->reason, 'note' => $line->note]]);
                                @endphp
                                <tr data-attendance-sheet="{{ $date }}">
                                    <td class="fw-semibold text-nowrap">{{ $dayLabel($date) }}</td>
                                    <td><span class="badge badge-soft-success">{{ $presentCount }} present</span></td>
                                    <td>
                                        @forelse ($absentees as $line)
                                            <div class="small">
                                                <span class="fw-semibold">{{ $line->user?->name ?? 'Unknown' }}</span>
                                                <span class="badge badge-soft-danger">{{ $line->reason }}</span>
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
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-attendance-open"
                                                data-bs-toggle="modal" data-bs-target="#attendanceModal"
                                                data-action="{{ route('admin.attendance.update', $sheet) }}"
                                                data-sheet-id="{{ $sheet->id }}" data-date="{{ $date }}"
                                                data-date-label="{{ $dayLabel($date) }}"
                                                data-marks="{{ $marks->toJson() }}">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted-soft py-4">No attendance submitted yet — start with today's.</td>
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
                            <button type="button" class="btn btn-sm btn-outline-primary js-attendance-open"
                                    data-bs-toggle="modal" data-bs-target="#attendanceModal"
                                    data-action="{{ route('admin.attendance.store') }}" data-date="{{ $day }}">
                                <i class="bi bi-plus-lg"></i> Add
                            </button>
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
         comes back filled with what was sent. --}}
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
                        <p class="text-muted-soft small mb-2">Everyone starts present — mark anyone who was off as Leave, and why. Their time that day won't count.</p>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0 attendance-table">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th>Role</th>
                                    <th>Attendance</th>
                                    <th>If on leave</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($people as $person)
                                    @php
                                        $field = fn (string $name) => "attendance.{$person->id}.{$name}";
                                        $status = $failed ? old($field('status'), 'present') : 'present';
                                    @endphp
                                    <tr data-attendance-user="{{ $person->id }}" @class(['is-absent' => $status === 'absent'])>
                                        <td class="fw-semibold">{{ $person->name }}</td>
                                        <td>
                                            @foreach ($person->roles->pluck('name')->intersect(array_keys(\App\Models\RfqStep::ROLE_STEPS)) as $roleName)
                                                <span class="badge badge-soft-secondary">{{ $roleName }}</span>
                                            @endforeach
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group" aria-label="{{ $person->name }}'s attendance">
                                                @foreach (['present' => ['Present', 'btn-outline-success', 'bi-check2'], 'absent' => ['Leave', 'btn-outline-danger', 'bi-x-lg']] as $value => [$label, $class, $icon])
                                                    <input type="radio" class="btn-check js-attendance-status" name="attendance[{{ $person->id }}][status]"
                                                           id="attendance-{{ $person->id }}-{{ $value }}" value="{{ $value }}" autocomplete="off" @checked($status === $value)>
                                                    <label class="btn {{ $class }}" for="attendance-{{ $person->id }}-{{ $value }}"><i class="bi {{ $icon }}"></i> {{ $label }}</label>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td>
                                            <div class="d-flex gap-2 attendance-absence">
                                                <select name="attendance[{{ $person->id }}][reason]" class="form-select form-select-sm w-auto @error($field('reason'), 'attendance') is-invalid @enderror"
                                                        aria-label="Why {{ $person->name }} was on leave">
                                                    <option value="">Why…</option>
                                                    @foreach (\App\Models\Attendance::REASONS as $reason)
                                                        <option value="{{ $reason }}" @selected($failed && old($field('reason')) === $reason)>{{ $reason }}</option>
                                                    @endforeach
                                                </select>
                                                <input type="text" name="attendance[{{ $person->id }}][note]" class="form-control form-control-sm"
                                                       value="{{ $failed ? old($field('note')) : '' }}" maxlength="500" placeholder="Note (optional)" aria-label="Note on {{ $person->name }}'s leave">
                                            </div>
                                            @error($field('reason'), 'attendance')
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
@endsection
