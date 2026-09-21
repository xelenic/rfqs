@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    @include('admin.dashboard._header')

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Users</div>
                            <div class="stat-value">{{ $usersCount }}</div>
                        </div>
                        <div class="stat-icon"><i class="bi bi-people"></i></div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Roles</div>
                            <div class="stat-value">{{ $rolesCount }}</div>
                        </div>
                        <div class="stat-icon stat-icon-violet"><i class="bi bi-shield-check"></i></div>
                    </div>
                </div>

                <div class="col-sm-6 col-xl-4">
                    <div class="stat-card d-flex align-items-center justify-content-between">
                        <div>
                            <div class="stat-label">Permissions</div>
                            <div class="stat-value">{{ $permissionsCount }}</div>
                        </div>
                        <div class="stat-icon stat-icon-teal"><i class="bi bi-key"></i></div>
                    </div>
                </div>
            </div>

            @if ($rfqActivity)
                @include('admin.dashboard._activity')
                @include('admin.dashboard._recent_rfqs')
            @else
                <div class="card">
                    <div class="card-body">
                        <h2 class="h6 fw-bold mb-2">You're all set</h2>
                        <p class="text-muted-soft mb-0">
                            Use the sidebar to manage users, roles and permissions via Spatie's role &amp; permission package.
                        </p>
                    </div>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            @include('admin.dashboard._notifications')
        </div>
    </div>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
    <script src="{{ asset('js/rfq-chart.js') }}?v={{ filemtime(public_path('js/rfq-chart.js')) }}"></script>
@endpush
