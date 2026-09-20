{{--
    The prompt every Mark Complete and Return to Sourcing opens — Sourcing's on
    their own part, Data Entry's on a Sourcing part (to complete it, or to send
    it back with a reason). One shared modal, pointed at a part and dressed for
    the action by JS when it opens (see admin.js, .js-complete and
    _complete_button.blade.php). It asks for a comment for whoever's next in
    line, posted to the RFQ's thread along with the action — there's no
    separate comment box in the quick-detail modal. See
    RfqController::completeSourcing(), completeDataEntry() and returnSourcing().
--}}
<div class="modal fade" id="completeModal" tabindex="-1" aria-labelledby="completeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="completeForm" action="#" novalidate class="modal-content">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="">
            <input type="hidden" name="return_to" value="">
            <input type="hidden" name="redirect_status" value="">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="completeModalLabel">Mark complete</h5>
                    <div class="text-muted-soft small" id="complete-subtitle"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="wizard-lead">
                    <span class="wizard-lead-icon"><i class="bi bi-chat-left-text" id="complete-lead-icon"></i></span>
                    <div>
                        <div class="wizard-lead-title" id="complete-lead-title"></div>
                        <div class="wizard-lead-text" id="complete-hint"></div>
                    </div>
                </div>

                <label class="form-label" for="complete-comment" id="complete-label">Comment</label>
                <textarea name="comment" id="complete-comment" rows="4" maxlength="2000" class="form-control"></textarea>
                <div class="form-text d-flex justify-content-between gap-3">
                    <span>Posted to this RFQ's comments, where <span id="complete-audience"></span> will read it.</span>
                    <span class="text-nowrap" id="complete-count">0 / 2000</span>
                </div>
                <div class="text-danger small mt-2 d-none" id="complete-error" role="alert"></div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="complete-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="complete-submit">
                    <i class="bi bi-check2-circle"></i> Mark Complete
                </button>
            </div>
        </form>
    </div>
</div>
