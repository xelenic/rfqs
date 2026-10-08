{{--
    A Close button: opens the Close popup (_close_modal.blade.php) for one
    part, or — with no part — a whole RFQ, which asks for the quotation number
    before anything's closed. See admin.js (.js-close-rfq).

    Expects: $rfq, $label (what's being closed, as it reads), $part (the part
    number, or null to close the whole RFQ — always passed, since the page
    around it may have a $part of its own). Optional: $hint (what closing it
    does).
--}}
@php $closingPart = is_int($part) ? $part : null; @endphp
<button type="button" class="btn btn-sm btn-success js-close-rfq"
        data-bs-toggle="modal" data-bs-target="#closeRfqModal"
        data-action="{{ $closingPart !== null ? route('admin.rfqs.close-part', $rfq) : route('admin.rfqs.close', $rfq) }}"
        data-rfq-id="{{ $rfq->id }}"
        data-part="{{ $closingPart ?? '' }}"
        data-label="{{ $label }}"
        data-hint="{{ $hint ?? '' }}">
    <i class="bi bi-flag"></i> Close
</button>
