{{--
    The button that opens "Get Details Again" (_request_details_modal) for
    an RFQ waiting on Senior Operations to assign it — on their Unassigned
    queue (_rfq_row), and on their Returns once Business Development has
    fixed one. See RfqController::requestDetails().

    Expects: $rfq.
--}}
<button type="button" class="btn btn-sm btn-outline-danger js-request-details"
        data-bs-toggle="modal" data-bs-target="#requestDetailsModal"
        data-action="{{ route('admin.rfqs.request-details', $rfq) }}"
        data-rfq-id="{{ $rfq->id }}"
        data-label="{{ $rfq->rfq_number }} — {{ $rfq->subject }}"
        title="Send back to Business Development for details">
    <i class="bi bi-arrow-counterclockwise"></i> Get Details Again
</button>
