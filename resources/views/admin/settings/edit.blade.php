{{--
    The Settings page (see SettingsController), one tab per part: Profile,
    Password and Preferences for everyone, and for an Admin the Application
    settings, the Working Hours, the Half Day, the Sourcing and Data Entry
    Targets, and the Idle Alert. Each tab is its own
    form with its own error bag. The tab that opens is the one a form was
    just saved from (session settings_tab) or failed on, else ?tab=, else
    Profile — and switching tabs keeps ?tab= in the address (see admin.js),
    so a reload stays put.

    Expects: $user, $closesRfqs, $isAdmin, $companyName, $liveInterval,
    $liveIntervalRange, $workingHours (Setting::workingHours()), $timezone
    (Setting::timezone()), $sourcingTargets (Setting::sourcingTargets()),
    $dataEntryTargets (Setting::dataEntryTargets()), $dataEntryIdleAlert
    (Setting::dataEntryIdleAlert()), $dataEntryIdleMinutesRange,
    $attendanceSince (Setting::attendanceSince()), $halfDayOff
    (Setting::halfDayOff()).
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
        $adminTabs = $isAdmin ? [
            'application' => ['label' => 'Application', 'icon' => 'bi-building', 'bag' => 'application'],
            'working-hours' => ['label' => 'Working Hours', 'icon' => 'bi-clock', 'bag' => 'working_hours'],
            'half-day' => ['label' => 'Half Day', 'icon' => 'bi-circle-half', 'bag' => 'half_day'],
            'sourcing-targets' => ['label' => 'Sourcing Targets', 'icon' => 'bi-stopwatch', 'bag' => 'sourcing_targets'],
            'data-entry-targets' => ['label' => 'Data Entry Targets', 'icon' => 'bi-hourglass-split', 'bag' => 'data_entry_targets'],
            'idle-alert' => ['label' => 'Idle Alert', 'icon' => 'bi-bell', 'bag' => 'idle_alert'],
        ] : [];
        $allTabs = $accountTabs + $adminTabs;
        $hasErrors = fn (array $tab) => $tab['bag'] !== null && $errors->getBag($tab['bag'])->any();

        $activeTab = collect($allTabs)->search($hasErrors) ?: session('settings_tab') ?: request('tab');
        $activeTab = array_key_exists((string) $activeTab, $allTabs) ? $activeTab : 'profile';
    @endphp

    {{-- Tabs across the top — the Admin's own after a divider — and the open
         one's form below, full width. On a narrow screen the row scrolls. --}}
    <ul class="nav nav-tabs settings-tabs mb-3" role="tablist" aria-label="Settings">
        @foreach ($allTabs as $slug => $tab)
            @if ($slug === array_key_first($adminTabs))
                <li class="settings-tabs-divider" role="presentation">
                    <span class="badge badge-soft-primary">Admin</span>
                </li>
            @endif
            <li class="nav-item" role="presentation">
                <button type="button" class="nav-link {{ $activeTab === $slug ? 'active' : '' }}" id="settings-tab-{{ $slug }}"
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

    <div class="tab-content">
        <div class="tab-pane fade {{ $activeTab === 'profile' ? 'show active' : '' }}" id="settings-pane-profile" role="tabpanel" aria-labelledby="settings-tab-profile" tabindex="0">
            <div class="card mb-4">
                <div class="card-header">Profile</div>
                <div class="card-body">
                    <div class="settings-identity mb-4">
                        <span class="settings-avatar">{{ strtoupper(substr($user->name, 0, 1)) }}</span>
                        <div>
                            <div class="fw-semibold">{{ $user->name }}</div>
                            <div class="d-flex flex-wrap gap-1 mt-1">
                                @forelse ($user->roles as $role)
                                    <span class="badge badge-soft-secondary">{{ $role->name }}</span>
                                @empty
                                    <span class="text-muted-soft small">No role yet</span>
                                @endforelse
                            </div>
                        </div>
                    </div>

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

            <div class="tab-pane fade {{ $activeTab === 'working-hours' ? 'show active' : '' }}" id="settings-pane-working-hours" role="tabpanel" aria-labelledby="settings-tab-working-hours" tabindex="0">
                {{-- The working week: one row per day, Sunday first — a switch for
                     whether it's worked, its working hours and its lunch, and the hours
                     that leaves. A day switched off keeps its times, greyed out (see
                     admin.js), for when it's switched back on. --}}
                @php
                    $hasWorkingHoursErrors = $errors->working_hours->any();
                    $weekMinutes = collect($workingHours)->sum(fn (array $day) => \App\Models\Setting::workingMinutes($day));
                @endphp
                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center gap-2">
                        Working Hours
                        <span class="badge badge-soft-primary">Admin</span>
                    </div>
                    <form method="POST" action="{{ route('admin.settings.working-hours') }}" novalidate>
                        @csrf
                        @method('PATCH')

                        <div class="table-responsive">
                            <table class="table align-middle mb-0 working-hours-table" id="working-hours-table">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Working</th>
                                        <th>Working hours</th>
                                        <th>Lunch <span class="text-muted-soft fw-normal text-lowercase">(optional)</span></th>
                                        <th class="text-end">Hours</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($workingHours as $day => $hours)
                                        @php
                                            $field = fn (string $name) => "days.{$day}.{$name}";
                                            $isWorking = $hasWorkingHoursErrors ? old($field('working')) === '1' : $hours['working'];
                                            $time = fn (string $name) => $hasWorkingHoursErrors && $isWorking ? old($field($name)) : $hours[$name];
                                            $dayErrors = collect(['start', 'end', 'lunch_start', 'lunch_end'])->flatMap(fn (string $name) => $errors->working_hours->get($field($name)));
                                        @endphp
                                        <tr data-working-day="{{ $day }}" @class(['is-off' => ! $isWorking])>
                                            <td class="fw-semibold">{{ ucfirst($day) }}</td>
                                            <td>
                                                <div class="form-check form-switch mb-0">
                                                    <input type="hidden" name="days[{{ $day }}][working]" value="0">
                                                    <input class="form-check-input js-working-day" type="checkbox" role="switch"
                                                           name="days[{{ $day }}][working]" value="1" id="working-{{ $day }}"
                                                           aria-label="{{ ucfirst($day) }} is a working day" @checked($isWorking)>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="working-hours-range">
                                                    @foreach (['start' => 'starts', 'end' => 'finishes'] as $name => $label)
                                                        <input type="time" name="days[{{ $day }}][{{ $name }}]" data-time="{{ $name }}"
                                                               class="form-control form-control-sm @if ($errors->working_hours->has($field($name))) is-invalid @endif"
                                                               value="{{ $time($name) }}" aria-label="{{ ucfirst($day) }} {{ $label }}" @disabled(! $isWorking)>
                                                        @if ($name === 'start')
                                                            <span class="text-muted-soft">to</span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td>
                                                <div class="working-hours-range">
                                                    @foreach (['lunch_start' => 'lunch starts', 'lunch_end' => 'lunch ends'] as $name => $label)
                                                        <input type="time" name="days[{{ $day }}][{{ $name }}]" data-time="{{ $name }}"
                                                               class="form-control form-control-sm @if ($errors->working_hours->has($field($name))) is-invalid @endif"
                                                               value="{{ $time($name) }}" aria-label="{{ ucfirst($day) }} {{ $label }}" @disabled(! $isWorking)>
                                                        @if ($name === 'lunch_start')
                                                            <span class="text-muted-soft">to</span>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            </td>
                                            <td class="text-end text-nowrap js-day-hours">
                                                {{ $isWorking ? \App\Models\Setting::hoursLabel(\App\Models\Setting::workingMinutes($hours)) : 'Day off' }}
                                            </td>
                                        </tr>
                                        @if ($dayErrors->isNotEmpty())
                                            <tr class="working-hours-errors">
                                                <td colspan="5" class="text-danger small pt-0">
                                                    @foreach ($dayErrors as $message)
                                                        <div>{{ $message }}</div>
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td colspan="4" class="text-end text-muted-soft">Working week</td>
                                        <td class="text-end fw-semibold text-nowrap" id="working-hours-week">{{ \App\Models\Setting::hoursLabel($weekMinutes) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>

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

                            <p class="text-muted-soft small">Working hours Data Entry has to send a part to finalize, from when they press Start. Their list counts each started part down, only during working hours.</p>

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
        @endif
    </div>
@endsection
