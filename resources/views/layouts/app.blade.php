<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') · {{ config('app.name', 'RFQMS') }} Admin</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @stack('styles')
</head>
<body>
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <img src="{{ asset('logo.png') }}" alt="{{ config('app.name', 'RFQMS') }}" class="sidebar-logo">
            </div>

            <nav class="sidebar-nav">
                <div class="sidebar-section-title">Main</div>
                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2"></i> Dashboard
                </a>

                @can('rfqs.view')
                    @php
                        // Each role's own actionable backlog on their "Pending
                        // RFQs" link, as a red count so it doesn't get lost in
                        // the list — Operations: not yet assigned to Sourcing;
                        // Sourcing: their own split not yet marked complete;
                        // Data Entry: Sourcing-completed splits they haven't
                        // marked complete yet — one per assignee, matching the
                        // "By Sourcing" rows (see RfqController::index()
                        // $bySourcingRfqs), since a split RFQ can hand off
                        // more than one assignee's part to Data Entry.
                        $pendingBadgeCount = match (true) {
                            auth()->user()->hasRole('Senior Operations') => \App\Models\Rfq::where('status', 'Pending')
                                ->doesntHave('assignees')
                                ->count(),
                            auth()->user()->hasRole('Sourcing') => \App\Models\Rfq::where('status', 'Pending')
                                ->whereHas('assignees', fn ($q) => $q->whereKey(auth()->id())->whereNull('rfq_user.completed_at'))
                                ->count(),
                            auth()->user()->hasRole('Data Entry') => \Illuminate\Support\Facades\DB::table('rfq_user')
                                ->whereNotNull('completed_at')
                                ->whereNull('data_entry_completed_at')
                                ->count(),
                            auth()->user()->hasRole('Head of Business Development') => \App\Models\Rfq::where('stage', 'head_of_bd_review')->count(),
                            auth()->user()->hasRole('GM Assistant') => \App\Models\Rfq::where('stage', 'gm_assistant')->count(),
                            auth()->user()->hasRole('General Manager') => \App\Models\Rfq::where('stage', 'gm_review')->count(),
                            default => 0,
                        };
                        $pendingBadgeTitle = match (true) {
                            auth()->user()->hasRole('Senior Operations') => "{$pendingBadgeCount} not yet assigned to Sourcing",
                            auth()->user()->hasRole('Sourcing') => "{$pendingBadgeCount} not marked complete",
                            auth()->user()->hasRole('Data Entry') => "{$pendingBadgeCount} not marked complete",
                            auth()->user()->hasRole('Head of Business Development') => "{$pendingBadgeCount} awaiting your review",
                            auth()->user()->hasRole('GM Assistant') => "{$pendingBadgeCount} awaiting client details",
                            auth()->user()->hasRole('General Manager') => "{$pendingBadgeCount} awaiting your approval",
                            default => '',
                        };
                    @endphp
                    <div class="sidebar-section-title">Requests</div>
                    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('status') === 'Pending' && ! in_array(request('view'), ['returns', 'review', 'closing'], true) ? 'active' : '' }}">
                        <i class="bi bi-hourglass-split"></i>
                        <span class="nav-link-label">
                            {{ match (true) {
                                auth()->user()->hasRole('Sourcing') => 'My Pending RFQs',
                                auth()->user()->hasRole('Data Entry') => 'Ready for Data Entry',
                                auth()->user()->hasRole('Senior Operations') => 'Unassigned RFQs',
                                auth()->user()->hasRole('Head of Business Development') => 'Review',
                                auth()->user()->hasRole('GM Assistant') => 'Review',
                                auth()->user()->hasRole('General Manager') => 'Review',
                                default => 'Pending RFQs',
                            } }}
                        </span>
                        @if ($pendingBadgeCount > 0)
                            <span class="nav-link-count" title="{{ $pendingBadgeTitle }}">{{ $pendingBadgeCount }}</span>
                        @endif
                    </a>
                    @if (auth()->user()->hasRole('Sourcing'))
                        <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
                            <i class="bi bi-arrow-counterclockwise"></i> Returns
                        </a>
                    @endif
                    @if (auth()->user()->hasRole('Senior Operations'))
                        <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'review' ? 'active' : '' }}">
                            <i class="bi bi-clipboard2-check"></i> Review
                        </a>
                    @endif
                    @if (auth()->user()->hasRole('Business Development'))
                        <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'closing' ? 'active' : '' }}">
                            <i class="bi bi-flag"></i> Ready to Close
                        </a>
                    @endif
                    <a href="{{ route('admin.rfqs.index', ['status' => 'Completed']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('status') === 'Completed' ? 'active' : '' }}">
                        <i class="bi bi-check2-circle"></i> Closed RFQs
                    </a>
                @endcan

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
            </nav>

            <div class="sidebar-footer">
                &copy; {{ now()->year }} {{ config('app.name', 'RFQMS') }}
            </div>
        </aside>

        <div class="main-col">
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

                <div class="dropdown">
                    <button class="user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="user-avatar">{{ strtoupper(substr(auth()->user()->name ?? '?', 0, 1)) }}</span>
                        <span>{{ auth()->user()->name ?? 'Account' }}</span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header">{{ auth()->user()->email ?? '' }}</h6></li>
                        <li><hr class="dropdown-divider"></li>
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
            </header>

            <main class="page-body">
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

                @yield('content')
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/admin.js') }}"></script>
    @stack('scripts')
</body>
</html>
