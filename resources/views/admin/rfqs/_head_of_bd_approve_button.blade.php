{{--
    The Head of Business Development's Approve, which opens
    _head_of_bd_approve_modal to finalize the line items first: for one part
    ($approvePart, approved on its own — RfqController::approveHeadOfBdPart()),
    or the whole RFQ (approving every part still waiting — approveHeadOfBd()).

    Expects: $rfq. Optional: $approvePart (a part number; not $part, which
    the RFQ's page uses for its own).
--}}
@php
    $partsToApprove = isset($approvePart)
        ? collect([$rfq->assigneeForPart((int) $approvePart)])->filter()
        : $rfq->partsAwaitingHeadOfBdApproval();
    $counts = $partsToApprove->map(fn ($assignee) => [
        'part' => $assignee->pivot->part_number,
        'label' => $rfq->partNumberLabel($assignee->pivot->part_number),
        'who' => $assignee->name,
        'verified' => $assignee->pivot->verifiedLineItems(),
    ] + $assignee->pivot->lineItemsCheck())->values();
    $approveLabel = isset($approvePart) ? $rfq->partNumberLabel((int) $approvePart) : $rfq->rfq_number;
@endphp
<button type="button" class="btn btn-sm btn-success js-head-of-bd-approve"
        data-bs-toggle="modal" data-bs-target="#headOfBdApproveModal"
        data-action="{{ isset($approvePart) ? route('admin.rfqs.approve-head-of-bd-part', $rfq) : route('admin.rfqs.approve-head-of-bd', $rfq) }}"
        data-part="{{ $approvePart ?? '' }}"
        data-label="{{ $approveLabel }} — {{ $rfq->subject }}"
        data-hint="{{ isset($approvePart) && $rfq->isSplit() ? 'Finalize the line items, then approve. The RFQ moves on to GM Assistant once every part is approved.' : 'Finalize the line items, then approve. It moves on to GM Assistant, whose time on it goes by these line items.' }}"
        data-parts="{{ $counts->toJson() }}">
    <i class="bi bi-check2-circle"></i> Approve
</button>
