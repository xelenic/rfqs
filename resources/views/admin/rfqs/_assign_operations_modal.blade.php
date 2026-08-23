{{--
    Assign Operations modal — shared between the index (list) and show
    (detail) pages. Single-select: exactly one Operations-role user, or
    none. Populated via JS per row/page, see public/js/admin.js
    (.js-assign-operations-rfq).

    Expects: $operationsUsers.
    Optional: $statusFilter, $returnTo — see _edit_modal.blade.php.
--}}
<div class="modal fade" id="assignOperationsModal" tabindex="-1" aria-labelledby="assignOperationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="assignOperationsForm" action="#">
                @csrf
                @method('PATCH')
                @if ($statusFilter ?? null)
                    <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                @endif
                @if (($returnTo ?? null) === 'show')
                    <input type="hidden" name="return_to" value="show">
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="assignOperationsModalLabel">Assign Operations</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted-soft small mb-3">Optional — pick the Operations team member routing this RFQ before it goes to Sourcing.</p>

                    @if ($operationsUsers->isEmpty())
                        <p class="text-muted-soft mb-0">No users have the Operations role yet. Assign that role from the Users page first.</p>
                    @else
                        <div class="role-pick-grid">
                            <label class="role-pick-card" for="assign-operations-none">
                                <input type="radio" class="role-pick-input" name="operations_user"
                                       id="assign-operations-none" value="" checked>
                                <span class="role-pick-check"><i class="bi bi-check-circle-fill"></i></span>
                                <span class="role-pick-name">None</span>
                                <span class="role-pick-desc">Clear the current assignment</span>
                            </label>
                            @foreach ($operationsUsers as $operationsUser)
                                <label class="role-pick-card" for="assign-operations-{{ $operationsUser->id }}">
                                    <input type="radio" class="role-pick-input" name="operations_user"
                                           id="assign-operations-{{ $operationsUser->id }}" value="{{ $operationsUser->id }}">
                                    <span class="role-pick-check"><i class="bi bi-check-circle-fill"></i></span>
                                    <span class="role-pick-name">{{ $operationsUser->name }}</span>
                                    <span class="role-pick-desc">{{ $operationsUser->email }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    @unless ($operationsUsers->isEmpty())
                        <button type="submit" class="btn btn-primary">Save assignment</button>
                    @endunless
                </div>
            </form>
        </div>
    </div>
</div>
