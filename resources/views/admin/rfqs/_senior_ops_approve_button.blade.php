{{--
    Senior Operations' Approve, which opens _senior_ops_approve_modal to verify
    the line items first: for one part ($approvePart, approved on its own —
    RfqController::approveSeniorOpsPart()), or the whole RFQ (approving every
    part still waiting — completeSeniorOpsReview()).

    Expects: $rfq. Optional: $approvePart (a part number; not $part, which
    the RFQ's page uses for its own).
--}}
@php
    $partsToApprove = isset($approvePart)
        ? collect([$rfq->assigneeForPart((int) $approvePart)])->filter()
        : $rfq->partsAwaitingSeniorOpsApproval();
    $checks = $partsToApprove->map(fn ($assignee) => [
        'part' => $assignee->pivot->part_number,
        'label' => $rfq->partNumberLabel($assignee->pivot->part_number),
        'who' => $assignee->name,
    ] + $assignee->pivot->lineItemsCheck())->values();
    $approveLabel = isset($approvePart) ? $rfq->partNumberLabel((int) $approvePart) : $rfq->rfq_number;
@endphp
<button type="button" class="btn btn-sm btn-success js-senior-ops-approve"
        data-bs-toggle="modal" data-bs-target="#seniorOpsApproveModal"
        data-action="{{ isset($approvePart) ? route('admin.rfqs.approve-senior-ops-part', $rfq) : route('admin.rfqs.complete-senior-ops-review', $rfq) }}"
        data-part="{{ $approvePart ?? '' }}"
        data-label="{{ $approveLabel }} — {{ $rfq->subject }}"
        data-hint="{{ isset($approvePart) && $rfq->isSplit() ? 'Check the line items, then approve. The RFQ moves on to the Head of Business Development once every part is approved.' : 'Check the line items, then approve. It moves on to the Head of Business Development.' }}"
        data-parts="{{ $checks->toJson() }}">
    <i class="bi bi-check2-circle"></i> Approve
</button>
