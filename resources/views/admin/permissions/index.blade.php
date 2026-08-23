@extends('layouts.app')

@section('title', 'Permissions')

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <form method="GET" class="d-flex gap-2">
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search permissions..." style="min-width:220px;">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </form>

            @can('permissions.create')
                <a href="{{ route('admin.permissions.create') }}" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg"></i> Add permission
                </a>
            @endcan
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Permission</th>
                        <th>Assigned roles</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($permissions as $permission)
                        <tr>
                            <td class="fw-semibold">{{ $permission->name }}</td>
                            <td><span class="badge badge-soft-primary">{{ $permission->roles_count }}</span></td>
                            <td class="text-end">
                                @can('permissions.edit')
                                    <a href="{{ route('admin.permissions.edit', $permission) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                @endcan
                                @can('permissions.delete')
                                    <form action="{{ route('admin.permissions.destroy', $permission) }}" method="POST" class="d-inline" data-confirm="Delete this permission?">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="text-center text-muted-soft py-4">No permissions found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($permissions->hasPages())
            <div class="card-footer bg-white">
                {{ $permissions->links() }}
            </div>
        @endif
    </div>
@endsection
