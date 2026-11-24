{{--
    The app's own confirm, in place of the browser's: asked before any
    form[data-confirm] goes (admin.js askToConfirm()). A form gives the
    question (data-confirm), and may give a title (data-confirm-title) and
    its Yes button's words (data-confirm-ok). It shows over another popup
    when its form is in one (#rejectRfqModal, say).
--}}
<div class="modal fade app-confirm" id="appConfirmModal" tabindex="-1" aria-labelledby="appConfirmTitle" aria-describedby="appConfirmMessage" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h6 mb-0" id="appConfirmTitle">Please confirm</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="appConfirmMessage"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="appConfirmOk">Yes</button>
            </div>
        </div>
    </div>
</div>
