{{--
    The Head of Business Development's Approve — the popup that finalizes the
    line items before a part (or the whole RFQ) goes on to GM Assistant. For
    each part it's approving: the line items so far, read only — what Sourcing
    gave Data Entry to add, what Data Entry added, and what Senior Operations
    verified (RfqAssignment::lineItemsCheck(), verifiedLineItems()) — and the
    Finalized Line Items, which they enter (finalized_line_items[part], kept as
    rfq_user.finalized_line_items): what GM Assistant's target goes by
    (Rfq::gmAssistantLineItemsFor()). One shared popup, pointed at a part or
    an RFQ by the .js-head-of-bd-approve button that opens it
    (_head_of_bd_approve_button) — admin.js fills it in, as it does every
    .js-line-items-modal. See RfqController::approveHeadOfBdPart() and
    approveHeadOfBd().
--}}
<div class="modal fade js-line-items-modal" id="headOfBdApproveModal" data-field="finalized_line_items" tabindex="-1" aria-labelledby="headOfBdApproveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" id="headOfBdApproveForm" action="#" class="modal-content">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="" disabled>

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="headOfBdApproveModalLabel">Finalize line items</h5>
                    <div class="text-muted-soft small js-li-target"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted-soft small js-li-hint"></p>
                <div class="js-li-parts"></div>
                <p class="text-muted-soft small d-none js-li-none">There are no parts with line items to finalize — it goes straight on.</p>

                {{-- One part's line items, filled in by admin.js. --}}
                <template id="headOfBdApprovePart">
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
                                <label class="form-label small" data-label-for="verified">Actual Line Items</label>
                                <input type="text" class="form-control line-items-check-readonly" data-value="verified" readonly tabindex="-1">
                                <div class="form-text">Verified by Senior Operations</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold" data-label-for="count">Finalized Line Items</label>
                                <input type="number" class="form-control js-actual" min="0" max="{{ \App\Http\Controllers\Admin\RfqController::MAX_DATA_ENTRIES }}" step="1" inputmode="numeric" required>
                                <div class="form-text">Finalized by you</div>
                            </div>
                        </div>
                        @include('admin.rfqs._line_items_history')
                    </div>
                </template>

                <div class="mt-3">
                    @include('admin.rfqs._acting_as', ['role' => 'Head of Business Development', 'id' => 'head-of-bd-approve-acting-as', 'label' => 'Approved by'])
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check2-circle"></i> Approve &amp; send to GM Assistant
                </button>
            </div>
        </form>
    </div>
</div>
