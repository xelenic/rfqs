@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="dashboard-header mb-4">
        <div>
            <div class="dashboard-eyebrow">{{ now()->format('l, F j, Y') }}</div>
            <h2 class="dashboard-title">Welcome back, {{ explode(' ', auth()->user()->name)[0] }} 👋</h2>
            <p class="dashboard-subtitle mb-0">Here's what's happening across {{ config('app.name', 'RFQMS') }} today.</p>
        </div>

        @can('rfqs.create')
            <a href="{{ route('admin.rfqs.index') }}" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> New RFQ
            </a>
        @endcan
    </div>

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
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <h2 class="h6 fw-bold mb-0">RFQ Activity</h2>

                    <div class="range-filter" role="group" aria-label="Time range">
                        @foreach (\App\Http\Controllers\Admin\DashboardController::RANGES as $r)
                            @php
                                $rangeLabel = match ($r) {
                                    1 => '1 Day',
                                    7 => '7 Days',
                                    30 => '1 Month',
                                    90 => '3 Months',
                                };
                            @endphp
                            <a href="{{ route('admin.dashboard', ['range' => $r]) }}"
                               class="range-filter-btn {{ $rfqActivity['range'] === $r ? 'active' : '' }}">
                                {{ $rangeLabel }}
                            </a>
                        @endforeach
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6 col-xl-6">
                        <div class="stat-card d-flex align-items-center justify-content-between">
                            <div>
                                <div class="stat-label">Pending RFQs</div>
                                <div class="stat-value">{{ $rfqActivity['pendingTotal'] }}</div>
                            </div>
                            <div class="stat-icon stat-icon-warning"><i class="bi bi-hourglass-split"></i></div>
                        </div>
                    </div>

                    <div class="col-sm-6 col-xl-6">
                        <div class="stat-card d-flex align-items-center justify-content-between">
                            <div>
                                <div class="stat-label">Completed RFQs</div>
                                <div class="stat-value">{{ $rfqActivity['completedTotal'] }}</div>
                            </div>
                            <div class="stat-icon stat-icon-success"><i class="bi bi-check2-circle"></i></div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>RFQs created over time</span>
                        <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-table"
                                data-target="rfqActivityTable" data-chart-target="rfqActivityChart">
                            <i class="bi bi-table"></i> View as table
                        </button>
                    </div>
                    <div class="card-body">
                        <div id="rfqActivityChart" class="rfq-chart" data-chart="{{ json_encode($rfqActivity) }}"></div>

                        <div id="rfqActivityTable" class="table-responsive d-none">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Period</th>
                                        <th>Pending</th>
                                        <th>Completed</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rfqActivity['labels'] as $i => $label)
                                        <tr>
                                            <td>{{ $label }}</td>
                                            <td>{{ $rfqActivity['pending'][$i] }}</td>
                                            <td>{{ $rfqActivity['completed'][$i] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>Recent RFQs</span>
                        <a href="{{ route('admin.rfqs.index') }}" class="small fw-semibold">View all <i class="bi bi-arrow-right"></i></a>
                    </div>

                    @if ($recentRfqs->isNotEmpty())
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>WC Number</th>
                                        <th>Subject</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Created</th>
                                        <th class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($recentRfqs as $rfq)
                                        <tr>
                                            <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                            <td>{{ $rfq->subject }}</td>
                                            <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                            <td><span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->statusLabel() }}</span></td>
                                            <td class="text-muted-soft">{{ $rfq->created_at->diffForHumans() }}</td>
                                            <td class="text-end">
                                                <a href="{{ route('admin.rfqs.show', $rfq) }}" class="btn btn-sm btn-outline-secondary" title="View details">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <div class="card-body text-center py-4">
                            <p class="text-muted-soft mb-2">No RFQs yet.</p>
                            @can('rfqs.create')
                                <a href="{{ route('admin.rfqs.index') }}" class="btn btn-sm btn-primary">
                                    <i class="bi bi-plus-lg"></i> Create the first one
                                </a>
                            @endcan
                        </div>
                    @endif
                </div>
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
            <div class="card">
                <div class="card-header">Notifications</div>
                <div class="card-body">
                    @forelse ($notifications as $notice)
                        <a href="{{ $notice['url'] }}" class="dashboard-notice dashboard-notice-{{ $notice['type'] }}">
                            <span class="dashboard-notice-icon"><i class="bi {{ $notice['icon'] }}"></i></span>
                            <span>
                                <span class="dashboard-notice-title">{{ $notice['title'] }}</span>
                                <span class="dashboard-notice-desc">{{ $notice['description'] }}</span>
                            </span>
                        </a>
                    @empty
                        <div class="dashboard-notice-empty">
                            <i class="bi bi-check2-circle"></i>
                            <p class="fw-semibold mb-1">You're all caught up!</p>
                            <p class="text-muted-soft small mb-0">No outstanding alerts right now.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/rfq-chart.js') }}"></script>
@endpush
