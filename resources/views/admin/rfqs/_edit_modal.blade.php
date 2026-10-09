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
                @include('admin.rfqs._redirect_fields')
                @if (($returnTo ?? null) === 'show')
                    <input type="hidden" name="return_to" value="show">
                @endif
                <div class="modal-header">
                    <h5 class="modal-title" id="editRfqModalLabel">Edit RFQ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @include('admin.rfqs._form', ['mode' => 'edit', 'idPrefix' => 'edit', 'priorities' => $priorities, 'statuses' => $statuses])
                    {{-- On Business Development's Returns page, for an RFQ that can
                         go straight back to whoever sent it (data-forward-back on
                         its Edit button): after saving, send it there rather than on
                         to Senior Operations — see RfqController::update(). --}}
                    <div @class(['form-check mt-3', 'd-none' => ! old('forward_back_to')]) id="editForwardBack">
                        <input class="form-check-input" type="checkbox" name="forward_back" value="1" id="edit-forward_back" @checked(old('forward_back'))>
                        <label class="form-check-label" for="edit-forward_back">
                            Then send it straight back to <span id="editForwardBackTo">{{ old('forward_back_to') }}</span>
                        </label>
                        <input type="hidden" name="forward_back_to" value="{{ old('forward_back_to') }}">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
