{{--
    The Settings page (see SettingsController), one tab per part: Profile,
    Password and Preferences for everyone, and for an Admin the Application
    settings, WC Validation, RFQ Numbers, the Working Hours, each role's own
    (Role Hours — HR Manager's too), the Half Day, the Sourcing and Data Entry Targets, the Idle Alert,
    and people's Extra Hours. Each tab is its own form with its own error bag. The tab that
    opens is the one a form was just saved from (session settings_tab) or
    failed on, else ?tab=, else
    Profile — and switching tabs keeps ?tab= in the address (see admin.js),
    so a reload stays put.

    Expects: $user, $closesRfqs, $isAdmin, $managesWorkingHours, $companyName, $liveInterval,
    $liveIntervalRange, $workingHours (Setting::workingHours()), $timezone
    (Setting::timezone()), $sourcingTargets (Setting::sourcingTargets()),
    $dataEntryTargets (Setting::dataEntryTargets()), $dataEntryIdleAlert
    (Setting::dataEntryIdleAlert()), $dataEntryIdleMinutesRange, $dataEntryPerItem
    (Setting::dataEntryPerItem()) and $dataEntryPerItemRange, $rfqNumberStart
    (Setting::rfqNumberStart()), $nextRfqNumber, $lastRfqSequence, $rfqDataCounts
    (App\RfqDataEraser::counts(), for an Admin), $extraHours
    (ExtraWorkingHour, with user.roles), $extraHoursPeople
    (AttendanceSheet::people()),
    $attendanceSince (Setting::attendanceSince()), $halfDayOff
    (Setting::halfDayOff()), $roleHours (each role with its current
    WorkingSchedule, or null), $peopleHours (each person with their own).
--}}
@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    @php
        $accountTabs = [
            'profile' => ['label' => 'Profile', 'icon' => 'bi-person', 'bag' => 'profile'],
            'password' => ['label' => 'Password', 'icon' => 'bi-key', 'bag' => 'password'],
            'preferences' => ['label' => 'Preferences', 'icon' => 'bi-sliders', 'bag' => null],
        ];
        $roleHoursTab = ['label' => 'Role Hours', 'icon' => 'bi-person-badge', 'bag' => 'role_hours'];
        // The Admin's own tabs — or, for HR Manager, Role Hours alone — under their heading.
        $managedTabs = $isAdmin ? [
            'application' => ['label' => 'Application', 'icon' => 'bi-building', 'bag' => 'application'],
            'wc-validation' => ['label' => 'WC Validation', 'icon' => 'bi-patch-check', 'bag' => null],
            'rfq-numbers' => ['label' => 'RFQ Numbers', 'icon' => 'bi-123', 'bag' => 'rfq_numbers'],
            'working-hours' => ['label' => 'Working Hours', 'icon' => 'bi-clock', 'bag' => 'working_hours'],
            'role-hours' => $roleHoursTab,
            'half-day' => ['label' => 'Half Day', 'icon' => 'bi-circle-half', 'bag' => 'half_day'],
            'sourcing-targets' => ['label' => 'Sourcing Targets', 'icon' => 'bi-stopwatch', 'bag' => 'sourcing_targets'],
            'data-entry-targets' => ['label' => 'Data Entry Targets', 'icon' => 'bi-hourglass-split', 'bag' => ['data_entry_targets', 'data_entry_per_item']],
            'idle-alert' => ['label' => 'Idle Alert', 'icon' => 'bi-bell', 'bag' => 'idle_alert'],
            'extra-hours' => ['label' => 'Extra Hours', 'icon' => 'bi-moon-stars', 'bag' => 'extra_hours'],
            'danger-zone' => ['label' => 'Danger Zone', 'icon' => 'bi-exclamation-octagon', 'bag' => 'danger_zone', 'danger' => true],
        ] : ($managesWorkingHours ? ['role-hours' => $roleHoursTab] : []);
        $managedTabsHeading = $isAdmin ? 'Admin' : 'HR Manager';
        $allTabs = $accountTabs + $managedTabs;
        // A tab's form — or forms — and whether one of them failed.
        $hasErrors = fn (array $tab) => collect((array) $tab['bag'])->contains(fn (string $bag) => $errors->getBag($bag)->any());

        $activeTab = collect($allTabs)->search($hasErrors) ?: session('settings_tab') ?: request('tab');
        $activeTab = array_key_exists((string) $activeTab, $allTabs) ? $activeTab : 'profile';
    @endphp

    {{-- Tabs down the left — the Admin's own (HR Manager's) under their heading — and the
         open one's form beside them. On a narrow screen they're a row across
         the top, which scrolls. --}}
    <div class="settings-layout">
        <div class="settings-nav">
            <ul class="nav settings-tabs" role="tablist" aria-label="Settings" aria-orientation="vertical">
                @foreach ($allTabs as $slug => $tab)
                    @if ($slug === array_key_first($managedTabs))
                        <li class="settings-tabs-divider" role="presentation">
                            <span class="badge badge-soft-primary">{{ $managedTabsHeading }}</span>
                        </li>
                    @endif
                    <li class="nav-item" role="presentation">
                        <button type="button" @class(['nav-link', 'active' => $activeTab === $slug, 'is-danger' => $tab['danger'] ?? false]) id="settings-tab-{{ $slug }}"
                                data-bs-toggle="tab" data-bs-target="#settings-pane-{{ $slug }}" data-settings-tab="{{ $slug }}"
                                role="tab" aria-controls="settings-pane-{{ $slug }}" aria-selected="{{ $activeTab === $slug ? 'true' : 'false' }}">
                            <i class="bi {{ $tab['icon'] }}"></i>
                            {{ $tab['label'] }}
                            @if ($hasErrors($tab))
                                <i class="bi bi-exclamation-circle-fill text-danger" title="Something here needs fixing"></i>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>

    {{-- The open tab's form, beside the tabs (.settings-layout). --}}
    <div class="tab-content settings-content">
        <div class="tab-pane fade {{ $activeTab === 'profile' ? 'show active' : '' }}" id="settings-pane-profile" role="tabpanel" aria-labelledby="settings-tab-profile" tabindex="0">
            <div class="card mb-4">
                <div class="card-header">Profile</div>
                <div class="card-body">
                    {{-- Their photo — anyone changes their own — beside who they are. --}}
                    <div class="settings-identity mb-4">
                        @include('admin.users._photo', ['person' => $user, 'returnTo' => 'settings', 'avatarClass' => 'settings-avatar settings-photo', 'inputId' => 'settings-photo-input'])
                        <div>
                            <div class="fw-semibold">{{ $user->name }}</div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @forelse ($user->roles as $role)
                                    <span class="badge badge-soft-secondary">{{ $role->name }}</span>
                                @empty
                                    <span class="text-muted-soft small">No role yet</span>
                                @endforelse
                            </div>
                            <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                <label for="settings-photo-input" class="btn btn-sm btn-outline-secondary mb-0">
                                    <i class="bi bi-camera"></i> {{ $user->avatar_path ? 'Change photo' : 'Upload photo' }}
                                </label>
                                @if ($user->avatar_path)
                                    <form method="POST" action="{{ route('admin.users.photo.destroy', $user) }}" data-confirm="Remove your photo? Your initial shows instead.">
                                        @csrf
                                        @method('DELETE')
                                        <input type="hidden" name="return_to" value="settings">
                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0">Remove</button>
                                    </form>
                                @endif
                            </div>
                            @error('photo', 'photo')
                                <div class="text-danger small mt-1">{{ $message }}</div>
                            @else
                                <div class="form-text mt-1">JPG, PNG, WebP or GIF, up to {{ \App\Models\User::AVATAR_MAX_KILOBYTES / 1024 }} MB — it's squared and shrunk for you.</div>
                            @enderror
                        </div>
                    </div>

                    {{-- Only those who keep people's details change their own
                         (User::USER_DETAILS_ROLES); everyone else sees theirs. --}}
                    @if ($user->canChangeUserDetails())
                        <form method="POST" action="{{ route('admin.settings.profile') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="settings-name" class="form-label">Full name</label>
                                    <input type="text" name="name" id="settings-name" class="form-control @error('name', 'profile') is-invalid @enderror"
                                           value="{{ old('name', $user->name) }}" maxlength="255" required autocomplete="name">
                                    @error('name', 'profile')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="col-md-6">
                                    <label for="settings-email" class="form-label">Email address</label>
                                    <input type="email" name="email" id="settings-email" class="form-control @error('email', 'profile') is-invalid @enderror"
                                           value="{{ old('email', $user->email) }}" maxlength="255" required autocomplete="email">
                                    @error('email', 'profile')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <p class="text-muted-soft small mt-3 mb-0">Your role is set by an Admin.</p>

                            <div class="mt-3">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save profile</button>
                            </div>
                        </form>
                    @else
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="settings-name" class="form-label">Full name</label>
                                <input type="text" id="settings-name" class="form-control" value="{{ $user->name }}" readonly>
                            </div>

                            <div class="col-md-6">
                                <label for="settings-email" class="form-label">Email address</label>
                                <input type="email" id="settings-email" class="form-control" value="{{ $user->email }}" readonly>
                            </div>
                        </div>

                        <p class="text-muted-soft small mt-3 mb-0">
                            <i class="bi bi-lock"></i> Your name, email and role are kept by an Admin or HR Manager — ask them to change anything here. Your password is yours to change, on the Password tab.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'password' ? 'show active' : '' }}" id="settings-pane-password" role="tabpanel" aria-labelledby="settings-tab-password" tabindex="0">
            <div class="card mb-4">
                <div class="card-header">Password</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.settings.password') }}" novalidate>
                        @csrf
                        @method('PUT')

                        <div class="row g-3">
                            <div class="col-12">
                                <label for="settings-current-password" class="form-label">Current password</label>
                                <input type="password" name="current_password" id="settings-current-password"
                                       class="form-control @error('current_password', 'password') is-invalid @enderror" required autocomplete="current-password">
                                @error('current_password', 'password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="settings-password" class="form-label">New password</label>
                                <input type="password" name="password" id="settings-password"
                                       class="form-control @error('password', 'password') is-invalid @enderror" minlength="8" required autocomplete="new-password">
                                @error('password', 'password')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text">At least 8 characters.</div>
                                @enderror
                            </div>

                            <div class="col-md-6">
                                <label for="settings-password-confirmation" class="form-label">Confirm new password</label>
                                <input type="password" name="password_confirmation" id="settings-password-confirmation"
                                       class="form-control" minlength="8" required autocomplete="new-password">
                            </div>
                        </div>

                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary"><i class="bi bi-key"></i> Change password</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="tab-pane fade {{ $activeTab === 'preferences' ? 'show active' : '' }}" id="settings-pane-preferences" role="tabpanel" aria-labelledby="settings-tab-preferences" tabindex="0">
            <div class="card mb-4">
                <div class="card-header">Preferences</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.settings.preferences') }}">
                        @csrf
                        @method('PATCH')

                        {{-- Light is the panel as it's always been; Dark turns every
                             page dark (admin.css). --}}
                        <div class="mb-3">
                            <div class="form-label fw-semibold mb-2">Theme</div>
                            <div class="theme-picks" role="radiogroup" aria-label="Theme">
                                @foreach (\App\Models\User::THEMES as $theme => $themeLabel)
                                    <input type="radio" class="btn-check" name="theme" id="pref-theme-{{ $theme }}" value="{{ $theme }}" autocomplete="off" @checked($user->theme() === $theme)>
                                    <label class="theme-pick" for="pref-theme-{{ $theme }}" data-theme-preview="{{ $theme }}">
                                        <span class="theme-pick-swatch" aria-hidden="true">
                                            <span class="theme-pick-sidebar"></span>
                                            <span class="theme-pick-card"></span>
                                        </span>
                                        <span class="fw-semibold"><i class="bi {{ $theme === 'dark' ? 'bi-moon-stars' : 'bi-sun' }}"></i> {{ $themeLabel }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input type="hidden" name="live_updates" value="0">
                            <input class="form-check-input" type="checkbox" role="switch" name="live_updates" value="1" id="pref-live-updates"
                                   @checked($user->preference('live_updates'))>
                            <label class="form-check-label fw-semibold" for="pref-live-updates">Update pages automatically</label>
                            <div class="form-text">New and changed RFQs, counts and comments appear on their own, without reloading.</div>
                        </div>

                        @if ($closesRfqs)
                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="celebrations" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="celebrations" value="1" id="pref-celebrations"
                                       @checked($user->preference('celebrations'))>
                                <label class="form-check-label fw-semibold" for="pref-celebrations">Celebrate closed RFQs</label>
                                <div class="form-text">The fireworks when you close an RFQ.</div>
                            </div>
                        @endif

                        <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save preferences</button>
                    </form>
                </div>
            </div>
        </div>

        @if ($isAdmin)
            <div class="tab-pane fade {{ $activeTab === 'application' ? 'show active' : '' }}" id="settings-pane-application" role="tabpanel" aria-labelledby="settings-tab-application" tabindex="0">
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Application
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.application') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <div class="mb-3">
                                <label for="settings-company-name" class="form-label">Company name</label>
                                <input type="text" name="company_name" id="settings-company-name"
                                       class="form-control @error('company_name', 'application') is-invalid @enderror"
                                       value="{{ old('company_name', $companyName) }}" maxlength="80" placeholder="{{ config('app.name') }}">
                                @error('company_name', 'application')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text">Shown in the browser tab, the sidebar and on the sign-in page. Leave empty for “{{ config('app.name') }}”.</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="settings-live-interval" class="form-label">Check for changes every</label>
                                <div class="input-group">
                                    <input type="number" name="live_interval" id="settings-live-interval"
                                           class="form-control @error('live_interval', 'application') is-invalid @enderror"
                                           value="{{ old('live_interval', $liveInterval) }}" min="{{ $liveIntervalRange[0] }}" max="{{ $liveIntervalRange[1] }}" step="1" required>
                                    <span class="input-group-text">seconds</span>
                                    @error('live_interval', 'application')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="form-text">How often open pages look for changes — shorter is quicker, longer is lighter on the server ({{ $liveIntervalRange[0] }}–{{ $liveIntervalRange[1] }}).</div>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save application settings</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade {{ $activeTab === 'wc-validation' ? 'show active' : '' }}" id="settings-pane-wc-validation" role="tabpanel" aria-labelledby="settings-tab-wc-validation" tabindex="0">
                {{-- The WC0000 format for WC numbers — see Setting::wcFormatRequired(). --}}
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        WC Validation
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.wc-validation') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <p class="text-muted-soft small">When it's on, a new RFQ's WC number has to be in the format <strong>WC0000</strong> — WC and four digits, like WC0123. It can be typed in small letters; it's saved in capitals. Existing RFQs keep their WC numbers, and are only checked if their WC number is changed.</p>

                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="enabled" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="wc-validation-enabled" value="1" @checked($wcFormatRequired)>
                                <label class="form-check-label" for="wc-validation-enabled">Require the WC0000 format</label>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save WC validation</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade {{ $activeTab === 'rfq-numbers' ? 'show active' : '' }}" id="settings-pane-rfq-numbers" role="tabpanel" aria-labelledby="settings-tab-rfq-numbers" tabindex="0">
                {{-- Where new RFQ numbers start from — Setting::rfqNumberStart(),
                     Rfq::nextRfqNumber(). --}}
                @php
                    $rfqNumbersFailed = $errors->getBag('rfq_numbers')->any();
                    $startShown = \App\Models\Rfq::formatRfqNumber($rfqNumberStart);
                @endphp
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        RFQ Numbers
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.rfq-numbers') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <p class="text-muted-soft small">The RFQ number a new RFQ is given — from here on they count up one at a time: start from RFQ1670, and the RFQs after it are RFQ1671, RFQ1672 and so on. A number that's already been given is never given again, so starting below one already used carries on after it. Existing RFQs keep their numbers.</p>

                            <div class="mb-3" style="max-width: 260px;">
                                <label for="rfq-number-start" class="form-label">Start RFQ numbers from</label>
                                <input type="text" name="start" id="rfq-number-start" inputmode="numeric" autocomplete="off"
                                       class="form-control @error('start', 'rfq_numbers') is-invalid @enderror"
                                       value="{{ $rfqNumbersFailed ? old('start') : $startShown }}" placeholder="RFQ1670" required>
                                @error('start', 'rfq_numbers')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>

                            <dl class="user-facts small mb-3">
                                <dt>Next RFQ</dt>
                                <dd class="fw-semibold" id="rfq-number-next">{{ $nextRfqNumber }}</dd>
                                <dt>Highest given so far</dt>
                                <dd>{{ $lastRfqSequence > 0 ? \App\Models\Rfq::formatRfqNumber($lastRfqSequence) : 'None yet' }}</dd>
                            </dl>
                            @if ($nextRfqNumber !== $startShown)
                                <p class="small text-warning-emphasis">
                                    <i class="bi bi-info-circle"></i>
                                    {{ \App\Models\Rfq::formatRfqNumber($lastRfqSequence) }} is already taken, so new RFQs carry on from {{ $nextRfqNumber }}.
                                </p>
                            @endif

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save RFQ numbers</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade {{ $activeTab === 'working-hours' ? 'show active' : '' }}" id="settings-pane-working-hours" role="tabpanel" aria-labelledby="settings-tab-working-hours" tabindex="0">
                {{-- The company's working week (_week_table) — everyone's, but for a
                     role or a person with their own (Role Hours, a person's page). --}}
                @php
                    $hasWorkingHoursErrors = $errors->working_hours->any();
                @endphp
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Working Hours
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <form method="POST" action="{{ route('admin.settings.working-hours') }}" novalidate>
                        @csrf
                        @method('PATCH')

                        @include('admin.settings._week_table', [
                            'week' => $workingHours,
                            'bag' => 'working_hours',
                            'refill' => $hasWorkingHoursErrors,
                            'idPrefix' => 'working',
                            'tableId' => 'working-hours-table',
                            'totalId' => 'working-hours-week',
                        ])

                        <div class="card-body border-top">
                            <div class="mb-3 working-hours-timezone">
                                <label for="settings-timezone" class="form-label">Time zone</label>
                                <select name="timezone" id="settings-timezone" class="form-select @error('timezone', 'working_hours') is-invalid @enderror">
                                    @foreach (timezone_identifiers_list() as $zone)
                                        <option value="{{ $zone }}" @selected(old('timezone', $timezone) === $zone)>{{ $zone }}</option>
                                    @endforeach
                                </select>
                                @error('timezone', 'working_hours')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text">The times above are in this zone. Time spent on RFQs only counts these hours.</div>
                                @enderror
                            </div>
                            <div class="mb-3 working-hours-timezone">
                                <label for="settings-attendance-since" class="form-label">Attendance from</label>
                                <input type="date" name="attendance_since" id="settings-attendance-since"
                                       class="form-control @error('attendance_since', 'working_hours') is-invalid @enderror"
                                       value="{{ old('attendance_since', $attendanceSince) }}">
                                @error('attendance_since', 'working_hours')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @else
                                    <div class="form-text">From this day, a person's time only counts on a day Senior Operations' attendance sheet has them present. Leave empty to count all time straight away.</div>
                                @enderror
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save working hours</button>
                        </div>
                    </form>
                </div>
            </div>

        @endif

        @if ($managesWorkingHours)
            <div class="tab-pane fade {{ $activeTab === 'role-hours' ? 'show active' : '' }}" id="settings-pane-role-hours" role="tabpanel" aria-labelledby="settings-tab-role-hours" tabindex="0">
                {{-- Each role's working week — its own, or the company's — and
                     the people with their own, which come first. Each is set in
                     a window (_week_modal, below the tabs). See WorkingSchedule. --}}
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Role Hours
                        <span class="badge badge-soft-primary">Admin</span>
                        <span class="badge badge-soft-primary">HR Manager</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted-soft small mb-0">
                            A role can work its own hours instead of the company's working hours — say 07:00 to 16:00 for Data Entry. Its people's time counts in them: on the Time Spent sheet, in their countdowns, and for Data Entry's Start. Someone with hours of their own — set on their page under Users — keeps those, and extra hours still count on top. A change counts from today; days before keep the hours they had.
                        </p>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Role</th>
                                    <th>Hours</th>
                                    <th class="text-end">A week</th>
                                    <th class="text-end"><span class="visually-hidden">Change</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($roleHours as $row)
                                    @php
                                        $role = $row['role'];
                                        $current = $row['current'];
                                        $roleWeek = $current ? \App\Models\Setting::weekFrom($current->week) : $workingHours;
                                    @endphp
                                    <tr data-role-hours="{{ $role->id }}">
                                        <td class="fw-semibold">{{ $role->name }}</td>
                                        <td>
                                            @if ($current)
                                                <span class="badge badge-soft-primary">Own hours</span>
                                                <span style="font-variant-numeric: tabular-nums;">{{ \App\Models\Setting::weekLabel($roleWeek) }}</span>
                                                <div class="text-muted-soft small">From {{ $current->starts_on->format('M d, Y') }}</div>
                                            @else
                                                <span class="text-muted-soft">Company hours</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">{{ \App\Models\Setting::hoursLabel(collect($roleWeek)->sum(fn (array $day) => \App\Models\Setting::workingMinutes($day))) }}</td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#roleHoursModal-{{ $role->id }}">
                                                <i class="bi bi-pencil"></i> {{ $current ? 'Change' : 'Set hours' }}
                                            </button>
                                            @if ($current)
                                                <form method="POST" action="{{ route('admin.settings.role-hours.destroy', $role) }}" class="d-inline"
                                                      data-confirm="Put {{ $role->name }} back on the company's working hours? It counts from today — days before keep the hours they had.">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Back to the company's hours">
                                                        <i class="bi bi-arrow-counterclockwise"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">People with their own hours</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th>Role</th>
                                    <th>Hours</th>
                                    <th class="text-end">A week</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($peopleHours as $row)
                                    @php $personWeek = \App\Models\Setting::weekFrom($row['current']->week); @endphp
                                    <tr>
                                        <td class="fw-semibold">
                                            <a href="{{ route('admin.users.show', ['user' => $row['user'], 'tab' => 'working-hours']) }}">{{ $row['user']->name }}</a>
                                        </td>
                                        <td>
                                            @foreach ($row['user']->roles as $personRole)
                                                <span class="badge badge-soft-secondary">{{ $personRole->name }}</span>
                                            @endforeach
                                        </td>
                                        <td>
                                            <span style="font-variant-numeric: tabular-nums;">{{ \App\Models\Setting::weekLabel($personWeek) }}</span>
                                            <div class="text-muted-soft small">From {{ $row['current']->starts_on->format('M d, Y') }}</div>
                                        </td>
                                        <td class="text-end text-nowrap">{{ \App\Models\Setting::hoursLabel(collect($personWeek)->sum(fn (array $day) => \App\Models\Setting::workingMinutes($day))) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted-soft py-4">Nobody — everyone works their role's hours or the company's. Set someone's own on their page under Users.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        @endif

        @if ($isAdmin)
            <div class="tab-pane fade {{ $activeTab === 'half-day' ? 'show active' : '' }}" id="settings-pane-half-day" role="tabpanel" aria-labelledby="settings-tab-half-day" tabindex="0">
                {{-- The time a half day off takes — the morning off and the
                     afternoon off. On the attendance sheet, someone on a half day
                     is off one of them, and the time in it doesn't count. --}}
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Half Day
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.half-day') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <p class="text-muted-soft small">When the attendance sheet has someone on a half day, the time they spend on RFQs in the half they were off doesn't count. The rest of their day counts as usual.</p>

                            <div class="row g-3 mb-3">
                                @foreach (['morning' => 'Morning off', 'afternoon' => 'Afternoon off'] as $half => $halfLabel)
                                    <div class="col-md-6">
                                        <label class="form-label" for="half-{{ $half }}-start">{{ $halfLabel }}</label>
                                        <div class="working-hours-range">
                                            @foreach (['start' => 'starts', 'end' => 'ends'] as $name => $label)
                                                <input type="time" name="halves[{{ $half }}][{{ $name }}]" id="half-{{ $half }}-{{ $name }}"
                                                       class="form-control @error("halves.{$half}.{$name}", 'half_day') is-invalid @enderror"
                                                       value="{{ old("halves.{$half}.{$name}", $halfDayOff[$half][$name]) }}" aria-label="{{ $halfLabel }} {{ $label }}">
                                                @if ($name === 'start')
                                                    <span class="text-muted-soft">to</span>
                                                @endif
                                            @endforeach
                                        </div>
                                        @foreach (['start', 'end'] as $name)
                                            @error("halves.{$half}.{$name}", 'half_day')
                                                <div class="text-danger small mt-1">{{ $message }}</div>
                                            @enderror
                                        @endforeach
                                    </div>
                                @endforeach
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save half day</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade {{ $activeTab === 'sourcing-targets' ? 'show active' : '' }}" id="settings-pane-sourcing-targets" role="tabpanel" aria-labelledby="settings-tab-sourcing-targets" tabindex="0">
                {{-- How long Sourcing has to complete a part, by its RFQ's priority —
                     what the countdown on their Pending list runs from. --}}
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Sourcing Targets
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.sourcing-targets') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <p class="text-muted-soft small">Working hours Sourcing has to mark a part complete. Their Pending list counts each part down, only during working hours.</p>

                            {{-- Both target tabs send targets[]: only the one that failed gets what was typed back. --}}
                            <div class="row g-3 mb-3">
                                @foreach (array_reverse($sourcingTargets, true) as $priority => $minutes)
                                    <div class="col-sm-6">
                                        <label for="target-{{ \Illuminate\Support\Str::slug($priority) }}" class="form-label">{{ $priority }}</label>
                                        <div class="input-group">
                                            <input type="number" name="targets[{{ $priority }}]" id="target-{{ \Illuminate\Support\Str::slug($priority) }}"
                                                   class="form-control @error('targets.'.$priority, 'sourcing_targets') is-invalid @enderror"
                                                   value="{{ $errors->getBag('sourcing_targets')->any() ? old('targets.'.$priority, $minutes / 60) : $minutes / 60 }}" min="0.25" step="0.25" required>
                                            <span class="input-group-text">hours</span>
                                            @error('targets.'.$priority, 'sourcing_targets')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save Sourcing targets</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade {{ $activeTab === 'data-entry-targets' ? 'show active' : '' }}" id="settings-pane-data-entry-targets" role="tabpanel" aria-labelledby="settings-tab-data-entry-targets" tabindex="0">
                {{-- How long Data Entry has to send a part to finalize from their
                     Start, by its RFQ's priority — what the countdown on their
                     list runs from. --}}
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Data Entry Targets
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.data-entry-targets') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <p class="text-muted-soft small">Working hours Data Entry has to send a part to finalize, from when they press Start, by its RFQ's priority — unless it's set per item, below. Their list counts each started part down, only during working hours.</p>

                            {{-- Both target tabs send targets[]: only the one that failed gets what was typed back. --}}
                            <div class="row g-3 mb-3">
                                @foreach (array_reverse($dataEntryTargets, true) as $priority => $minutes)
                                    <div class="col-sm-6">
                                        <label for="de-target-{{ \Illuminate\Support\Str::slug($priority) }}" class="form-label">{{ $priority }}</label>
                                        <div class="input-group">
                                            <input type="number" name="targets[{{ $priority }}]" id="de-target-{{ \Illuminate\Support\Str::slug($priority) }}"
                                                   class="form-control @error('targets.'.$priority, 'data_entry_targets') is-invalid @enderror"
                                                   value="{{ $errors->getBag('data_entry_targets')->any() ? old('targets.'.$priority, $minutes / 60) : $minutes / 60 }}" min="0.25" step="0.25" required>
                                            <span class="input-group-text">hours</span>
                                            @error('targets.'.$priority, 'data_entry_targets')
                                                <div class="invalid-feedback">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    </div>
                                @endforeach
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save Data Entry targets</button>
                        </form>
                    </div>
                </div>

                {{-- Or per item: so many minutes an item, times a part's items —
                     Setting::dataEntryPerItem(), Rfq::dataEntryTargetSeconds(). --}}
                @php
                    $perItemFailed = $errors->getBag('data_entry_per_item')->any();
                    $perItemOn = $perItemFailed ? old('enabled') === '1' : $dataEntryPerItem['enabled'];
                    $perItemMinutes = $perItemFailed ? old('minutes') : $dataEntryPerItem['seconds'] / 60;
                @endphp
                <div class="card mb-4" id="data-entry-per-item">
                    <div class="card-header d-flex align-items-center gap-2">
                        Per item
                        <span class="badge badge-soft-primary">Admin</span>
                        @if ($dataEntryPerItem['enabled'])
                            <span class="badge badge-soft-success">On</span>
                        @endif
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.data-entry-per-item') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <p class="text-muted-soft small">
                                Work Data Entry's target out from the RFQ's number of items instead: the minutes an item takes, times its items — 40 items at 3 minutes is 2 hours. A part of a split RFQ gets its share of the items, rounded up. An RFQ with no number of items keeps its priority's target above.
                            </p>

                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="enabled" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="de-per-item-enabled" value="1" @checked($perItemOn)>
                                <label class="form-check-label" for="de-per-item-enabled">Set Data Entry's target per item</label>
                            </div>

                            <div class="mb-3" style="max-width: 260px;">
                                <label for="de-per-item-minutes" class="form-label">Time per item</label>
                                <div class="input-group">
                                    <input type="number" name="minutes" id="de-per-item-minutes"
                                           class="form-control @error('minutes', 'data_entry_per_item') is-invalid @enderror"
                                           value="{{ $perItemMinutes }}" min="{{ $dataEntryPerItemRange[0] / 60 }}" max="{{ $dataEntryPerItemRange[1] / 60 }}" step="0.5" required>
                                    <span class="input-group-text">minutes</span>
                                    @error('minutes', 'data_entry_per_item')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save per item</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade {{ $activeTab === 'idle-alert' ? 'show active' : '' }}" id="settings-pane-idle-alert" role="tabpanel" aria-labelledby="settings-tab-idle-alert" tabindex="0">
                {{-- When Senior Operations hears that someone in Data Entry hasn't
                     started anything — see App\Console\Commands\AlertIdleDataEntry. --}}
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Idle Alert
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.settings.idle-alert') }}" novalidate>
                            @csrf
                            @method('PATCH')

                            <p class="text-muted-soft small">When parts are waiting for Data Entry and someone there hasn't started anything for this many working minutes, Senior Operations gets an alert in their bell. Lunch, out of hours, and anyone on leave on today's attendance sheet don't count.</p>

                            @php $idleAlertFailed = $errors->getBag('idle_alert')->any(); @endphp
                            <div class="form-check form-switch mb-3">
                                <input type="hidden" name="enabled" value="0">
                                <input class="form-check-input" type="checkbox" role="switch" name="enabled" id="idle-alert-enabled" value="1"
                                       @checked($idleAlertFailed ? old('enabled') : $dataEntryIdleAlert['enabled'])>
                                <label class="form-check-label" for="idle-alert-enabled">Alert Senior Operations</label>
                            </div>

                            <div class="mb-3 settings-narrow-field">
                                <label for="idle-alert-minutes" class="form-label">Alert after</label>
                                <div class="input-group">
                                    <input type="number" name="minutes" id="idle-alert-minutes"
                                           class="form-control @error('minutes', 'idle_alert') is-invalid @enderror"
                                           value="{{ $idleAlertFailed ? old('minutes') : $dataEntryIdleAlert['minutes'] }}"
                                           min="{{ $dataEntryIdleMinutesRange[0] }}" max="{{ $dataEntryIdleMinutesRange[1] }}" step="1" required>
                                    <span class="input-group-text">minutes</span>
                                    @error('minutes', 'idle_alert')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Save idle alert</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="tab-pane fade {{ $activeTab === 'extra-hours' ? 'show active' : '' }}" id="settings-pane-extra-hours" role="tabpanel" aria-labelledby="settings-tab-extra-hours" tabindex="0">
                {{-- People's own working time on top of everyone's working hours —
                     their time counts in it too. See ExtraWorkingHour and
                     SettingsController::storeExtraHours(). --}}
                @php
                    $extraFailed = $errors->extra_hours->any() && old('return_to') !== 'users';
                @endphp
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Extra Hours
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <p class="text-muted-soft small">
                            For someone who also works outside the working hours — say 19:00 to 20:00 in the evening. Their time counts in those hours as well as the working hours: on the Time Spent sheet, in their countdowns, and for Data Entry's Start. Everyone else keeps the working hours alone.
                        </p>

                        <form method="POST" action="{{ route('admin.settings.extra-hours.store') }}" class="extra-hours-form" novalidate>
                            @csrf
                            <div class="row g-3 align-items-end">
                                <div class="col-md-4">
                                    <label for="extra-hours-user" class="form-label">Person</label>
                                    <select name="user_id" id="extra-hours-user" class="form-select @error('user_id', 'extra_hours') is-invalid @enderror" required>
                                        <option value="">Pick someone…</option>
                                        @foreach ($extraHoursPeople as $person)
                                            <option value="{{ $person->id }}" @selected($extraFailed && (string) old('user_id') === (string) $person->id)>
                                                {{ $person->name }} — {{ $person->roles->pluck('name')->intersect(array_keys(\App\Models\RfqStep::ROLE_STEPS))->join(', ') }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('user_id', 'extra_hours')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>
                                @include('admin.settings._extra_hours_fields', ['idPrefix' => 'extra-hours', 'showOld' => $extraFailed])
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add extra hours</button>
                                    <span class="form-text ms-2">Set an earlier day for time already worked this way — it's counted again with these hours.</span>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Person</th>
                                    <th>Role</th>
                                    <th>Days</th>
                                    <th>Extra hours</th>
                                    <th>Counting from</th>
                                    <th class="text-end"><span class="visually-hidden">Remove</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($extraHours as $range)
                                    <tr>
                                        <td class="fw-semibold">{{ $range->user?->name ?? 'Unknown' }}</td>
                                        <td>
                                            @foreach ($range->user?->roles->pluck('name')->intersect(array_keys(\App\Models\RfqStep::ROLE_STEPS)) ?? [] as $roleName)
                                                <span class="badge badge-soft-secondary">{{ $roleName }}</span>
                                            @endforeach
                                        </td>
                                        <td>{{ $range->daysLabel() }}</td>
                                        <td class="text-nowrap" style="font-variant-numeric: tabular-nums;">{{ $range->start }} – {{ $range->end }}</td>
                                        <td class="text-muted-soft text-nowrap">{{ $range->starts_on->format('M d, Y') }}</td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('admin.settings.extra-hours.destroy', $range) }}" class="d-inline"
                                                  data-confirm="Remove {{ $range->user?->name }}'s extra hours ({{ $range->daysLabel() }}, {{ $range->start }}–{{ $range->end }})? Their time in them stops counting from now on — time already worked in them still counts.">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted-soft py-4">Nobody has extra hours — everyone works the working hours.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade {{ $activeTab === 'danger-zone' ? 'show active' : '' }}" id="settings-pane-danger-zone" role="tabpanel" aria-labelledby="settings-tab-danger-zone" tabindex="0">
                {{-- Clearing every RFQ for good — SettingsController::clearRfqData(),
                     App\RfqDataEraser. Typed phrase and password, then the
                     app's own confirm. --}}
                @php
                    $phrase = \App\Http\Controllers\Admin\SettingsController::CLEAR_RFQ_DATA_PHRASE;
                    $dangerFailed = $errors->getBag('danger_zone')->any();
                    $whatGoes = [
                        'rfqs' => ['RFQ', 'RFQs'],
                        'rfq_user' => ['Sourcing part', 'Sourcing parts'],
                        'rfq_comments' => ['comment', 'comments'],
                        'rfq_comment_attachments' => ['photo or file', 'photos and files'],
                        'rfq_returns' => ['return', 'returns'],
                        'rfq_steps' => ['timed step', 'timed steps'],
                    ];
                    $rfqCount = $rfqDataCounts['rfqs'] ?? 0;
                @endphp
                <div class="card mb-4 danger-zone-card">
                    <div class="card-header d-flex align-items-center gap-2">
                        <i class="bi bi-exclamation-octagon-fill text-danger"></i>
                        Clear all RFQ data
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <div class="card-body">
                        <p class="small mb-2">
                            Deletes <strong>every RFQ</strong> — open, closed, on hold or cancelled — with everything that goes with them, for good. It can't be undone; take a database backup first if you might need any of it.
                        </p>
                        <p class="small text-muted-soft">
                            Users, roles, permissions, attendance, messages and every setting stay as they are. Afterwards the next RFQ is {{ \App\Models\Rfq::formatRfqNumber($rfqNumberStart) }} — where the RFQ Numbers tab starts them.
                        </p>

                        <ul class="danger-zone-counts list-unstyled small mb-3">
                            @foreach ($whatGoes as $table => [$one, $many])
                                @isset($rfqDataCounts[$table])
                                    <li><strong>{{ number_format($rfqDataCounts[$table]) }}</strong> {{ $rfqDataCounts[$table] === 1 ? $one : $many }}</li>
                                @endisset
                            @endforeach
                        </ul>

                        @if (array_sum($rfqDataCounts) === 0)
                            <p class="small text-muted-soft mb-0"><i class="bi bi-check2-circle"></i> There's no RFQ data to clear.</p>
                        @else
                            <form method="POST" action="{{ route('admin.settings.rfq-data.clear') }}" novalidate
                                  data-confirm="Delete all {{ number_format($rfqCount) }} RFQs and everything with them, for good? This can't be undone.">
                                @csrf
                                @method('DELETE')

                                <div class="mb-3" style="max-width: 360px;">
                                    <label for="danger-confirmation" class="form-label">Type <code>{{ $phrase }}</code> to confirm</label>
                                    <input type="text" name="confirmation" id="danger-confirmation" autocomplete="off" spellcheck="false"
                                           class="form-control @error('confirmation', 'danger_zone') is-invalid @enderror"
                                           value="{{ $dangerFailed ? old('confirmation') : '' }}" required>
                                    @error('confirmation', 'danger_zone')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <div class="mb-3" style="max-width: 360px;">
                                    <label for="danger-password" class="form-label">Your password</label>
                                    <input type="password" name="password" id="danger-password" autocomplete="current-password"
                                           class="form-control @error('password', 'danger_zone') is-invalid @enderror" required>
                                    @error('password', 'danger_zone')
                                        <div class="invalid-feedback">{{ $message }}</div>
                                    @enderror
                                </div>

                                <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Clear all RFQ data</button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>
    </div>{{-- .settings-layout --}}

    @if ($managesWorkingHours)
        {{-- Each role's hours window, outside the tabs; the one whose save
             didn't go in opens again, as it was sent. --}}
        @php $failedRoleId = $errors->role_hours->any() ? (string) old('role_id') : null; @endphp
        @foreach ($roleHours as $row)
            @include('admin.settings._week_modal', [
                'modalId' => 'roleHoursModal-'.$row['role']->id,
                'title' => $row['role']->name.' — working hours',
                'subtitle' => 'Its people\'s time counts in these hours, unless they have their own.',
                'action' => route('admin.settings.role-hours.update', $row['role']),
                'week' => $row['current'] ? \App\Models\Setting::weekFrom($row['current']->week) : $workingHours,
                'bag' => 'role_hours',
                'refill' => $failedRoleId === (string) $row['role']->id,
                'idPrefix' => 'role-'.$row['role']->id,
                'hidden' => ['role_id' => $row['role']->id],
            ])
        @endforeach

        @if ($failedRoleId)
            @push('scripts')
                <script>
                    document.addEventListener('DOMContentLoaded', function () {
                        var modalEl = document.getElementById(@json('roleHoursModal-'.$failedRoleId));
                        if (modalEl) {
                            bootstrap.Modal.getOrCreateInstance(modalEl).show();
                        }
                    });
                </script>
            @endpush
        @endif
    @endif
@endsection
