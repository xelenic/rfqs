{{--
    A Sourcing member's Pending reason on a part of theirs still pending:
    opens _pending_reason_modal to say why — or change what they said. It's
    posted to the RFQ's thread, and shown to whoever hovers the RFQ's
    Pending status (_pending_reason_badge). See RfqController::pendingReason().
    Only where Rfq::canGivePendingReason() says so.

    Expects: $rfq, $part. Optional: $label (the button's words — the icon
    alone without), $backModal (a quick-detail modal Cancel goes back to).
--}}
@php $currentReason = $rfq->assigneeForPart((int) $part)?->pivot->pendingReason(); @endphp
<button type="button" class="btn btn-sm btn-outline-secondary js-pending-reason"
        data-bs-toggle="modal" data-bs-target="#pendingReasonModal"
        data-action="{{ route('admin.rfqs.pending-reason', $rfq) }}"
        data-part="{{ $part }}"
        data-rfq-number="{{ $rfq->partNumberLabel((int) $part) }}"
        data-subject="{{ $rfq->subject }}"
        data-reason="{{ $currentReason }}"
        data-back-modal="{{ $backModal ?? '' }}"
        title="{{ $currentReason !== null ? 'Change why it\'s still pending' : 'Say why it\'s still pending' }}"
        aria-label="Pending reason for {{ $rfq->partNumberLabel((int) $part) }}">
    <i class="bi bi-hourglass-split"></i>@isset($label) {{ $label }}@endisset
</button>
