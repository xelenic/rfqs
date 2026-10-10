{{--
    $mode: 'create' | 'edit'. $idPrefix: unique DOM id/error-bag prefix so the
    two modals (create + edit) never collide, since both include this partial
    on the same page. Fields are populated client-side (admin.js) when opening
    the edit modal, or by old() — scoped to whichever modal actually failed
    validation — when redisplaying errors.
--}}
@php
    $showOld = old('user_id') ? $idPrefix === 'edit' : ($errors->create->any() && $idPrefix === 'create');
@endphp

<div class="row g-3">
    <div class="col-md-6">
        <label for="{{ $idPrefix }}-name" class="form-label">Full name</label>
        <input type="text" name="name" id="{{ $idPrefix }}-name" class="form-control @error('name', $idPrefix) is-invalid @enderror"
               value="{{ $showOld ? old('name') : '' }}" required>
        @error('name', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="{{ $idPrefix }}-email" class="form-label">Email address</label>
        <input type="email" name="email" id="{{ $idPrefix }}-email" class="form-control @error('email', $idPrefix) is-invalid @enderror"
               value="{{ $showOld ? old('email') : '' }}" required>
        @error('email', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    @if ($mode === 'edit' && ! auth()->user()->hasRole('Admin'))
        {{-- Only an Admin sets someone's password; HR Manager sends a link
             (the envelope on the list). --}}
    @elseif ($mode === 'edit')
        <div class="col-md-6">
            <label for="{{ $idPrefix }}-password" class="form-label">
                Password <span class="text-muted-soft fw-normal">(leave blank to keep current)</span>
            </label>
            <input type="password" name="password" id="{{ $idPrefix }}-password" class="form-control @error('password', $idPrefix) is-invalid @enderror" autocomplete="new-password">
            @error('password', $idPrefix)
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="col-md-6">
            <label for="{{ $idPrefix }}-password_confirmation" class="form-label">Confirm password</label>
            <input type="password" name="password_confirmation" id="{{ $idPrefix }}-password_confirmation" class="form-control" autocomplete="new-password">
        </div>
    @else
        {{-- How a new account signs in: by default its person sets their own
             password from the email it sends; an Admin can set one instead,
             and no email goes (Admin\UserController::store()). admin.js
             shows the password fields for that choice alone. --}}
        @php
            $canSetPassword = auth()->user()->hasRole('Admin');
            $setsPassword = $canSetPassword && $showOld && old('sign_in') === 'password';
        @endphp
        @if ($canSetPassword)
            <div class="col-12">
                <label class="form-label d-block mb-2">How they'll sign in</label>
                <div class="d-flex flex-column flex-sm-row gap-2 js-sign-in-choice">
                    <label class="sign-in-option" for="{{ $idPrefix }}-sign-in-email">
                        <input class="form-check-input" type="radio" name="sign_in" id="{{ $idPrefix }}-sign-in-email" value="email_link" @checked(! $setsPassword)>
                        <span>
                            <span class="fw-semibold d-block"><i class="bi bi-envelope-check"></i> Email them a link</span>
                            <span class="small text-muted-soft">They set their own password.</span>
                        </span>
                    </label>
                    <label class="sign-in-option" for="{{ $idPrefix }}-sign-in-password">
                        <input class="form-check-input" type="radio" name="sign_in" id="{{ $idPrefix }}-sign-in-password" value="password" @checked($setsPassword)>
                        <span>
                            <span class="fw-semibold d-block"><i class="bi bi-key"></i> Set a password now</span>
                            <span class="small text-muted-soft">You give it to them — no email is sent.</span>
                        </span>
                    </label>
                </div>
                @error('sign_in', $idPrefix)
                    <div class="text-danger small mt-2">{{ $message }}</div>
                @enderror
            </div>
        @endif

        <div @class(['col-12 js-sign-in-email', 'd-none' => $setsPassword])>
            <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-0">
                <i class="bi bi-envelope-check mt-1"></i>
                <div class="small">They'll get an email at this address with a link to set their own password — it works for {{ \App\Notifications\AccountCreated::LINK_DAYS }} days.</div>
            </div>
        </div>

        @if ($canSetPassword)
            <div @class(['col-md-6 js-sign-in-password', 'd-none' => ! $setsPassword])>
                <label for="{{ $idPrefix }}-password" class="form-label">Password</label>
                <input type="password" name="password" id="{{ $idPrefix }}-password" class="form-control @error('password', $idPrefix) is-invalid @enderror"
                       autocomplete="new-password" minlength="8" @required($setsPassword) @disabled(! $setsPassword)>
                @error('password', $idPrefix)
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div @class(['col-md-6 js-sign-in-password', 'd-none' => ! $setsPassword])>
                <label for="{{ $idPrefix }}-password_confirmation" class="form-label">Confirm password</label>
                <input type="password" name="password_confirmation" id="{{ $idPrefix }}-password_confirmation" class="form-control"
                       autocomplete="new-password" @required($setsPassword) @disabled(! $setsPassword)>
            </div>
        @endif
    @endif

    <div class="col-12">
        <label class="form-label d-block mb-2">Roles</label>

        @if ($roles->isEmpty())
            <p class="text-muted-soft mb-0">No roles have been created yet.</p>
        @else
            <div class="role-pick-grid">
                @foreach ($roles as $role)
                    @php
                        $checked = $showOld && in_array($role->name, old('roles', []));
                    @endphp
                    <label class="role-pick-card {{ $checked ? 'selected' : '' }}" for="{{ $idPrefix }}-role-{{ $role->id }}">
                        <input type="checkbox" class="role-pick-input" name="roles[]"
                               id="{{ $idPrefix }}-role-{{ $role->id }}"
                               value="{{ $role->name }}" {{ $checked ? 'checked' : '' }}>
                        <span class="role-pick-check"><i class="bi bi-check-circle-fill"></i></span>
                        <span class="role-pick-name">{{ $role->name }}</span>
                        @if ($role->description)
                            <span class="role-pick-desc">{{ str($role->description)->limit(60) }}</span>
                        @endif
                        @unless ($role->is_active)
                            <span class="role-pick-inactive">Inactive</span>
                        @endunless
                    </label>
                @endforeach
            </div>
            @if ($errors->getBag($idPrefix)->has('roles.*'))
                <div class="text-danger small mt-2">{{ $errors->getBag($idPrefix)->first('roles.*') }}</div>
            @endif
        @endif
    </div>
</div>
