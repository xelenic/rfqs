{{--
    The Settings page (see SettingsController). Four independent forms, each
    with its own error bag: Profile and Password on the left, Preferences and —
    for an Admin — Application on the right.

    Expects: $user, $closesRfqs, $isAdmin, $companyName, $liveInterval,
    $liveIntervalRange.
--}}
@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <div class="row g-4">
        <div class="col-xl-7">
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

        <div class="col-xl-5">
            <div class="card mb-4">
                <div class="card-header">Preferences</div>
                <div class="card-body">
                    <form method="POST" action="{{ route('admin.settings.preferences') }}">
                        @csrf
                        @method('PATCH')

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

            @if ($isAdmin)
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
            @endif
        </div>
    </div>
@endsection
