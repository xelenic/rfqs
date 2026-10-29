{{--
    The Head of Business Development's or the General Manager's Return
    (Rfq::RETURNABLE_STAGES), beside their Reject: the same popup
    (_reject_modal, opened as a Return — admin.js, data-as-return) and the
    same choice of where to, but it lands on that role's Returns page rather
    than its Rejected page. See RfqController::reject().

    Expects: $rfq, $action (their reject route for it). Optional: $part and
    $label, to return just that part.
--}}
<button type="button" class="btn btn-sm btn-outline-secondary js-reject-rfq"
        data-bs-toggle="modal" data-bs-target="#rejectRfqModal"
        data-action="{{ $action }}"
        data-rfq-id="{{ $rfq->id }}"
        data-as-return="1"
        @if ($part ?? null) data-part="{{ $part }}" data-label="{{ $label ?? $rfq->partNumberLabel((int) $part) }}" @endif
        title="Send it back for changes — on their Returns page, not their Rejected page">
    <i class="bi bi-arrow-return-left"></i> Return
</button>
