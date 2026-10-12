{{--
    One person's page, from the Users list (UserController::show()): who they
    are, with the list's own actions — message them, a set-password link,
    Edit; and their extra hours, on that tab — each coming back here; then a tab each for
    their RFQ work in numbers and details (Overview), the parts in their
    hands now, what they've done lately, and, for the people whose time is
    tracked, the last two weeks' attendance and their extra hours. An RFQ links to its page only for those who can open
    RFQs (HR Manager can't).

    Expects: $user (with roles, and extraWorkingHours — the current ones),
    $isTracked, $canManage, $roles, $work, $partsInHand, $activity,
    $attendance (null while attendance isn't kept, or not tracked).
--}}
@extends('layouts.app')

@section('title', $user->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('admin.users.index') }}">Users</a></li>
    <li class="breadcrumb-item active" aria-current="page">{{ $user->name }}</li>
@endsection

@section('content')
    @php
        $timezone = \App\Models\Setting::timezone();
        $isYou = $user->id === auth()->id();
        $canOpenRfqs = auth()->user()->can('rfqs.view');
        $attendanceLooks = [
            \App\Models\Attendance::PRESENT => ['Present', 'badge-soft-success'],
            \App\Models\Attendance::ABSENT => ['On leave', 'badge-soft-danger'],
            \App\Models\Attendance::MORNING_OFF => ['Morning off', 'badge-soft-info'],
            \App\Models\Attendance::AFTERNOON_OFF => ['Afternoon off', 'badge-soft-info'],
            \App\Models\Attendance::UNMARKED => ['Not marked yet', 'badge-soft-secondary'],
        ];
    @endphp

    <div class="card mb-4">
        <div class="card-body user-profile">
            <span class="settings-avatar user-profile-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
            <div class="user-profile-who">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h2 class="user-profile-name">{{ $user->name }}</h2>
                    @if ($isYou)
                        <span class="badge badge-soft-info">You</span>
                    @endif
                </div>
                <div class="user-profile-details">
                    <span><i class="bi bi-envelope"></i> <a href="mailto:{{ $user->email }}">{{ $user->email }}</a></span>
                    <span><i class="bi bi-calendar3"></i> Joined {{ $user->created_at->copy()->setTimezone($timezone)->format('M d, Y') }}</span>
                </div>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    @forelse ($user->roles as $role)
                        <span class="badge badge-soft-primary">{{ $role->name }}</span>
                    @empty
                        <span class="badge badge-soft-secondary">No role</span>
                    @endforelse
                </div>
            </div>

            <div class="user-profile-actions">
                @unless ($isYou)
                    <a href="{{ route('admin.messages.show', $user) }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-chat-dots"></i> Message
                    </a>
                @endunless
                {{-- A fresh set-password email — for a link that expired or went astray. --}}
                @if ($canManage && auth()->user()->can('users.create'))
                    <form action="{{ route('admin.users.password-link', $user) }}" method="POST" class="d-inline"
                          data-confirm="Email {{ $user->name }} a link to set their password? Any link sent before stops working.">
                        @csrf
                        <input type="hidden" name="return_to" value="show">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-envelope"></i> Send password link
                        </button>
                    </form>
                @endif
                @if ($canManage && auth()->user()->can('users.edit'))
                    <button type="button" class="btn btn-sm btn-outline-secondary js-edit-user"
                            data-bs-toggle="modal" data-bs-target="#editUserModal"
                            data-action="{{ route('admin.users.update', $user) }}"
                            data-id="{{ $user->id }}"
                            data-name="{{ $user->name }}"
                            data-email="{{ $user->email }}"
                            data-roles="{{ $user->roles->pluck('name')->join(',') }}">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Tabs under the header: what they do here at a glance, the parts in
         their hands, what they've done lately and — whose time is tracked —
         their attendance and extra hours. The one open is kept in the
         address (?tab=, admin.js), so a reload stays on it. --}}
    @php
        $tabs = ['overview' => ['label' => 'Overview', 'icon' => 'bi-grid-1x2', 'count' => null]];
        if ($isTracked || $partsInHand->isNotEmpty()) {
            $tabs['in-hand'] = ['label' => 'In their hands', 'icon' => 'bi-hourglass-split', 'count' => $partsInHand->count()];
        }
        $tabs['activity'] = ['label' => 'Recent activity', 'icon' => 'bi-clock-history', 'count' => null];
        if ($isTracked) {
            $tabs['attendance'] = ['label' => 'Attendance', 'icon' => 'bi-calendar-check', 'count' => null];
            $tabs['extra-hours'] = ['label' => 'Extra hours', 'icon' => 'bi-moon-stars', 'count' => $user->extraWorkingHours->count()];
        }
        $activeTab = match (true) {
            $errors->extra_hours->any() && isset($tabs['extra-hours']) => 'extra-hours',
            array_key_exists((string) request('tab'), $tabs) => (string) request('tab'),
            default => 'overview',
        };
    @endphp

    <ul class="nav nav-pills rfq-view-toggle user-tabs mb-3" role="tablist" aria-label="{{ $user->name }}">
        @foreach ($tabs as $slug => $tab)
            <li class="nav-item" role="presentation">
                <button type="button" class="nav-link {{ $activeTab === $slug ? 'active' : '' }}" id="user-tab-{{ $slug }}"
                        data-bs-toggle="pill" data-bs-target="#user-pane-{{ $slug }}" data-user-tab="{{ $slug }}"
                        role="tab" aria-controls="user-pane-{{ $slug }}" aria-selected="{{ $activeTab === $slug ? 'true' : 'false' }}">
                    <i class="bi {{ $tab['icon'] }}"></i> {{ $tab['label'] }}
                    @if ($tab['count'] !== null)
                        <span class="rfq-tab-count">{{ $tab['count'] }}</span>
                    @endif
                </button>
            </li>
        @endforeach
    </ul>

    <div class="tab-content">
        {{-- Overview: their RFQ work in numbers, and the details on record. --}}
        <div class="tab-pane fade {{ $activeTab === 'overview' ? 'show active' : '' }}" id="user-pane-overview" role="tabpanel" aria-labelledby="user-tab-overview" tabindex="0">
            @if ($work !== [])
                <div class="row g-3 mb-4">
                    @foreach ($work as $figure)
                        <div class="col-sm-6 col-xl-3">
                            <div class="stat-card d-flex align-items-center justify-content-between">
                                <div>
                                    <div class="stat-label">{{ $figure['label'] }}</div>
                                    <div class="stat-value">{{ $figure['value'] }}</div>
                                    <div class="stat-caption">{{ $figure['caption'] }}</div>
                                </div>
                                <div class="stat-icon stat-icon-{{ $figure['tone'] }}"><i class="bi {{ $figure['icon'] }}"></i></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="card mb-4">
                <div class="card-header">Details</div>
                <div class="card-body">
                    <dl class="user-facts mb-0">
                        <dt>Name</dt>
                        <dd>{{ $user->name }}</dd>
                        <dt>Email</dt>
                        <dd><a href="mailto:{{ $user->email }}">{{ $user->email }}</a></dd>
                        <dt>Roles</dt>
                        <dd>{{ $user->roles->pluck('name')->join(', ') ?: 'No role' }}</dd>
                        <dt>Joined</dt>
                        <dd>{{ $user->created_at->copy()->setTimezone($timezone)->format('M d, Y') }}</dd>
                        <dt>Last step taken</dt>
                        <dd>
                            @if ($activity->isNotEmpty())
                                {{ $activity->first()['label'] }} {{ $activity->first()['rfq']->rfq_number }} · {{ $activity->first()['at']->diffForHumans() }}
                            @else
                                <span class="text-muted-soft">None yet</span>
                            @endif
                        </dd>
                        @if ($isTracked)
                            <dt>In their hands</dt>
                            <dd>{{ $partsInHand->count() }} {{ \Illuminate\Support\Str::plural('part', $partsInHand->count()) }}</dd>
                            <dt>Time counted</dt>
                            <dd>The working hours{{ $user->extraWorkingHours->isNotEmpty() ? ', and their extra hours' : '' }}</dd>
                        @endif
                    </dl>
                </div>
            </div>
        </div>

        {{-- The parts in their hands now. --}}
        @isset($tabs['in-hand'])
            <div class="tab-pane fade {{ $activeTab === 'in-hand' ? 'show active' : '' }}" id="user-pane-in-hand" role="tabpanel" aria-labelledby="user-tab-in-hand" tabindex="0">
                <div class="card mb-4">
                    @if ($partsInHand->isEmpty())
                        <div class="card-body">
                            <p class="text-muted-soft small mb-0">Nothing in their hands right now.</p>
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead>
                                    <tr>
                                        <th>RFQ</th>
                                        <th>Subject</th>
                                        <th>Priority</th>
                                        <th>Where it is</th>
                                        <th>Since</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($partsInHand as $item)
                                        <tr>
                                            <td class="text-nowrap fw-semibold">
                                                @if ($canOpenRfqs)
                                                    <a href="{{ route('admin.rfqs.show', $item['rfq']) }}?status=Pending">{{ $item['label'] }}</a>
                                                @else
                                                    {{ $item['label'] }}
                                                @endif
                                            </td>
                                            <td>{{ $item['rfq']->subject }}</td>
                                            <td><span class="badge {{ $item['rfq']->priorityBadgeClass() }}">{{ $item['rfq']->priority_level }}</span></td>
                                            <td><span class="badge {{ $item['badge'] }}">{{ $item['state'] }}</span></td>
                                            <td class="text-muted-soft text-nowrap">{{ $item['since']?->diffForHumans() ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>
        @endisset

        {{-- What they've done lately. --}}
        <div class="tab-pane fade {{ $activeTab === 'activity' ? 'show active' : '' }}" id="user-pane-activity" role="tabpanel" aria-labelledby="user-tab-activity" tabindex="0">
            <div class="card mb-4">
                <div class="card-body">
                    @forelse ($activity as $event)
                        @php $eventAt = $event['at']->copy()->setTimezone($timezone); @endphp
                        @if ($canOpenRfqs)
                            <a href="{{ route('admin.rfqs.show', $event['rfq']) }}" class="today-feed-item tone-{{ $event['tone'] }}">
                        @else
                            <div class="today-feed-item tone-{{ $event['tone'] }}">
                        @endif
                            <span class="today-feed-icon"><i class="bi {{ $event['icon'] }}"></i></span>
                            <span class="today-feed-text">
                                {{ $event['label'] }} <strong>{{ $event['rfq']->rfq_number }}</strong>
                                @if ($event['context'])
                                    <span class="text-muted-soft">· {{ $event['context'] }}</span>
                                @endif
                            </span>
                            <span class="today-feed-time" title="{{ $eventAt->format('M d, Y g:i A') }}">{{ $eventAt->format('M j, g:i A') }}</span>
                        @if ($canOpenRfqs)
                            </a>
                        @else
                            </div>
                        @endif
                    @empty
                        <p class="text-muted-soft small mb-0">Nothing done on any RFQ yet.</p>
                    @endforelse
                </div>
            </div>
        </div>

        @if ($isTracked)
            {{-- Their attendance over the last two weeks. --}}
            <div class="tab-pane fade {{ $activeTab === 'attendance' ? 'show active' : '' }}" id="user-pane-attendance" role="tabpanel" aria-labelledby="user-tab-attendance" tabindex="0">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>Last two weeks</span>
                        <span class="text-muted-soft small fw-normal">As HR Manager approved it</span>
                    </div>
                    <div class="card-body">
                        @if ($attendance === null)
                            <p class="text-muted-soft small mb-0">Attendance isn't being kept yet — it starts from the day set on Settings → Working Hours.</p>
                        @elseif ($attendance === [])
                            <p class="text-muted-soft small mb-0">No working days since attendance started.</p>
                        @else
                            <ul class="list-unstyled user-attendance mb-0">
                                @foreach ($attendance as $day)
                                    @php [$statusLabel, $statusBadge] = $attendanceLooks[$day['status']] ?? [$day['status'], 'badge-soft-secondary']; @endphp
                                    <li>
                                        <span>{{ $day['date']->format('D, M j') }}</span>
                                        <span class="badge {{ $statusBadge }}">{{ $statusLabel }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <div class="form-text mt-2">A day still waiting on a sheet, or its approval, isn't marked yet.</div>
                        @endif
                    </div>
                </div>
            </div>

            {{-- The extra hours their time counts in, besides the working hours. --}}
            <div class="tab-pane fade {{ $activeTab === 'extra-hours' ? 'show active' : '' }}" id="user-pane-extra-hours" role="tabpanel" aria-labelledby="user-tab-extra-hours" tabindex="0">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>Their time counts in these hours too</span>
                        @if (auth()->user()->hasRole('Admin'))
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#extraHoursModal-{{ $user->id }}">
                                <i class="bi bi-pencil"></i> Change
                            </button>
                        @endif
                    </div>
                    <div class="card-body">
                        @forelse ($user->extraWorkingHours as $range)
                            <div class="d-flex align-items-center justify-content-between gap-2 {{ $loop->last ? '' : 'mb-2' }}">
                                <span class="fw-semibold" style="font-variant-numeric: tabular-nums;">{{ $range->start }} – {{ $range->end }}</span>
                                <span class="text-muted-soft small text-end">{{ $range->daysLabel() }} · from {{ $range->starts_on->format('M d, Y') }}</span>
                            </div>
                        @empty
                            <p class="text-muted-soft small mb-0">None — their time counts in the working hours only.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($canManage && auth()->user()->can('users.edit'))
        @include('admin.users._edit_modal', ['roles' => $roles, 'returnTo' => 'show'])
    @endif

    @if ($isTracked && auth()->user()->hasRole('Admin'))
        @include('admin.users._extra_hours_modal', ['user' => $user])
    @endif

    {{-- An edit, or extra hours, that didn't go in: its window open again, as it was sent. --}}
    @php
        $reopenModal = match (true) {
            $errors->edit->any() => 'editUserModal',
            $errors->extra_hours->any() && old('return_to') === 'users' => 'extraHoursModal-'.$user->id,
            default => null,
        };
    @endphp
    @if ($reopenModal)
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById(@json($reopenModal));
                    if (modalEl) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif
@endsection
