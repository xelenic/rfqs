{{--
    The Edit user modal, shared by every Edit button on the page and filled in
    from the one clicked (admin.js, .js-edit-user). On a person's own page it
    comes back there after saving ($returnTo "show" — UserController::backFrom()).

    Expects: $roles (the ones the signed-in user can give). Optional: $returnTo.
--}}
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="editUserForm" action="{{ old('user_id') ? route('admin.users.update', old('user_id')) : '#' }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="user_id" value="{{ old('user_id') }}">
                @if ($returnTo ?? null)
                    <input type="hidden" name="return_to" value="{{ $returnTo }}">
                @endif
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
