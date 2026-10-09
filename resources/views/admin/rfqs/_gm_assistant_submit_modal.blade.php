{{--
    GM Assistant's Submit prompt — one shared modal, pointed at a part (or a
    whole RFQ) by the Submit button that opens it (_gm_assistant_submit,
    admin.js .js-gm-assistant-submit). A comment for the General Manager,
    and photos or files, are optional: whatever's given is posted to the
    RFQ's thread as GM Assistant's as it goes on. See
    RfqController::submitGmAssistantDetails().
--}}
<div class="modal fade" id="gmAssistantSubmitModal" tabindex="-1" aria-labelledby="gmAssistantSubmitModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="gmAssistantSubmitForm" action="#" class="modal-content" enctype="multipart/form-data">
            @csrf
            @method('PATCH')
            {{-- The part it's for — empty for a whole RFQ. --}}
            <input type="hidden" name="part" value="">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="gmAssistantSubmitModalLabel">Submit to General Manager</h5>
                    <div class="text-muted-soft small" id="gmAssistantSubmitTarget"></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <label class="form-label" for="gm-assistant-comment">
                    Comment <span class="text-muted-soft fw-normal">(optional)</span>
                </label>
                <textarea name="comment" id="gm-assistant-comment" rows="4" maxlength="2000" class="form-control"
                          placeholder="Anything the General Manager should know?"></textarea>
                <div class="form-text">Posted to this RFQ's comments, where the General Manager will read it.</div>

                @include('admin.rfqs._attachments_input', ['attachmentsId' => 'gm-assistant-attachments'])

                <div class="mt-3">
                    @include('admin.rfqs._acting_as', ['role' => 'GM Assistant', 'id' => 'gm-assistant-acting-as', 'label' => 'Submitted by'])
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send-check"></i> Submit to General Manager
                </button>
            </div>
        </form>
    </div>
</div>
