@extends('layouts.app')

@section('title', 'Roles')

@section('content')
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <form method="GET" class="d-flex gap-2">
            <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search roles..." style="min-width:220px;">
            <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
        </form>

        @can('roles.create')
            <a href="{{ route('admin.roles.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> Add role
            </a>
        @endcan
    </div>

    <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-3">
        @forelse ($roles as $role)
            <div class="col">
                <div class="card h-100 role-card">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <h2 class="h6 fw-bold mb-0">{{ $role->name }}</h2>

                            <div class="form-check form-switch mb-0 flex-shrink-0" title="{{ $role->is_active ? 'Disable role' : 'Enable role' }}">
                                <input class="form-check-input js-role-toggle" type="checkbox" role="switch"
                                       id="role-switch-{{ $role->id }}"
                                       data-url="{{ route('admin.roles.toggle', $role) }}"
                                       {{ $role->is_active ? 'checked' : '' }}
                                       @cannot('roles.edit') disabled @endcannot>
                                <label class="form-check-label visually-hidden" for="role-switch-{{ $role->id }}">
                                    Toggle {{ $role->name }}
                                </label>
                            </div>
                        </div>

                        <p class="text-muted-soft small mb-3 flex-grow-1">
                            {{ $role->description ?: 'No description provided.' }}
                        </p>

                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge js-role-status {{ $role->is_active ? 'badge-soft-primary' : 'badge-soft-secondary' }}">
                                {{ $role->is_active ? 'Active' : 'Inactive' }}
                            </span>
                            <span class="badge badge-soft-secondary">
                                <i class="bi bi-key"></i> {{ $role->permissions_count }} permission{{ $role->permissions_count === 1 ? '' : 's' }}
                            </span>
                            <span class="badge badge-soft-secondary">
                                <i class="bi bi-people"></i> {{ $role->users_count }} user{{ $role->users_count === 1 ? '' : 's' }}
                            </span>
                        </div>

                        <div class="d-flex gap-2 mt-auto">
                            @can('roles.edit')
                                <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i> Edit
                                </a>
                            @endcan
                            @can('roles.delete')
                                <form action="{{ route('admin.roles.destroy', $role) }}" method="POST" data-confirm="Delete this role?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i> Delete
                                    </button>
                                </form>
                            @endcan
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center text-muted-soft py-4">No roles found.</div>
                </div>
            </div>
        @endforelse
    </div>

    @if ($roles->hasPages())
        <div class="mt-3">
            {{ $roles->links() }}
        </div>
    @endif
@endsection
