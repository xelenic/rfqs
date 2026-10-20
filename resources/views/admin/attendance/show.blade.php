{{--
    One attendance sheet's own page (AttendanceController::show()), from the
    attendance list: the day and where HR Executive's review stands — with
    Approve and Return for those who review the sheets, and Edit (Correct,
    once returned) for those who keep them, each coming back here — its
    counts; the leave to review: everyone off, each with their leave's type
    (Full-Day Leave, Half-Day Leave – First Half, Second Half or Custom
    Time), the day, the hours off, how much working time that is and any
    note; everyone on it with their role and mark; and who submitted,
    edited, approved or returned it, and when. Previous / Next step through
    the sheets by day.
    Hovering (or focusing) a person shows their time off — leaves and half
    days this month, this year and in all (_time_off_card).

    Expects: $sheet (with attendances.user.roles, createdBy, updatedBy,
    approvedBy, returnedBy), $lines (its attendances, by name), $timeOff
    (Attendance::timeOffTally() for everyone on it, and in the Edit popup),
    $previousSheet, $nextSheet (the sheets either side, or null), and for
    the Edit popup $people, $sheetDates, $today, $since.
--}}
@extends('layouts.app')

@section('title', 'Attendance — '.$sheet->date->format('D, M j, Y'))

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.attendance.index') }}">Attendance</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $sheet->date->format('D, M j, Y') }}</li>
@endsection

