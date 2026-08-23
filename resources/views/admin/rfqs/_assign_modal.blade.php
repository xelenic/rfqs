{{--
    Assign Sourcing modal — shared between the index (list) and show (detail)
    pages. Populated via JS per row/page, see public/js/admin.js (.js-assign-rfq).

    Expects: $sourcingUsers.
    Optional: $statusFilter, $returnTo — see _edit_modal.blade.php.
--}}
<div class="modal fade" id="assignRfqModal" tabindex="-1" aria-labelledby="assignRfqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="assignRfqForm" action="#">
                @csrf
                @method('PATCH')
                @if ($statusFilter ?? null)
                    <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                @endif
                @if (($returnTo ?? null) === 'show')
                    <input type="hidden" name="return_to" value="show">
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="assignRfqModalLabel">Assign Sourcing</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted-soft small mb-3">Optional — pick a Sourcing team member to work this RFQ.</p>

                    @if ($sourcingUsers->isEmpty())
                        <p class="text-muted-soft mb-0">No users have the Sourcing role yet. Assign that role from the Users page first.</p>
                    @else
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="assign-split-toggle">
                            <label class="form-check-label" for="assign-split-toggle">
                                Split this task across multiple Sourcing members
                            </label>
                        </div>

                        <div class="role-pick-grid">
                            @foreach ($sourcingUsers as $sourcingUser)
                                <label class="role-pick-card" for="assign-user-{{ $sourcingUser->id }}">
                                    <input type="checkbox" class="role-pick-input" name="users[]"
                                           id="assign-user-{{ $sourcingUser->id }}" value="{{ $sourcingUser->id }}">
                                    <span class="role-pick-check"><i class="bi bi-check-circle-fill"></i></span>
                                    <span class="role-pick-name">{{ $sourcingUser->name }}</span>
                                    <span class="role-pick-desc">{{ $sourcingUser->email }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    @unless ($sourcingUsers->isEmpty())
                        <button type="submit" class="btn btn-primary">Save assignment</button>
                    @endunless
                </div>
            </form>
        </div>
    </div>
</div>
