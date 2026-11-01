{{--
    Senior Operations' Reassign on one part: opens _reassign_modal, pointed at
    the part (admin.js, .js-reassign-part), to replace its Sourcing member
    and, till it's sent to finalize, choose who has it in Data Entry. See
    RfqController::reassign(). Only where Rfq::canReplaceSourcing() says so.

    Expects: $rfq, $assignee (the part's holder, with its pivot). Optional:
    $buttonClass and $buttonText, for a line in a dropdown (one part of a split
    on the RFQ's page).
--}}
@php
    $reassignPart = (int) $assignee->pivot->part_number;
    $dataEntryNow = $rfq->dataEntryStanding($reassignPart);
@endphp
<button type="button" class="{{ $buttonClass ?? 'btn btn-sm btn-outline-primary' }} js-reassign-part"
        data-bs-toggle="modal" data-bs-target="#reassignModal"
        data-action="{{ route('admin.rfqs.reassign', $rfq) }}"
        data-rfq-id="{{ $rfq->id }}"
        data-part="{{ $reassignPart }}"
        data-label="{{ $rfq->partNumberLabel($reassignPart) }}"
        data-current-id="{{ $assignee->id }}"
        data-current-name="{{ $assignee->name }}"
        data-de-can-replace="{{ $dataEntryNow['can_replace'] ? '1' : '0' }}"
        data-de-handed="{{ $dataEntryNow['handed_over'] ? '1' : '0' }}"
        data-de-current-id="{{ $dataEntryNow['current_id'] }}"
        data-de-now="{{ $dataEntryNow['label'] }}"
        title="Give this part to someone else in Sourcing or Data Entry">
    <i class="bi bi-person-gear"></i> {{ $buttonText ?? 'Reassign' }}
</button>