@section('content')
    @php
        $date = $sheet->date->toDateString();
        $dayLabel = fn (string $day) => $day === $today ? 'Today' : \Carbon\CarbonImmutable::parse($day)->format('D, M j, Y');
        $stamp = fn ($at) => $at->toBusinessTimezone()->format('M d, Y g:i A');
        $canKeep = auth()->user()->canManageAttendance();
        $canApprove = auth()->user()->canApproveAttendance();
        $canSeePeople = auth()->user()->can('users.view');
        // Everyone off that day, for HR Executive to review: full-day leave first.
        $leaveLines = $lines->where('status', '!=', \App\Models\Attendance::PRESENT)
            ->sortBy(fn ($line) => [$line->status === \App\Models\Attendance::ABSENT ? 0 : 1, $line->user?->name])
            ->values();
        $counts = [
            'present' => $lines->where('status', \App\Models\Attendance::PRESENT)->count(),
            'half' => $lines->where('status', \App\Models\Attendance::HALF_DAY)->count(),
            'leave' => $lines->where('status', \App\Models\Attendance::ABSENT)->count(),
        ];
        $stats = [
            ['label' => 'People on the sheet', 'value' => $lines->count(), 'caption' => 'Everyone in Sourcing, Data Entry and GM Assistant', 'icon' => 'bi-people', 'tone' => ''],
            ['label' => 'Full-Day Leave', 'value' => $counts['leave'], 'caption' => 'Off the whole working day', 'icon' => 'bi-person-dash', 'tone' => 'danger'],
            ['label' => 'Half-Day Leave', 'value' => $counts['half'], 'caption' => 'Off the first half, the second half or a custom time', 'icon' => 'bi-circle-half', 'tone' => 'warning'],
            ['label' => 'Worked the full day', 'value' => $counts['present'], 'caption' => 'No leave that day', 'icon' => 'bi-person-check', 'tone' => 'success'],
        ];
        // What the Edit popup opens with — as the list's Edit has it.
        $editMarks = $lines->mapWithKeys(fn ($line) => [$line->user_id => ['status' => $line->status, 'half_off' => $line->half_off, 'note' => $line->note]
            + ($line->half_off === \App\Models\Attendance::CUSTOM_HALF ? ['half_off_start' => $line->half_off_start, 'half_off_end' => $line->half_off_end] : [])]);
    @endphp

    <div class="card mb-3" data-attendance-sheet="{{ $date }}">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h2 class="h5 fw-bold mb-0">{{ $sheet->date->format('l, F j, Y') }}</h2>
                    @if ($date === $today)
                        <span class="badge badge-soft-info">Today</span>
                    @endif
                    @if ($sheet->isApproved())
                        <span class="badge badge-soft-success"><i class="bi bi-check2-circle"></i> Approved</span>
                    @elseif ($sheet->isReturned())
                        <span class="badge badge-soft-danger"><i class="bi bi-arrow-counterclockwise"></i> Returned</span>
                    @else
                        <span class="badge badge-soft-warning"><i class="bi bi-hourglass-split"></i> Awaiting HR approval</span>
                    @endif
                </div>
                <div class="text-muted-soft small mt-1">
                    @if ($sheet->isApproved())
                        It counts: present people's time that day, and none of anyone's while they were off.
                    @elseif ($sheet->isReturned())
                        Returned to Senior Operations to correct. It doesn't count until it's corrected and approved.
                    @else
                        Doesn't count until HR Executive approves it.
                    @endif
                </div>
            </div>

            <div class="d-flex flex-wrap align-items-center gap-2">
                @if ($canApprove && $sheet->isAwaitingApproval())
                    <form action="{{ route('admin.attendance.approve', $sheet) }}" method="POST" class="d-inline"
                          data-confirm="Approve attendance for {{ $dayLabel($date) }}? From now, present people's time that day counts and nobody's on leave does.">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="return_to" value="show">
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
                            data-marks="{{ $editMarks->toJson() }}">
                        <i class="bi bi-pencil"></i> {{ $sheet->isReturned() ? 'Correct' : 'Edit' }}
                    </button>
                @endif
                <div class="btn-group btn-group-sm" role="group" aria-label="Other sheets">
                    @if ($previousSheet)
                        <a href="{{ route('admin.attendance.show', $previousSheet) }}" class="btn btn-outline-secondary" title="{{ $previousSheet->date->format('D, M j, Y') }}">
                            <i class="bi bi-chevron-left"></i> Previous
                        </a>
                    @else
                        <button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-chevron-left"></i> Previous</button>
                    @endif
                    @if ($nextSheet)
                        <a href="{{ route('admin.attendance.show', $nextSheet) }}" class="btn btn-outline-secondary" title="{{ $nextSheet->date->format('D, M j, Y') }}">
                            Next <i class="bi bi-chevron-right"></i>
                        </a>
                    @else
                        <button type="button" class="btn btn-outline-secondary" disabled>Next <i class="bi bi-chevron-right"></i></button>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if ($sheet->isReturned())
        <div class="alert alert-danger d-flex align-items-start gap-2" role="status">
            <i class="bi bi-arrow-counterclockwise"></i>
            <div>
                <strong>What needs correcting</strong>{{ $sheet->returnedBy ? ' — from '.$sheet->returnedBy->name : '' }}, {{ $stamp($sheet->returned_at) }}
                <div>{{ $sheet->return_reason }}</div>
            </div>
        </div>
    @endif

    <div class="row g-3 mb-3">
        @foreach ($stats as $stat)
            <div class="col-6 col-md-3">
                <div class="stat-card kpi-card">
                    <div class="kpi-card-head">
                        <span class="stat-label">{{ $stat['label'] }}</span>
                        <span class="stat-icon {{ $stat['tone'] ? 'stat-icon-'.$stat['tone'] : '' }}"><i class="bi {{ $stat['icon'] }}"></i></span>
                    </div>
                    <div class="stat-value">{{ $stat['value'] }}</div>
                    <div class="stat-caption">{{ $stat['caption'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Who submitted it and when, and where HR Executive's review stands. --}}
    <div class="card mb-3">
        <div class="card-header">Sheet details</div>
        <div class="card-body">
            <dl class="rfq-detail-grid mb-0">
                <div>
                    <dt>Submitted by</dt>
                    <dd>{{ $sheet->createdBy?->name ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt>Submitted</dt>
                    <dd>{{ $stamp($sheet->created_at) }}</dd>
                </div>
                @if ($sheet->updatedBy && $sheet->updated_at->gt($sheet->created_at))
                    <div>
                        <dt>Last edited by</dt>
                        <dd>{{ $sheet->updatedBy->name }}</dd>
                    </div>
                    <div>
                        <dt>Last edited</dt>
                        <dd>{{ $stamp($sheet->updated_at) }}</dd>
                    </div>
                @endif
                @if ($sheet->isApproved())
                    <div>
                        <dt>Approved by</dt>
                        <dd>{{ $sheet->approvedBy?->name ?? 'Unknown' }}</dd>
                    </div>
                    <div>
                        <dt>Approved</dt>
                        <dd>{{ $stamp($sheet->approved_at) }}</dd>
                    </div>
                @elseif ($sheet->isReturned())
                    <div>
                        <dt>Returned by</dt>
                        <dd>{{ $sheet->returnedBy?->name ?? 'Unknown' }}</dd>
                    </div>
                    <div>
                        <dt>Returned</dt>
                        <dd>{{ $stamp($sheet->returned_at) }}</dd>
                    </div>
                @else
                    <div>
                        <dt>HR approval</dt>
                        <dd>Awaiting</dd>
                    </div>
                @endif
            </dl>
        </div>
    </div>

    {{-- The leave to review before approving: who's off, what kind of leave, when and for how long. --}}
    <div class="card mb-3" id="leave-to-review">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Leave to review</span>
            <span class="text-muted-soft small fw-normal">
                {{ $counts['leave'] }} Full-Day · {{ $counts['half'] }} Half-Day
            </span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Leave type</th>
                        <th>Date</th>
                        <th>Time off</th>
                        <th>Duration</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($leaveLines as $line)
                        @php
                            $isFullDay = $line->status === \App\Models\Attendance::ABSENT;
                            $hoursOff = $line->hoursOff($date);
                            $secondsOff = $line->workingSecondsOff($date);
                        @endphp
                        <tr data-leave-line="{{ $line->user_id }}">
                            <td>@include('admin.attendance._sheet_person')</td>
                            <td>
                                <span class="badge {{ $isFullDay ? 'badge-soft-danger' : 'badge-soft-orange' }}"><i class="bi {{ $isFullDay ? 'bi-x-lg' : 'bi-circle-half' }}"></i> {{ $line->leaveTypeLabel() }}</span>
                            </td>
                            <td class="text-nowrap">{{ $sheet->date->format('D, M j, Y') }}</td>
                            <td class="text-nowrap">
                                @if ($hoursOff)
                                    {{ $hoursOff['start'] }}–{{ $hoursOff['end'] }}
                                    <div class="small text-muted-soft">{{ $isFullDay ? 'Their whole working day' : 'Lunch isn\'t counted' }}</div>
                                @else
                                    <span class="text-muted-soft">Not a working day for them</span>
                                @endif
                            </td>
                            <td class="text-nowrap fw-semibold">{{ $secondsOff > 0 ? \App\Models\Setting::durationLabel($secondsOff) : '—' }}</td>
                            <td class="small">
                                {{-- Only on an older sheet: why's no longer asked. --}}
                                @if ($line->reason)
                                    <span class="badge badge-soft-danger">{{ $line->reason }}</span>
                                @endif
                                @if ($line->note)
                                    {{ $line->note }}
                                @elseif (! $line->reason)
                                    <span class="text-muted-soft">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted-soft py-4">No leave — everyone on the sheet worked the full day.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Everyone on the sheet</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th>Role</th>
                        <th>Attendance</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($lines as $line)
                        <tr data-attendance-line="{{ $line->user_id }}" @class(['is-absent' => $line->status === \App\Models\Attendance::ABSENT, 'is-half-day' => $line->status === \App\Models\Attendance::HALF_DAY])>
                            <td>@include('admin.attendance._sheet_person')</td>
                            <td>
                                @foreach ($line->user?->roles->pluck('name')->intersect(array_keys(\App\Models\RfqStep::ROLE_STEPS)) ?? [] as $roleName)
                                    <span class="badge badge-soft-secondary">{{ $roleName }}</span>
                                @endforeach
                            </td>
                            <td class="small">
                                @if ($line->status === \App\Models\Attendance::PRESENT)
                                    <span class="badge badge-soft-success"><i class="bi bi-check2"></i> Present — full day</span>
                                @else
                                    @include('admin.attendance._leave_type', ['line' => $line, 'date' => $date])
                                @endif
                            </td>
                            <td class="small">
                                {{-- Only on an older sheet: why's no longer asked. --}}
                                @if ($line->reason)
                                    <span class="badge badge-soft-danger">{{ $line->reason }}</span>
                                @endif
                                @if ($line->note)
                                    {{ $line->note }}
                                @elseif (! $line->reason)
                                    <span class="text-muted-soft">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted-soft py-4">Nobody's on this sheet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Each person's time off, drawn on hovering their name. --}}
    @include('admin.attendance._time_off_cards', ['hoverPeople' => $lines->pluck('user')->merge($canKeep ? $people : [])])

    {{-- Edit, and HR Executive's Return — each back to this page once done. --}}
    @if ($canKeep)
        @include('admin.attendance._sheet_modal', ['returnTo' => 'show'])
    @endif

    @if ($canApprove)
        @include('admin.attendance._return_modal', ['returnTo' => 'show'])
    @endif
@endsection
