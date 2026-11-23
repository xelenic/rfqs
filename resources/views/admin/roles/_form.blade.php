{{--
    A role's name, description, and what it can do: each permission an on/off
    switch, by area (App\Permissions) — what it's called, what it lets
    someone do, and its key. Those made on the Permissions page come last.

    Expects: $role (null when adding one), $permissions (area => permissions).
--}}
@php
    $rolePermissions = session()->hasOldInput() ? old('permissions', []) : ($role?->permissions->pluck('name')->all() ?? []);
@endphp

<div class="mb-3">
    <label for="name" class="form-label">Role name</label>
    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $role->name ?? '') }}" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    {{-- The slug the system knows the role by (App\Models\Role): renaming never changes it. --}}
    <div class="form-text">
        @if ($role?->slug)
            Key <code>{{ $role->slug }}</code> — the system knows this role by its key, so renaming it keeps its people, permissions and access.
        @else
            Its key is made from this name when it's saved, and stays the same if it's renamed later.
        @endif
    </div>
</div>

<div class="mb-3">
    <label for="description" class="form-label">Short description</label>
    <textarea name="description" id="description" rows="2" maxlength="255"
              class="form-control @error('description') is-invalid @enderror"
              placeholder="What is this role for?">{{ old('description', $role->description ?? '') }}</textarea>
    @error('description')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">Shown on the role card — keep it to one short sentence.</div>
</div>

<div class="form-check form-switch mb-4">
    <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="is_active" value="1"
           {{ old('is_active', $role->is_active ?? true) ? 'checked' : '' }}>
    <label class="form-check-label" for="is_active">Active</label>
    <div class="form-text">Disabled roles are flagged inactive but keep any permissions already assigned.</div>
</div>

<div class="mb-2">
    <span class="form-label d-block mb-0">What this role can do</span>
    <div class="form-text mt-0">Switch a permission on to give it to everyone in this role, or off to take it away. It counts from their next page.</div>
</div>

@forelse ($permissions as $area => $items)
    <fieldset class="permission-group">
        <legend class="group-title">{{ $area }}</legend>
        <div class="permission-rows">
            @foreach ($items as $permission)
                @php $hint = \App\Permissions::hint($permission->name); @endphp
                <div class="form-check form-switch permission-row">
                    <input class="form-check-input" type="checkbox" role="switch" name="permissions[]" id="permission-{{ $permission->id }}"
                           value="{{ $permission->name }}" @checked(in_array($permission->name, $rolePermissions, true))
                           @if ($hint) aria-describedby="permission-{{ $permission->id }}-hint" @endif>
                    <label class="form-check-label" for="permission-{{ $permission->id }}">
                        <span class="permission-label">{{ \App\Permissions::label($permission->name) }}</span>
                        <code class="permission-key">{{ $permission->name }}</code>
                    </label>
                    @if ($hint)
                        <div class="permission-hint" id="permission-{{ $permission->id }}-hint">{{ $hint }}</div>
                    @endif
                </div>
            @endforeach
        </div>
    </fieldset>
@empty
    <p class="text-muted-soft">No permissions have been created yet.</p>
@endforelse
