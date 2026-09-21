<!DOCTYPE html>
<html lang="en">
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
    <div class="app-shell">
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <img src="{{ asset('logo.png') }}" alt="{{ \App\Models\Setting::companyName() }}" class="sidebar-logo">
            </div>

            <nav class="sidebar-nav">
                <div class="sidebar-section-title">Main</div>
                <a href="{{ route('admin.dashboard') }}" class="nav-link {{ request()->routeIs('admin.dashboard') ? 'active' : '' }}">
                    <i class="bi bi-grid-1x2"></i> Dashboard
                </a>
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
                    @if (auth()->user()->preference('live_updates'))
                        <span class="live-indicator" id="liveIndicator" data-state="live" role="status" title="This page updates by itself">
                            <i class="live-dot" aria-hidden="true"></i><span class="live-label">Live</span>
                        </span>
                    @endif
                    <div class="dropdown">
                        <button class="user-menu-btn dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="user-avatar">{{ strtoupper(substr(auth()->user()->name ?? '?', 0, 1)) }}</span>
                            <span>{{ auth()->user()->name ?? 'Account' }}</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><h6 class="dropdown-header">{{ auth()->user()->email ?? '' }}</h6></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item" href="{{ route('admin.settings.edit') }}">
                                    <i class="bi bi-gear me-1"></i> Settings
                                </a>
                            </li>
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
                // except on the pages for users, roles, permissions and settings,
                // which aren't live. Its hash lets a refresh tell whether anything
                // in it actually changed.
                $liveBody = ! request()->routeIs('admin.users.*', 'admin.roles.*', 'admin.permissions.*', 'admin.settings.*');
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
    @if (auth()->user()->preference('celebrations'))
        @include('layouts._celebration')
    @endif

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="{{ asset('js/admin.js') }}?v={{ filemtime(public_path('js/admin.js')) }}"></script>
    <script src="{{ asset('js/live.js') }}?v={{ filemtime(public_path('js/live.js')) }}"></script>
    @stack('scripts')
</body>
</html>
