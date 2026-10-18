{{--
    Senior Operations' Approve — the popup that verifies the line items before
    a part (or the whole RFQ) goes on to the Head of Business Development.
    For each part it's approving: the line items Sourcing gave Data Entry to
    add, the line items Data Entry added and the difference between them —
    worked out by the system, read only (RfqAssignment::lineItemsCheck()) — and
    the Actual Line Items Senior Operations counted, which they enter
    (actual_line_items[part], kept as rfq_user.verified_line_items). One shared
    popup, pointed at a part or an RFQ by the .js-senior-ops-approve button
    that opens it (_senior_ops_approve_button, admin.js). See
    RfqController::approveSeniorOpsPart() and completeSeniorOpsReview().
--}}
<div class="modal fade" id="seniorOpsApproveModal" tabindex="-1" aria-labelledby="seniorOpsApproveModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form method="POST" id="seniorOpsApproveForm" action="#" class="modal-content">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="" disabled>

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="seniorOpsApproveModalLabel">Verify line items</h5>
                    <div class="text-muted-soft small" id="seniorOpsApproveTarget"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted-soft small" id="seniorOpsApproveHint"></p>
                <div id="seniorOpsApproveParts"></div>
                <p class="text-muted-soft small d-none" id="seniorOpsApproveNone">There are no parts with line items to verify — it goes straight on.</p>

                {{-- One part's check, filled in by admin.js. --}}
                <template id="seniorOpsApprovePart">
                    <div class="line-items-check">
                        <div class="line-items-check-title"></div>
                        <div class="row g-2">
                            <div class="col-6 col-md-3">
                                <label class="form-label small js-label-to-add">Line Items to Add</label>
                                <input type="text" class="form-control line-items-check-readonly js-to-add" readonly tabindex="-1">
                                <div class="form-text">Requested by Sourcing</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small js-label-added">Line Items Added</label>
                                <input type="text" class="form-control line-items-check-readonly js-added" readonly tabindex="-1">
                                <div class="form-text">Entered by Data Entry</div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small js-label-difference">Difference</label>
                                <input type="text" class="form-control line-items-check-readonly js-difference" readonly tabindex="-1">
                                <div class="form-text js-difference-note"></div>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label small fw-semibold js-label-actual">Actual Line Items</label>
                                <input type="number" class="form-control js-actual" min="0" max="{{ \App\Http\Controllers\Admin\RfqController::MAX_DATA_ENTRIES }}" step="1" inputmode="numeric" required>
                                <div class="form-text">Verified by you</div>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="mt-3">
                    @include('admin.rfqs._acting_as', ['role' => 'Senior Operations', 'id' => 'senior-ops-approve-acting-as', 'label' => 'Approved by'])
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button>
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-check2-circle"></i> Approve &amp; send to Head of Business Development
                </button>
            </div>
        </form>
    </div>
</div>
