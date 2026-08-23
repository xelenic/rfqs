@php
    $rolePermissions = $role?->permissions->pluck('name')->toArray() ?? old('permissions', []);
@endphp

<div class="mb-3">
    <label for="name" class="form-label">Role name</label>
    <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror"
           value="{{ old('name', $role->name ?? '') }}" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
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

<label class="form-label d-block">Permissions</label>

@forelse ($permissions as $group => $items)
    <div class="permission-group">
        <div class="group-title">{{ $group }}</div>
        @foreach ($items as $permission)
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" name="permissions[]" id="permission-{{ $permission->id }}"
                       value="{{ $permission->name }}" {{ in_array($permission->name, $rolePermissions) ? 'checked' : '' }}>
                <label class="form-check-label" for="permission-{{ $permission->id }}">{{ $permission->name }}</label>
            </div>
        @endforeach
    </div>
@empty
    <p class="text-muted-soft">No permissions have been created yet.</p>
@endforelse
