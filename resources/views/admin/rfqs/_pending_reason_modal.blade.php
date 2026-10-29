{{--
    The popup a Sourcing member's Pending reason opens (_pending_reason_button):
    why the part is still pending with them — required — filled with what
    they last said, to change it. One per page, pointed at the part by
    admin.js (.js-pending-reason) when it opens. See
    RfqController::pendingReason().
--}}
<div class="modal fade" id="pendingReasonModal" tabindex="-1" aria-labelledby="pendingReasonLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="pendingReasonForm" action="#" novalidate class="modal-content">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="pendingReasonLabel">Why is it still pending?</h5>
                    <div class="text-muted-soft small" id="pending-reason-subtitle"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="wizard-lead">
                    <span class="wizard-lead-icon"><i class="bi bi-hourglass-split"></i></span>
                    <div>
                        <div class="wizard-lead-title">Shown on its Pending status</div>
                        <div class="wizard-lead-text">Anyone hovering the RFQ's Pending status — Senior Operations among them — sees it, with your name and role. It's also posted to the RFQ's comments.</div>
                    </div>
                </div>

                <label class="form-label" for="pending-reason-text">Reason</label>
                <textarea name="pending_reason" id="pending-reason-text" rows="3" maxlength="1000" class="form-control" placeholder="What's it waiting on — a supplier's quote, a clarification…" required></textarea>
                <div class="form-text d-flex justify-content-end"><span id="pending-reason-count">0 / 1000</span></div>
                <div class="text-danger small mt-1 d-none" id="pending-reason-error" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="pending-reason-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="pending-reason-submit"><i class="bi bi-check2"></i> Save reason</button>
            </div>
        </form>
    </div>
</div>
