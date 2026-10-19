{{--
    HR Executive's dashboard — people and attendance, nothing of the RFQs
    themselves (DashboardController::hrOverview()): the headline figures;
    today's attendance sheet, everyone who goes on it with their mark and the
    hours they work today; the alerts (hrNotifications()) and the way to their
    pages; each person's attendance over the period, by the approved sheets;
    the latest sheets and where each stands; and the team by role. Live: it
    follows changes as they happen (live.js).

    The period filter scopes attendance over the period and who joined; today's
    sheet, the sheets and the team are as of now.

    Expects: $period, $overview, $notifications.
--}}
@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @php
        $sheetsUrl = route('admin.attendance.index');
        $canSeePeople = auth()->user()->can('users.view');
        $todayCounts = $overview['todayCounts'];
        $tracked = $overview['todayRows']->count();
        $todaySheet = $overview['todaySheet'];
        $todaySheetState = match (true) {
            $todaySheet === null => [$overview['isWorkingDay'] ? 'Not in yet' : 'Day off — none needed', 'badge-soft-secondary'],
            $todaySheet->isApproved() => ['Approved'.($todaySheet->approvedBy ? ' by '.$todaySheet->approvedBy->name : ''), 'badge-soft-success'],
            $todaySheet->isReturned() => ['Returned to Senior Operations', 'badge-soft-danger'],
            default => ['Awaiting your approval', 'badge-soft-warning'],
        };
        $marks = [
            \App\Models\Attendance::PRESENT => ['Present', 'badge-soft-success', 'bi-check2'],
            \App\Models\Attendance::HALF_DAY => ['Half day', 'badge-soft-orange', 'bi-circle-half'],
            \App\Models\Attendance::ABSENT => ['On leave', 'badge-soft-danger', 'bi-x-lg'],
            \App\Models\Attendance::UNMARKED => ['Not marked', 'badge-soft-secondary', 'bi-dash'],
        ];
        $rateTone = fn (?int $rate) => match (true) {
            $rate === null => '',
            $rate >= 90 => 'success',
            $rate >= 75 => 'warning',
            default => 'danger',
        };
        $earlierMissing = count(array_diff($overview['missingDays'], [$overview['today']]));
        $kpis = [
            ['label' => 'Present today', 'value' => $todaySheet ? $todayCounts['present'] + $todayCounts['half'] : '—', 'caption' => $todaySheet ? 'of '.$tracked.($todayCounts['half'] > 0 ? ' · '.$todayCounts['half'].' on a half day' : '') : 'Today\'s sheet isn\'t in yet', 'icon' => 'bi-person-check', 'tone' => 'success', 'alert' => false, 'url' => '#hr-today'],
            ['label' => 'On leave today', 'value' => $todaySheet ? $todayCounts['leave'] : '—', 'caption' => $todaySheet ? 'By today\'s sheet' : 'Not marked yet', 'icon' => 'bi-person-dash', 'tone' => 'danger', 'alert' => false, 'url' => '#hr-today'],
            ['label' => 'Awaiting your approval', 'value' => $overview['awaitingApproval'], 'caption' => $overview['awaitingApproval'] > 0 ? 'Approve to count their time' : 'Nothing waiting', 'icon' => 'bi-hourglass-split', 'tone' => 'warning', 'alert' => $overview['awaitingApproval'] > 0, 'url' => $sheetsUrl],
            ['label' => 'Days without a sheet', 'value' => count($overview['missingDays']), 'caption' => $earlierMissing > 0 ? $earlierMissing.' before today' : 'Senior Operations is up to date', 'icon' => 'bi-calendar-x', 'tone' => 'violet', 'alert' => $earlierMissing > 0, 'url' => $sheetsUrl],
            ['label' => 'Attendance', 'value' => $overview['attendanceRate'] !== null ? $overview['attendanceRate'].'%' : '—', 'caption' => 'Over the period, approved sheets', 'icon' => 'bi-graph-up', 'tone' => $rateTone($overview['attendanceRate']), 'alert' => $overview['attendanceRate'] !== null && $overview['attendanceRate'] < 75, 'url' => '#hr-attendance'],
            ['label' => 'Active people', 'value' => $overview['activePeople'], 'caption' => $overview['joined'] > 0 ? $overview['joined'].' joined in the period' : ($overview['deactivated'] > 0 ? $overview['deactivated'].' deactivated' : 'Everyone signs in'), 'icon' => 'bi-people', 'tone' => 'teal', 'alert' => false, 'url' => '#hr-team'],
        ];
        $quickLinks = array_filter([
            ['Attendance sheets', 'bi-person-check', $sheetsUrl],
            ['Time Spent', 'bi-stopwatch', route('admin.reports.time-spent')],
            auth()->user()->canViewPerformanceReports() ? ['Sourcing Daily', 'bi-calendar-day', route('admin.reports.sourcing-daily')] : null,
            auth()->user()->canViewPerformanceReports() ? ['Sourcing Monthly', 'bi-calendar3', route('admin.reports.sourcing-monthly')] : null,
            auth()->user()->canViewPerformanceReports() ? ['Data Entry Daily', 'bi-keyboard', route('admin.reports.data-entry-daily')] : null,
            auth()->user()->canViewPerformanceReports() ? ['RFQ Tracking Sheet', 'bi-table', route('admin.reports.rfq-tracking')] : null,
            $canSeePeople ? ['Users', 'bi-people', route('admin.users.index')] : null,
            auth()->user()->canManageWorkingHours() ? ['Role Hours', 'bi-clock', route('admin.settings.edit', ['tab' => 'role-hours'])] : null,
        ]);
    @endphp

    @include('admin.dashboard._header', ['subtitle' => 'People and attendance — today\'s sheet, what\'s waiting on your approval, and how attendance has gone.'])

    @include('admin.dashboard._period_filter', ['period' => $period, 'note' => 'attendance over the period and who joined; today\'s sheet, the sheets and the team are as of now'])

    {{-- The headline figures, each leading to where it's from. --}}
    <div class="row g-3 mb-4">
        @foreach ($kpis as $kpi)
            <div class="col-6 col-md-4 col-xxl-2">
                <a href="{{ $kpi['url'] }}" class="stat-card-link">
                    <div class="stat-card kpi-card">
                        <div class="kpi-card-head">
                            <span class="stat-label">{{ $kpi['label'] }}</span>
                            <span class="stat-icon {{ $kpi['tone'] ? 'stat-icon-'.$kpi['tone'] : '' }}"><i class="bi {{ $kpi['icon'] }}"></i></span>
                        </div>
                        <div class="stat-value">{{ $kpi['value'] }}</div>
                        <div class="stat-caption {{ $kpi['alert'] ? 'text-danger fw-semibold' : '' }}">{{ $kpi['caption'] }}</div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-4">
        {{-- Today: everyone on the sheet, their mark and today's hours. --}}
        <div class="col-xxl-8">
            <div class="card h-100" id="hr-today">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>Today's attendance <span class="text-muted-soft small fw-normal">{{ \Carbon\CarbonImmutable::parse($overview['today'])->format('l, M d, Y') }}</span></span>
                    <span class="badge {{ $todaySheetState[1] }}">{{ $todaySheetState[0] }}</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Person</th>
                                <th>Role</th>
                                <th>Today</th>
                                <th>Working hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($overview['todayRows'] as $row)
                                @php [$markLabel, $markBadge, $markIcon] = $marks[$row['status']] ?? $marks[\App\Models\Attendance::UNMARKED]; @endphp
                                <tr>
                                    <td>
                                        @if ($canSeePeople)
                                            <a href="{{ route('admin.users.show', $row['user']) }}" class="dash-person">
                                                <x-avatar :user="$row['user']" class="dash-avatar" />
                                                <span class="text-truncate">{{ $row['user']->name }}</span>
                                            </a>
                                        @else
                                            <span class="dash-person">
                                                <x-avatar :user="$row['user']" class="dash-avatar" />
                                                <span class="text-truncate">{{ $row['user']->name }}</span>
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        @foreach ($row['roles'] as $roleName)
                                            <span class="badge badge-soft-secondary">{{ $roleName }}</span>
                                        @endforeach
                                    </td>
                                    <td>
                                        <span class="badge {{ $markBadge }}"><i class="bi {{ $markIcon }}"></i> {{ $markLabel }}{{ $row['timeOff'] ? ' · '.$row['timeOff'] : '' }}</span>
                                        @if ($row['note'])
                                            <div class="small text-muted-soft">{{ $row['note'] }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap text-muted-soft">{{ $row['hours'] ?? 'Day off' }}</td>
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
        </div>

        <div class="col-xxl-4 d-flex flex-column gap-3">
            @include('admin.dashboard._notifications', ['notifications' => $notifications])

            <div class="card">
                <div class="card-header">Go to</div>
                <div class="card-body d-flex flex-wrap gap-2">
                    @foreach ($quickLinks as [$label, $icon, $url])
                        <a href="{{ $url }}" class="btn btn-sm btn-outline-primary"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Over the period: each person's working days, by the approved sheets. --}}
    <div class="card mb-4" id="hr-attendance">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Attendance <span class="text-muted-soft small fw-normal">{{ $period['dates'] }}</span></span>
            <span class="text-muted-soft small">Their working days · approved sheets only — a day still waiting on one is "Not counted yet"</span>
        </div>
        @if ($overview['attendanceSince'] === null)
            <div class="card-body text-muted-soft small">Attendance isn't counted yet — an Admin chooses the day it starts in Settings → Working Hours.</div>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th class="text-end">Present</th>
                            <th class="text-end">Half day</th>
                            <th class="text-end">On leave</th>
                            <th class="text-end">Not counted yet</th>
                            <th class="text-end">Attendance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($overview['attendance'] as $row)
                            <tr>
                                <td class="fw-semibold text-nowrap">{{ $row['user']->name }}</td>
                                <td class="text-end">{{ $row['present'] }}</td>
                                <td class="text-end">{{ $row['half'] }}</td>
                                <td class="text-end {{ $row['leave'] > 0 ? 'text-danger fw-semibold' : '' }}">{{ $row['leave'] }}</td>
                                <td class="text-end text-muted-soft">{{ $row['unmarked'] }}</td>
                                <td class="text-end">
                                    @if ($row['rate'] !== null)
                                        <span class="badge {{ $rateTone($row['rate']) ? 'badge-soft-'.$rateTone($row['rate']) : 'badge-soft-secondary' }}">{{ $row['rate'] }}%</span>
                                    @else
                                        <span class="text-muted-soft">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted-soft py-4">Nobody to show yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="row g-3">
        {{-- The latest sheets, and where each stands. --}}
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Latest attendance sheets</span>
                    <a href="{{ $sheetsUrl }}" class="small">All sheets</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Marked</th>
                                <th>Submitted by</th>
                                <th>HR approval</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($overview['sheets'] as $sheet)
                                @php
                                    $off = $sheet->attendances->where('status', \App\Models\Attendance::ABSENT)->count();
                                    $half = $sheet->attendances->where('status', \App\Models\Attendance::HALF_DAY)->count();
                                @endphp
                                <tr>
                                    <td class="fw-semibold text-nowrap">{{ $sheet->date->format('D, M d, Y') }}</td>
                                    <td class="small">
                                        {{ $sheet->attendances->count() - $off - $half }} present{{ $half > 0 ? ' · '.$half.' half day' : '' }}{{ $off > 0 ? ' · '.$off.' on leave' : '' }}
                                    </td>
                                    <td class="small text-muted-soft">{{ $sheet->createdBy?->name ?? 'Unknown' }}</td>
                                    <td>
                                        @if ($sheet->isApproved())
                                            <span class="badge badge-soft-success"><i class="bi bi-check2"></i> Approved</span>
                                        @elseif ($sheet->isReturned())
                                            <span class="badge badge-soft-danger"><i class="bi bi-arrow-counterclockwise"></i> Returned</span>
                                        @else
                                            <a href="{{ $sheetsUrl }}" class="badge badge-soft-warning text-decoration-none"><i class="bi bi-hourglass-split"></i> Awaiting your approval</a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted-soft py-4">No attendance sheets yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- The team: active people by role. --}}
        <div class="col-lg-5">
            <div class="card h-100" id="hr-team">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <span>Team</span>
                    @if ($canSeePeople)
                        <a href="{{ route('admin.users.index') }}" class="small">All users</a>
                    @endif
                </div>
                <div class="card-body">
                    @forelse ($overview['team'] as $row)
                        <div class="d-flex align-items-center justify-content-between py-1 border-bottom">
                            <span>{{ $row['role'] }}</span>
                            <span class="fw-semibold">{{ $row['count'] }}</span>
                        </div>
                    @empty
                        <p class="text-muted-soft small mb-0">Nobody has a role yet.</p>
                    @endforelse
                    <div class="d-flex flex-wrap gap-3 mt-3 small text-muted-soft">
                        <span><i class="bi bi-people"></i> {{ $overview['activePeople'] }} active</span>
                        <span><i class="bi bi-person-plus"></i> {{ $overview['joined'] }} joined in the period</span>
                        <span><i class="bi bi-slash-circle"></i> {{ $overview['deactivated'] }} deactivated</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
