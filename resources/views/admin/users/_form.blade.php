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

    <div class="col-md-6">
        <label for="{{ $idPrefix }}-password" class="form-label">
            Password @if ($mode === 'edit') <span class="text-muted-soft fw-normal">(leave blank to keep current)</span> @endif
        </label>
        <input type="password" name="password" id="{{ $idPrefix }}-password" class="form-control @error('password', $idPrefix) is-invalid @enderror" {{ $mode === 'edit' ? '' : 'required' }} autocomplete="new-password">
        @error('password', $idPrefix)
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>

    <div class="col-md-6">
        <label for="{{ $idPrefix }}-password_confirmation" class="form-label">Confirm password</label>
        <input type="password" name="password_confirmation" id="{{ $idPrefix }}-password_confirmation" class="form-control" {{ $mode === 'edit' ? '' : 'required' }} autocomplete="new-password">
    </div>

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
        @endif
    </div>
</div>
