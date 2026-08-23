{{--
    Edit RFQ modal — shared between the index (list) and show (detail) pages.
    Populated via JS per row/page, see public/js/admin.js (.js-edit-rfq).

    Expects: $priorities, $statuses.
    Optional: $statusFilter (preserves the current Pending/Completed filter
    after saving from the list), $returnTo ('show' sends the user back to
    the RFQ's detail page instead of the list after saving).
--}}
<div class="modal fade" id="editRfqModal" tabindex="-1" aria-labelledby="editRfqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="editRfqForm" action="{{ old('rfq_id') ? route('admin.rfqs.update', old('rfq_id')) : '#' }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="rfq_id" value="{{ old('rfq_id') }}">
                @if ($statusFilter ?? null)
                    <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                @endif
                @if (($returnTo ?? null) === 'show')
                    <input type="hidden" name="return_to" value="show">
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="editRfqModalLabel">Edit RFQ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('admin.rfqs._form', ['mode' => 'edit', 'idPrefix' => 'edit', 'priorities' => $priorities, 'statuses' => $statuses])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
