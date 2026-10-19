<!DOCTYPE html>
{{-- The person's theme (Settings → Preferences): data-bs-theme turns
     Bootstrap's own components dark, data-theme admin.css's palette. --}}
<html lang="en" data-theme="{{ auth()->user()->theme() }}" data-bs-theme="{{ auth()->user()->theme() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Live updates (public/js/live.js): the data's version this page was built
         from, where to ask whether it has moved on, and how often. Left out for
         anyone who has turned them off on their Settings page — the script then
         does nothing. --}}
    @if (auth()->user()->preference('live_updates'))
        <meta name="live-version" content="{{ request()->attributes->get('live_version', \App\LiveVersion::current()) }}">
        <meta name="live-pulse" content="{{ route('admin.live') }}">
        <meta name="live-interval" content="{{ \App\Models\Setting::liveIntervalSeconds() * 1000 }}">
    @endif
    <title>@yield('title', 'Dashboard') · {{ \App\Models\Setting::companyName() }} Admin</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ filemtime(public_path('css/admin.css')) }}">
    @stack('styles')
</head>
<body>
    @include('layouts._preloader')
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <img src="{{ asset('logo.jpg') }}?v={{ filemtime(public_path('logo.jpg')) }}" alt="{{ \App\Models\Setting::companyName() }}" class="sidebar-logo">
            </div>

            <nav class="sidebar-nav">
                <div class="sidebar-section-title">Main</div>
                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2"></i> Dashboard
                </a>
                {{-- Admin's list of every RFQ, whatever its status or where it is
                     — the RFQ list with no status or role asked for. --}}
                @if (auth()->user()->hasRole('Admin') && auth()->user()->can('rfqs.view'))
                    <a href="{{ route('admin.rfqs.index') }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && ! request()->filled('status') && ! request()->filled('role') ? 'active' : '' }}">
                        <i class="bi bi-collection"></i> All RFQs
                    </a>
                @endif
                @php $unreadMessages = auth()->user()->receivedMessages()->unread()->count(); @endphp
                <a href="{{ route('admin.messages.index') }}" class="nav-link {{ request()->routeIs('admin.messages.*') ? 'active' : '' }}">
                    <i class="bi bi-chat-dots"></i>
                    <span class="nav-link-label">Messages</span>
                    @if ($unreadMessages > 0)
                        <span class="nav-link-count" title="{{ $unreadMessages }} unread">{{ $unreadMessages }}</span>
                    @endif
                </a>

                @can('rfqs.view')
                    @if (auth()->user()->hasRole('Admin'))
                        @include('layouts._sidebar_admin_groups')
                    @else
                        @include('layouts._sidebar_requests')
                    @endif
                @endcan

                {{-- Admin has these in a Human Resource group of their own, after
                     RFQ Management (_sidebar_admin_groups). --}}
                @if (auth()->user()->canViewTimeSpentReport() && ! (auth()->user()->hasRole('Admin') && auth()->user()->can('rfqs.view')))
                    <div class="sidebar-section-title">Reports</div>
                    @if (auth()->user()->canViewAttendance())
                        {{-- What's waiting on them there, as a red count: for Senior
                             Operations, the working days still without a sheet and the
                             sheets HR Executive returned; for HR Executive, the sheets
                             awaiting their approval. --}}
                        @php
                            $daysWithoutSheet = auth()->user()->canManageAttendance() ? count(\App\Models\AttendanceSheet::missingDays()) : 0;
                            $attendanceToDo = array_filter([
                                $daysWithoutSheet.' working '.\Illuminate\Support\Str::plural('day', $daysWithoutSheet).' without a sheet' => $daysWithoutSheet,
                                'returned by HR Executive' => auth()->user()->canManageAttendance() ? \App\Models\AttendanceSheet::returnedCount() : 0,
                                'awaiting your approval' => auth()->user()->canApproveAttendance() ? \App\Models\AttendanceSheet::awaitingApprovalCount() : 0,
                            ]);
                            $attendanceCount = array_sum($attendanceToDo);
                            $attendanceTitle = collect($attendanceToDo)->map(fn (int $count, string $what) => str_contains($what, 'without a sheet') ? $what : "{$count} {$what}")->join(', ');
                        @endphp
                        <a href="{{ route('admin.attendance.index') }}" class="nav-link {{ request()->routeIs('admin.attendance.*') ? 'active' : '' }}">
                            <i class="bi bi-person-check"></i>
                            <span class="nav-link-label">Attendance</span>
                            @if ($attendanceCount > 0)
                                <span class="nav-link-count" title="{{ $attendanceTitle }}">{{ $attendanceCount }}</span>
                            @endif
                        </a>
                    @endif
                    <a href="{{ route('admin.reports.time-spent') }}" class="nav-link {{ request()->routeIs('admin.reports.time-spent') ? 'active' : '' }}">
                        <i class="bi bi-stopwatch"></i> Time Spent
                    </a>
                    {{-- The performance reports — HR Executive's here; Admin's under
                         Reports in _sidebar_admin_groups. --}}
                    @if (auth()->user()->canViewPerformanceReports())
                        @foreach ([
                            'admin.reports.sourcing-daily' => ['Sourcing Daily', 'bi-calendar-day'],
                            'admin.reports.sourcing-monthly' => ['Sourcing Monthly', 'bi-calendar3'],
                            'admin.reports.data-entry-daily' => ['Data Entry Daily', 'bi-keyboard'],
                            'admin.reports.rfq-tracking' => ['RFQ Tracking Sheet', 'bi-table'],
                        ] as $reportRoute => [$reportLabel, $reportIcon])
                            <a href="{{ route($reportRoute) }}" class="nav-link {{ request()->routeIs($reportRoute) ? 'active' : '' }}">
                                <i class="bi {{ $reportIcon }}"></i> {{ $reportLabel }}
                            </a>
                        @endforeach
                    @endif
                @endif

                @canany(['users.view', 'roles.view', 'permissions.view'])
                    <div class="sidebar-section-title">Access Control</div>
                @endcanany

                @can('users.view')
                    <a href="{{ route('admin.users.index') }}" class="nav-link {{ request()->routeIs('admin.users.*') ? 'active' : '' }}">
                        <i class="bi bi-people"></i> Users
                    </a>
                @endcan

                @can('roles.view')
                    <a href="{{ route('admin.roles.index') }}" class="nav-link {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}">
                        <i class="bi bi-shield-check"></i> Roles
                    </a>
                @endcan

                @can('permissions.view')
                    <a href="{{ route('admin.permissions.index') }}" class="nav-link {{ request()->routeIs('admin.permissions.*') ? 'active' : '' }}">
                        <i class="bi bi-key"></i> Permissions
                    </a>
                @endcan

                {{-- The application's log files, read only — Admin\LogController. --}}
                @if (auth()->user()->hasRole('Admin'))
                    <div class="sidebar-section-title">System</div>
                    <a href="{{ route('admin.logs.index') }}" class="nav-link {{ request()->routeIs('admin.logs.*') ? 'active' : '' }}">
                        <i class="bi bi-journal-text"></i> Logs
                    </a>
                    {{-- Backups and restoring them — Admin\BackupController. --}}
                    <a href="{{ route('admin.backups.index') }}" class="nav-link {{ request()->routeIs('admin.backups.*') ? 'active' : '' }}">
                        <i class="bi bi-cloud-arrow-up"></i> Backups
                    </a>
                @endif

                <div class="sidebar-section-title">Account</div>
                <a href="{{ route('admin.settings.edit') }}" class="nav-link {{ request()->routeIs('admin.settings.*') ? 'active' : '' }}">
                    <i class="bi bi-gear"></i> Settings
                </a>
            </nav>

            <div class="sidebar-footer">
                &copy; {{ now()->year }} {{ \App\Models\Setting::companyName() }}
            </div>
        </aside>

        <div class="main-col">
            {{-- An Admin in someone else's account (App\Impersonation): whose it
                 is, and the way back. --}}
            @if ($impersonator = \App\Impersonation::impersonator())
                <div class="impersonation-bar" role="status">
                    <span class="impersonation-bar-text">
                        <i class="bi bi-incognito" aria-hidden="true"></i>
                        You're signed in as <strong>{{ auth()->user()->name }}</strong>
                        <span class="impersonation-bar-roles">{{ auth()->user()->roles->pluck('name')->join(', ') ?: 'No role' }}</span>
                        — whatever you do here is done as them.
                    </span>
                    <form method="POST" action="{{ route('admin.impersonation.leave') }}">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-dark">
                            <i class="bi bi-box-arrow-left"></i> Back to your account ({{ $impersonator->name }})
                        </button>
                    </form>
                </div>
            @endif

            <header class="topbar">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn btn-sm btn-outline-secondary d-lg-none" id="sidebarToggle" type="button" aria-label="Toggle navigation">
                        <i class="bi bi-list"></i>
                    </button>
                    <div>
                        <h1>@yield('title', 'Dashboard')</h1>
                        @hasSection('breadcrumb')
                            <nav aria-label="breadcrumb">
                                <ol class="breadcrumb">
                                    @yield('breadcrumb')
                                </ol>
                            </nav>
                        @endif
                    </div>
                </div>

                <div class="d-flex align-items-center gap-3">
                    @if (auth()->user()->canSeeCriticalMonitor())
                        @include('layouts._critical_button')
                    @endif
                    @if (auth()->user()->preference('live_updates'))
                        <span class="live-indicator" id="liveIndicator" data-state="live" role="status" title="This page updates by itself">
                            <i class="live-dot" aria-hidden="true"></i><span class="live-label">Live</span>
                        </span>
                    @endif
                    @include('layouts._notification_bell')
                    <div class="dropdown">
                        <button class="user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <x-avatar :user="auth()->user()" class="user-avatar" />
                            <span class="d-none d-sm-inline">{{ auth()->user()->name ?? 'Account' }}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">{{ auth()->user()->email ?? '' }}</h6></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="{{ route('admin.settings.edit') }}">
                                    <i class="bi bi-gear me-1"></i> Settings
                                </a>
                            </li>
                            @if (\App\Impersonation::isActive())
                                <li>
                                    <form method="POST" action="{{ route('admin.impersonation.leave') }}">
                                        @csrf
                                        <button type="submit" class="dropdown-item">
                                            <i class="bi bi-box-arrow-left me-1"></i> Back to your account
                                        </button>
                                    </form>
                                </li>
                            @endif
                            <li>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item text-danger">
                                        <i class="bi bi-box-arrow-right me-1"></i> Log out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </div>
            </header>

            @php
                // The page body is what live updates swap (see public/js/live.js),
                // except on the pages for users, roles, permissions, settings,
                // attendance, the logs and backups, which aren't live — a refresh mid-way would wipe
                // what's being filled in. Its hash lets a refresh tell whether anything
                // in it actually changed.
                $liveBody = ! request()->routeIs('admin.users.*', 'admin.roles.*', 'admin.permissions.*', 'admin.settings.*', 'admin.attendance.*', 'admin.logs.*', 'admin.backups.*');
                $pageContent = $__env->yieldContent('content');
            @endphp
            <main class="page-body" id="live-main" data-live="{{ $liveBody ? 'on' : 'off' }}" data-live-hash="{{ md5($pageContent) }}">
                @if (session('status'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('status') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                @endif

                {!! $pageContent !!}
            </main>
        </div>
    </div>

    @include('layouts._user_card')
    @if (auth()->user()->canSeeCriticalMonitor())
        @include('layouts._critical_modal')
    @endif
    @if (auth()->user()->preference('celebrations'))
        @include('layouts._celebration')
    @endif

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/admin.js') }}?v={{ filemtime(public_path('js/admin.js')) }}"></script>
    <script src="{{ asset('js/live.js') }}?v={{ filemtime(public_path('js/live.js')) }}"></script>
    @if (auth()->user()->canSeeCriticalMonitor())
        <script src="{{ asset('js/critical-monitor.js') }}?v={{ filemtime(public_path('js/critical-monitor.js')) }}"></script>
    @endif
    @stack('scripts')
</body>
</html>
