{{--
    The popup Data Entry's Pause opens (_data_entry_actions): why they're
    pausing the part — required — and the document it's waiting on, if
    any. One per page, pointed at the part by admin.js (.js-pause-data-entry)
    when it opens. See RfqController::pauseDataEntry().
--}}
<div class="modal fade" id="pauseDataEntryModal" tabindex="-1" aria-labelledby="pauseDataEntryLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="pauseDataEntryForm" action="#" novalidate class="modal-content">
            @csrf
            @method('PATCH')
            <input type="hidden" name="part" value="">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="pauseDataEntryLabel">Pause this part</h5>
                    <div class="text-muted-soft small" id="pause-subtitle"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="wizard-lead">
                    <span class="wizard-lead-icon"><i class="bi bi-pause-circle"></i></span>
                    <div>
                        <div class="wizard-lead-title">Its countdown stops where it is</div>
                        <div class="wizard-lead-text">You can start another part, and resume this one once you've nothing running. Your reason is posted to the RFQ's comments.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="pause-reason">Reason</label>
                    <textarea name="pause_reason" id="pause-reason" rows="3" maxlength="1000" class="form-control" placeholder="Why are you pausing it — what's it waiting on?" required></textarea>
                    <div class="form-text d-flex justify-content-end"><span id="pause-count">0 / 1000</span></div>
                    <div class="text-danger small mt-1 d-none" id="pause-error" role="alert"></div>
                </div>

                <div>
                    <label class="form-label" for="pause-reference">Document Reference <span class="text-muted-soft fw-normal">(optional)</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-file-earmark-text" aria-hidden="true"></i></span>
                        <input type="text" name="pause_reference" id="pause-reference" maxlength="255" class="form-control" placeholder="e.g. PO-4471, quote number, email subject" autocomplete="off">
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="pause-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="pause-submit"><i class="bi bi-pause-fill"></i> Pause</button>
            </div>
        </form>
    </div>
</div>
