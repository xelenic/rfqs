{{--
    The Sourcing member's Finalize on a part Data Entry has sent to finalize
    (Send to Finalize) — opens _finalize_modal, which shows the part's line
    items so far and asks for the Submitted Line Items, then sends that part
    on to Senior Operations' review. Only for its own assignee, or Admin on
    their behalf; see RfqController::finalize() and Rfq::finalizePart().
    _finalize_modal must be on the page.

    Expects: $rfq, $part (the part number).
    Optional: $returnTo ('show' or 'dashboard' — otherwise back to the
    Pending list), $redirectRole (Admin's role page to come back to, e.g.
    'sourcing'), $backModal (id of the modal this sits in, which the popup's
    Back returns to), $label (the button's text).
--}}
@php
    $finalizing = $rfq->assigneeForPart((int) $part);
    $finalizeCheck = $finalizing ? [[
        'part' => (int) $part,
        'label' => $rfq->partNumberLabel((int) $part),
        'who' => $finalizing->name,
        // How the line items came about — shown under the counts (_line_items_history).
        'history' => $rfq->lineItemsHistory((int) $part),
    ] + $finalizing->pivot->lineItemsCheck()] : [];
@endphp
<button type="button" class="btn btn-sm btn-success js-finalize"
        data-bs-toggle="modal" data-bs-target="#finalizeModal"
        data-action="{{ route('admin.rfqs.finalize', $rfq) }}"
        data-part="{{ $part }}"
        data-label="{{ $rfq->partNumberLabel((int) $part) }} — {{ $rfq->subject }}"
        data-hint="Check the line items and enter how many you're submitting, then finalize. It goes on to Senior Operations' review."
        data-parts="{{ json_encode($finalizeCheck) }}"
        data-return-to="{{ $returnTo ?? '' }}"
        data-redirect-status="{{ ($returnTo ?? null) ? '' : 'Pending' }}"
        data-redirect-role="{{ ($returnTo ?? null) ? '' : ($redirectRole ?? '') }}"
        data-back-modal="{{ $backModal ?? '' }}">
    <i class="bi bi-check2-all"></i> {{ $label ?? 'Finalize' }}
</button>
