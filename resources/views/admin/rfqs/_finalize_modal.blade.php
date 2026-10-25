{{--
    The Sourcing member's Finalize — the popup that submits the line items
    before a part goes on to Senior Operations' review, laid out as Senior
    Operations' Verify line items (_senior_ops_approve_modal): the line items
    Sourcing gave Data Entry to add, the line items Data Entry added and the
    difference between them — worked out by the system, read only
    (RfqAssignment::lineItemsCheck()) — with how they came about
    (_line_items_history), and the Submitted Line Items, which they enter
    (submitted_line_items[part], kept as rfq_user.submitted_line_items). One
    shared popup, pointed at a part by the .js-finalize button that opens it
    (_finalize_button) — admin.js fills it in, as it does every
    .js-line-items-modal, along with where to come back to
    (data-from-button) and Back to the popup it was opened from. See
    RfqController::finalize().
--}}
<div class="modal fade js-line-items-modal" id="finalizeModal" data-field="submitted_line_items" tabindex="-1" aria-labelledby="finalizeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" id="finalizeForm" action="#" class="modal-content">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="" disabled>
            <input type="hidden" name="return_to" value="" data-from-button="returnTo" disabled>
            <input type="hidden" name="redirect_status" value="" data-from-button="redirectStatus" disabled>
            <input type="hidden" name="redirect_role" value="" data-from-button="redirectRole" disabled>

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="finalizeModalLabel">Finalize — submit line items</h5>
                    <div class="text-muted-soft small js-li-target"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted-soft small js-li-hint"></p>
                <div class="js-li-parts"></div>
                <p class="text-muted-soft small d-none js-li-none">There's no part to finalize.</p>

                {{-- The part's check, filled in by admin.js. --}}
                <template id="finalizePart">
                    <div class="line-items-check">
                        <div class="line-items-check-title"></div>
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <label class="form-label small" data-label-for="to_add">Line Items to Add</label>
                                <input type="text" class="form-control line-items-check-readonly" data-value="to_add" readonly tabindex="-1">
                                <div class="form-text">Requested by Sourcing</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small" data-label-for="added">Line Items Added</label>
                                <input type="text" class="form-control line-items-check-readonly" data-value="added" readonly tabindex="-1">
                                <div class="form-text">Entered by Data Entry</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small" data-label-for="difference">Difference</label>
                                <input type="text" class="form-control line-items-check-readonly" data-value="difference" readonly tabindex="-1">
                                <div class="form-text js-difference-note"></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold" data-label-for="count">Submitted Line Items</label>
                                <input type="number" class="form-control js-actual" min="0" max="{{ \App\Http\Controllers\Admin\RfqController::MAX_DATA_ENTRIES }}" step="1" inputmode="numeric" required>
                                <div class="form-text">Submitted by you</div>
                            </div>
                        </div>
                        @include('admin.rfqs._line_items_history')
                    </div>
                </template>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary js-li-back" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check2-all"></i> Finalize &amp; send to Senior Operations
                </button>
            </div>
        </form>
    </div>
</div>
