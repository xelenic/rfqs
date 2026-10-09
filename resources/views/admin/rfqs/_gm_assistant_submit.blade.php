{{--
    GM Assistant's Submit: opens the Submit prompt (_gm_assistant_submit_modal)
    pointed at one part — or, with no $part, the whole RFQ — which forwards
    it on to the General Manager, with a comment if they leave one
    (RfqController::submitGmAssistantDetails()).

    Expects: $rfq, $part (the part number, or null for the whole RFQ).
--}}
<button type="button" class="btn btn-sm btn-primary js-gm-assistant-submit"
        data-bs-toggle="modal" data-bs-target="#gmAssistantSubmitModal"
        data-action="{{ route('admin.rfqs.gm-assistant-details', $rfq) }}"
        data-part="{{ $part }}"
        data-label="{{ $part ? $rfq->partNumberLabel($part) : $rfq->rfq_number }}">
    <i class="bi bi-send-check"></i> Submit
</button>
