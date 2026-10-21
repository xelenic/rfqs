{{--
    One person's page, from the Users list (UserController::show()): who they
    are and their photo, with the list's own actions — message them, a
    set-password link, Edit, their photo changed or removed; their working
    hours and extra hours, on those tabs — each coming back here; then a tab
    each for their RFQ work in numbers and details
    (Overview), the parts in their hands now, what they've done lately, the
    week their time counts in, and, for the people whose time is tracked, their
    attendance — the leaves and half days they've taken (also at a glance under
    their name) and the last two weeks — and their extra hours. An RFQ links to
    its page only for those who can open RFQs (HR Executive can't).

    Expects: $user (with roles, and extraWorkingHours — the current ones),
    $isTracked, $canManage, $roles, $work, $partsInHand, $activity,
    $attendance and $timeOff (UserController::timeOffOf()) (each null while
    attendance isn't kept, or not tracked),
    $workingWeek and $weekSource (the week their time counts in today, and
    where it's from), $ownHours (their own WorkingSchedule, or null),
    $fallbackHours (what they'd work without it, in words), $dataEntryPeople
    (for an Admin on a Sourcing member's page: who in Data Entry they can be
    given — empty otherwise).
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
        $hoursSourceLabel = match ($weekSource['source']) {
            'person' => 'Their own hours',
            'role' => $weekSource['role']."'s hours",
            default => 'The working hours',
        };
        $attendanceLooks = [
            \App\Models\Attendance::PRESENT => ['Present', 'badge-soft-success'],
            \App\Models\Attendance::ABSENT => ['On leave', 'badge-soft-danger'],
            \App\Models\Attendance::MORNING_OFF => ['Morning off', 'badge-soft-info'],
            \App\Models\Attendance::AFTERNOON_OFF => ['Afternoon off', 'badge-soft-info'],
            \App\Models\Attendance::UNMARKED => ['Not marked yet', 'badge-soft-secondary'],
        ];
    @endphp

    {{-- Deactivated: they can't sign in, and aren't picked for work. --}}
    @if ($user->isDeactivated())
        <div class="alert alert-secondary d-flex align-items-center gap-2" role="status">
            <i class="bi bi-slash-circle"></i>
            <div>
                <strong>This account is deactivated</strong> — since {{ $user->deactivated_at->toBusinessTimezone()->format('M d, Y g:i A') }}{{ $user->deactivatedBy ? ', by '.$user->deactivatedBy->name : '' }}.
                They can't sign in, and aren't offered for any work. Everything they did stays as it was.
            </div>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body user-profile">
            @include('admin.users._photo', ['person' => $user, 'returnTo' => 'show', 'avatarClass' => 'settings-avatar user-profile-avatar'])
            <div class="user-profile-who">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h2 class="user-profile-name">{{ $user->name }}</h2>
                    @if ($isYou)
                        <span class="badge badge-soft-info">You</span>
                    @endif
                    @if ($user->isDeactivated())
                        <span class="badge badge-soft-danger"><i class="bi bi-slash-circle"></i> Deactivated</span>
                    @endif
                </div>
                <div class="user-profile-details">
                    <span><i class="bi bi-envelope"></i> <a href="mailto:{{ $user->email }}">{{ $user->email }}</a></span>
                    <span><i class="bi bi-calendar3"></i> Joined {{ $user->created_at->copy()->setTimezone($timezone)->format('M d, Y') }}</span>
                    {{-- Their time off this year, at a glance — the rest on the Attendance tab. --}}
                    @if ($timeOff)
                        <a href="{{ route('admin.users.show', ['user' => $user, 'tab' => 'attendance']) }}" class="text-reset" data-time-off-summary>
                            <i class="bi bi-calendar-x"></i>
                            This year: {{ $timeOff['tally']['year']['leaves'] }} {{ \Illuminate\Support\Str::plural('leave', $timeOff['tally']['year']['leaves']) }}
                            · {{ $timeOff['tally']['year']['half_days'] }} {{ \Illuminate\Support\Str::plural('half day', $timeOff['tally']['year']['half_days']) }}
                        </a>
                    @endif
                </div>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    @forelse ($user->roles as $role)
                        <span class="badge badge-soft-primary">{{ $role->name }}</span>
                    @empty
                        <span class="badge badge-soft-secondary">No role</span>
                    @endforelse
                </div>
                @error('photo', 'photo')
                    <div class="text-danger small mt-2">{{ $message }}</div>
                @enderror
            </div>

            <div class="user-profile-actions">
                @unless ($isYou || $user->isDeactivated())
                    <a href="{{ route('admin.messages.show', $user) }}" class="btn btn-sm btn-primary">
                        <i class="bi bi-chat-dots"></i> Message
                    </a>
                @endunless
                {{-- The rest, in one menu. --}}
                @php
                    $canSendLink = $canManage && auth()->user()->can('users.create') && ! $user->isDeactivated();
                    $canLogInAs = \App\Impersonation::canStart(auth()->user(), $user);
                    $canDeactivate = auth()->user()->hasRole('Admin') && ! $isYou;
                    $canRemovePhoto = $user->avatar_path && auth()->user()->canChangePhotoOf($user);
                @endphp
                @if ($canSendLink || $canLogInAs || $canDeactivate || $canRemovePhoto)
                    <div class="dropdown user-options d-inline-block">
                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="bi bi-three-dots"></i> More
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                            {{-- A fresh set-password email — for a link that expired or went astray. --}}
                            @if ($canSendLink)
                                <li>
                                    <form action="{{ route('admin.users.password-link', $user) }}" method="POST"
                                          data-confirm="Email {{ $user->name }} a link to set their password? Any link sent before stops working.">
                                        @csrf
                                        <input type="hidden" name="return_to" value="show">
                                        <button type="submit" class="dropdown-item"><i class="bi bi-envelope"></i> Send password link</button>
                                    </form>
                                </li>
                            @endif
                            {{-- An Admin signing in as them (App\Impersonation) — not as another Admin. --}}
                            @if ($canLogInAs)
                                <li>
                                    <form action="{{ route('admin.users.impersonate', $user) }}" method="POST"
                                          data-confirm="Sign in as {{ $user->name }}? You'll see the panel as they do, and whatever you do is done as them. Use &quot;Back to your account&quot; at the top to return.">
                                        @csrf
                                        <button type="submit" class="dropdown-item"><i class="bi bi-incognito"></i> Log in as</button>
                                    </form>
                                </li>
                            @endif
                            {{-- Deactivating keeps the account and its history; nobody signs in with it. --}}
                            @if ($canDeactivate)
                                <li>@include('admin.users._deactivate_button', ['user' => $user, 'asMenuItem' => true])</li>
                            @endif
                            @if ($canRemovePhoto)
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form action="{{ route('admin.users.photo.destroy', $user) }}" method="POST"
                                          data-confirm="Remove {{ $user->name }}'s photo? Their initial shows instead.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-person-x"></i> Remove photo</button>
                                    </form>
                                </li>
                            @endif
                        </ul>
                    </div>
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
        }
        $tabs['working-hours'] = ['label' => 'Working hours', 'icon' => 'bi-clock', 'count' => null];
        if ($isTracked) {
            $tabs['extra-hours'] = ['label' => 'Extra hours', 'icon' => 'bi-moon-stars', 'count' => $user->extraWorkingHours->count()];
        }
        $activeTab = match (true) {
            $errors->user_hours->any() => 'working-hours',
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
                                {{ $activity->first()['label'] }} {{ $activity->first()['rfq']->rfq_number }} · {{ $activity->first()['at']->toBusinessTimezone()->format('M d, Y g:i A') }}
                            @else
                                <span class="text-muted-soft">None yet</span>
                            @endif
                        </dd>
                        @if ($user->defaultForSourcing->isNotEmpty())
                            <dt>Usual Data Entry person for</dt>
                            <dd>{{ $user->defaultForSourcing->pluck('name')->join(', ') }}</dd>
                        @endif
                        @if ($isTracked)
                            <dt>In their hands</dt>
                            <dd>{{ $partsInHand->count() }} {{ \Illuminate\Support\Str::plural('part', $partsInHand->count()) }}</dd>
                            <dt>Time counted</dt>
                            <dd>{{ $hoursSourceLabel }}{{ $user->extraWorkingHours->isNotEmpty() ? ', and their extra hours' : '' }}</dd>
                        @endif
                    </dl>
                </div>
            </div>

            {{-- A Sourcing member's usual person in Data Entry: whom their
                 "Assign to Data Entry" picks to begin with (they can pick
                 someone else). An Admin sets it. --}}
            @if ($user->hasRole('Sourcing'))
                <div class="card mb-4" id="user-default-data-entry">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="bi bi-person-check"></i> Data Entry person
                    </div>
                    <div class="card-body">
                        <p class="small text-muted-soft">
                            When {{ $user->name }} assigns a part to Data Entry, this person is picked to begin with — they can still pick someone else, or leave it with anyone there.
                        </p>
                        @if (auth()->user()->hasRole('Admin'))
                            <form method="POST" action="{{ route('admin.users.default-data-entry', $user) }}" class="d-flex flex-wrap align-items-start gap-2">
                                @csrf
                                @method('PATCH')
                                <div style="min-width: 280px;">
                                    <select name="data_entry_user_id" class="form-select @error('data_entry_user_id', 'default_data_entry') is-invalid @enderror" aria-label="Data Entry person">
                                        <option value="">Anyone in Data Entry</option>
                                        @foreach ($dataEntryPeople as $person)
                                            <option value="{{ $person->id }}" @selected((int) $user->default_data_entry_user_id === $person->id)>{{ $person->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('data_entry_user_id', 'default_data_entry')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save</button>
                            </form>
                        @else
                            <p class="mb-0 fw-semibold">{{ $user->defaultDataEntryUser?->name ?? 'Anyone in Data Entry' }}</p>
                        @endif
                    </div>
                </div>
            @endif
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
                                            <td><span class="badge {{ $item['rfq']->priorityBadgeClass() }}">{{ $item['rfq']->priorityLabel() }}</span></td>
                                            <td><span class="badge {{ $item['badge'] }}">{{ $item['state'] }}</span></td>
                                            <td class="text-muted-soft text-nowrap">{{ $item['since']?->toBusinessTimezone()->format('M d, Y g:i A') ?? '—' }}</td>
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

        {{-- The week their time counts in today — their own, their role's or
             the company's — which an Admin or HR Executive can give them their
             own of. --}}
        <div class="tab-pane fade {{ $activeTab === 'working-hours' ? 'show active' : '' }}" id="user-pane-working-hours" role="tabpanel" aria-labelledby="user-tab-working-hours" tabindex="0">
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span class="d-flex align-items-center gap-2">
                        Their working week
                        <span @class(['badge', 'badge-soft-primary' => $weekSource['source'] !== 'company', 'badge-soft-secondary' => $weekSource['source'] === 'company'])>{{ $hoursSourceLabel }}</span>
                    </span>
                    @if (auth()->user()->canManageWorkingHours())
                        <span class="d-flex gap-2">
                            @if ($ownHours)
                                <form method="POST" action="{{ route('admin.settings.user-hours.destroy', $user) }}"
                                      data-confirm="Take {{ $user->name }}'s own hours away? From today their time counts in {{ $fallbackHours }} — days before keep the hours they had.">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-arrow-counterclockwise"></i> Use {{ $fallbackHours }}
                                    </button>
                                </form>
                            @endif
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#userHoursModal">
                                <i class="bi bi-pencil"></i> {{ $ownHours ? 'Change' : 'Set their own hours' }}
                            </button>
                        </span>
                    @endif
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 user-week">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Working hours</th>
                                <th>Lunch</th>
                                <th class="text-end">Hours</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($workingWeek as $day => $hours)
                                <tr @class(['text-muted-soft' => ! $hours['working']])>
                                    <td class="fw-semibold">{{ ucfirst($day) }}</td>
                                    @if ($hours['working'])
                                        <td style="font-variant-numeric: tabular-nums;">{{ $hours['start'] }} – {{ $hours['end'] }}</td>
                                        <td style="font-variant-numeric: tabular-nums;">{{ $hours['lunch_start'] && $hours['lunch_end'] ? $hours['lunch_start'].' – '.$hours['lunch_end'] : 'None' }}</td>
                                        <td class="text-end text-nowrap">{{ \App\Models\Setting::hoursLabel(\App\Models\Setting::workingMinutes($hours)) }}</td>
                                    @else
                                        <td colspan="2">Day off</td>
                                        <td class="text-end">—</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" class="text-end text-muted-soft">Working week</td>
                                <td class="text-end fw-semibold text-nowrap">{{ \App\Models\Setting::hoursLabel(collect($workingWeek)->sum(fn (array $day) => \App\Models\Setting::workingMinutes($day))) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="card-body border-top">
                    <p class="text-muted-soft small mb-0">
                        @if ($ownHours)
                            Their own hours, from {{ $ownHours->starts_on->format('M d, Y') }} — in place of {{ $fallbackHours }}.
                        @elseif ($weekSource['source'] === 'role')
                            Set for everyone in {{ $weekSource['role'] }} on Settings → Role Hours.
                        @else
                            The company's working hours, from Settings → Working Hours.
                        @endif
                        @if ($user->extraWorkingHours->isNotEmpty())
                            Their extra hours count on top.
                        @endif
                        Their time on RFQs counts only in these hours.
                    </p>
                </div>
            </div>
        </div>

        @if ($isTracked)
            {{-- Their time off — leaves and half days, in numbers and this
                 year's one by one — then their attendance over the last two weeks. --}}
            <div class="tab-pane fade {{ $activeTab === 'attendance' ? 'show active' : '' }}" id="user-pane-attendance" role="tabpanel" aria-labelledby="user-tab-attendance" tabindex="0">
                @if ($timeOff)
                    @php
                        $tally = $timeOff['tally'];
                        $countLabel = fn (array $counts) => $counts['leaves'].' '.\Illuminate\Support\Str::plural('leave', $counts['leaves']).' · '.$counts['half_days'].' '.\Illuminate\Support\Str::plural('half day', $counts['half_days']);
                        $dayLabel = fn (?string $date) => $date ? \Carbon\CarbonImmutable::parse($date)->format('D, M j, Y') : 'None yet';
                        $canOpenSheets = auth()->user()->canViewAttendance();
                        $timeOffStats = [
                            ['label' => 'Leaves this year', 'value' => $tally['year']['leaves'], 'caption' => 'This month: '.$tally['month']['leaves'].' · In all: '.$tally['all']['leaves'], 'icon' => 'bi-person-dash', 'tone' => 'danger'],
                            ['label' => 'Half days this year', 'value' => $tally['year']['half_days'], 'caption' => 'This month: '.$tally['month']['half_days'].' · In all: '.$tally['all']['half_days'], 'icon' => 'bi-circle-half', 'tone' => 'warning'],
                            ['label' => 'Days off this year', 'value' => \App\Models\Attendance::daysOffLabel($tally['year']), 'caption' => 'A half day counts as half · In all: '.\App\Models\Attendance::daysOffLabel($tally['all']), 'icon' => 'bi-calendar-x', 'tone' => 'violet'],
                            ['label' => 'Last time off', 'value' => $dayLabel(collect([$tally['last_leave'], $tally['last_half_day']])->filter()->max()), 'caption' => 'Last leave: '.$dayLabel($tally['last_leave']).' · Last half day: '.$dayLabel($tally['last_half_day']), 'icon' => 'bi-clock-history', 'tone' => ''],
                        ];
                        // What's on sheets not yet approved: "1 leave and 2 half days".
                        $pendingLabel = collect([
                            $tally['pending']['leaves'] > 0 ? $tally['pending']['leaves'].' '.\Illuminate\Support\Str::plural('leave', $tally['pending']['leaves']) : null,
                            $tally['pending']['half_days'] > 0 ? $tally['pending']['half_days'].' '.\Illuminate\Support\Str::plural('half day', $tally['pending']['half_days']) : null,
                        ])->filter()->join(' and ');
                    @endphp

                    <div class="row g-3 mb-3" data-time-off-stats>
                        @foreach ($timeOffStats as $stat)
                            <div class="col-6 col-xl-3">
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
                    @if ($pendingLabel !== '')
                        <p class="text-muted-soft small mb-3">
                            <i class="bi bi-hourglass-split"></i>
                            Not counted yet: {{ $pendingLabel }} on sheets HR Executive hasn't approved yet.
                        </p>
                    @endif

                    <div class="card mb-4">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <span>Leaves and half days this year</span>
                            <span class="text-muted-soft small fw-normal">{{ $countLabel($tally['year']) }}</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Leave</th>
                                        <th>Note</th>
                                        <th>HR approval</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($timeOff['days'] as $line)
                                        <tr data-time-off-day="{{ $line->sheet->date->toDateString() }}">
                                            <td class="fw-semibold text-nowrap">
                                                @if ($canOpenSheets)
                                                    <a href="{{ route('admin.attendance.show', $line->sheet) }}" class="text-reset">{{ $line->sheet->date->format('D, M j, Y') }}</a>
                                                @else
                                                    {{ $line->sheet->date->format('D, M j, Y') }}
                                                @endif
                                            </td>
                                            <td class="small">
                                                @include('admin.attendance._leave_type', ['line' => $line, 'date' => $line->sheet->date->toDateString()])
                                            </td>
                                            <td class="small">
                                                @if ($line->reason)
                                                    <span class="badge badge-soft-danger">{{ $line->reason }}</span>
                                                @endif
                                                {{ $line->note }}
                                                @if (! $line->reason && ! $line->note)
                                                    <span class="text-muted-soft">—</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($line->sheet->isApproved())
                                                    <span class="badge badge-soft-success"><i class="bi bi-check2-circle"></i> Approved</span>
                                                @elseif ($line->sheet->isReturned())
                                                    <span class="badge badge-soft-danger"><i class="bi bi-arrow-counterclockwise"></i> Returned — not counted yet</span>
                                                @else
                                                    <span class="badge badge-soft-warning"><i class="bi bi-hourglass-split"></i> Awaiting approval — not counted yet</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="text-center text-muted-soft py-4">No leaves or half days this year.</td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif

                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>Last two weeks</span>
                        <span class="text-muted-soft small fw-normal">As HR Executive approved it</span>
                    </div>
                    <div class="card-body">
                        @if ($attendance === null)
                            <p class="text-muted-soft small mb-0">Attendance isn't being kept yet — it starts from the day set on Settings → Working Hours.</p>
                        @elseif ($attendance === [])
                            <p class="text-muted-soft small mb-0">No working days since attendance started.</p>
                        @else
                            <ul class="list-unstyled user-attendance mb-0">
                                @foreach ($attendance as $day)
                                    @php [$statusLabel, $statusBadge] = $attendanceLooks[$day['status']] ?? [\App\Models\Attendance::timeOffLabelOf($day['status']) ?? $day['status'], 'badge-soft-info']; @endphp
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

            {{-- The extra hours their time counts in, besides their working week. --}}
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

    @if (auth()->user()->canManageWorkingHours())
        @include('admin.settings._week_modal', [
            'modalId' => 'userHoursModal',
            'title' => $user->name.' — their own working hours',
            'subtitle' => 'Their time counts in these hours, in place of '.$fallbackHours.'.',
            'action' => route('admin.settings.user-hours.update', $user),
            'week' => $workingWeek,
            'bag' => 'user_hours',
            'refill' => $errors->user_hours->any(),
            'idPrefix' => 'user-hours',
            'hidden' => [],
        ])
    @endif

    {{-- An edit, or extra hours, that didn't go in: its window open again, as it was sent. --}}
    @php
        $reopenModal = match (true) {
            $errors->edit->any() => 'editUserModal',
            $errors->user_hours->any() => 'userHoursModal',
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
