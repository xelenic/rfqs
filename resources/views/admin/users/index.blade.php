@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <form method="GET" class="d-flex gap-2">
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search users..." style="min-width:220px;">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </form>

            @can('users.create')
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createUserModal">
                    <i class="bi bi-plus-lg"></i> Add user
                </button>
            @endcan
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Roles</th>
                        <th>Joined</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td class="fw-semibold">{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>
                                @forelse ($user->roles as $role)
                                    <span class="badge badge-soft-primary">{{ $role->name }}</span>
                                @empty
                                    <span class="badge badge-soft-secondary">No role</span>
                                @endforelse
                            </td>
                            <td class="text-muted-soft">{{ $user->created_at->format('M d, Y') }}</td>
                            <td class="text-end">
                                @can('users.edit')
                                    <button type="button" class="btn btn-sm btn-outline-secondary js-edit-user"
                                            data-bs-toggle="modal" data-bs-target="#editUserModal"
                                            data-action="{{ route('admin.users.update', $user) }}"
                                            data-id="{{ $user->id }}"
                                            data-name="{{ $user->name }}"
                                            data-email="{{ $user->email }}"
                                            data-roles="{{ $user->roles->pluck('name')->join(',') }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                @endcan
                                @can('users.delete')
                                    <form action="{{ route('admin.users.destroy', $user) }}" method="POST" class="d-inline" data-confirm="Delete this user?">
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
                            <td colspan="5" class="text-center text-muted-soft py-4">No users found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($users->hasPages())
            <div class="card-footer bg-white">
                {{ $users->links() }}
            </div>
        @endif
    </div>

    {{-- Add user modal --}}
    <div class="modal fade" id="createUserModal" tabindex="-1" aria-labelledby="createUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.users.store') }}">
                    @csrf
                    <div class="modal-header">
                        <h5 class="modal-title" id="createUserModalLabel">Add user</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('admin.users._form', ['mode' => 'create', 'idPrefix' => 'create', 'roles' => $roles])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create user</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Edit user modal (shared — populated via JS per row, see public/js/admin.js) --}}
    <div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" id="editUserForm" action="{{ old('user_id') ? route('admin.users.update', old('user_id')) : '#' }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="user_id" value="{{ old('user_id') }}">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editUserModalLabel">Edit user</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('admin.users._form', ['mode' => 'edit', 'idPrefix' => 'edit', 'roles' => $roles])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @if ($errors->create->any() || $errors->edit->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalId = @json(old('user_id') ? 'editUserModal' : 'createUserModal');
                    var modalEl = document.getElementById(modalId);
                    if (modalEl) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif
@endsection
