{{--
    The Status button Senior Operations stops an RFQ in progress from on a
    list — or, with $statusPart, one part of a split on its own: a dropdown
    whose options each open the shared popup (_status_modal.blade.php),
    pointed at it and at the change (see admin.js, .js-rfq-status). See
    RfqController::changeStatus().

    Expects: $rfq, $statusPart (a part number, or null for the whole RFQ),
    $statusOptions (what to offer — Rfq::ON_HOLD, Rfq::CANCELLED). Both are
    always passed: a part number from the page around it would point this at
    the wrong thing.
--}}
@php
    $statusTarget = $statusPart ? $rfq->partNumberLabel($statusPart) : "{$rfq->rfq_number} — {$rfq->subject}";
@endphp
{{-- Fixed, so the table's scrolling box doesn't clip it. --}}
<div class="dropdown d-inline-block">
    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle"
            data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false"
            title="{{ $statusPart ? 'Change this part\'s status' : 'Change status' }}">
        <i class="bi bi-sliders"></i> Status
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
        @foreach ($statusOptions as $newStatus)
            <li>
                <button type="button" class="dropdown-item js-rfq-status"
                        data-bs-toggle="modal" data-bs-target="#rfqStatusModal"
                        data-status="{{ $newStatus }}"
                        data-action="{{ route('admin.rfqs.change-status', $rfq) }}"
                        data-rfq-id="{{ $rfq->id }}"
                        data-part="{{ $statusPart }}"
                        data-label="{{ $statusTarget }}">
                    @if ($newStatus === \App\Models\Rfq::ON_HOLD)
                        <i class="bi bi-pause-circle"></i> Put {{ $statusPart ? 'part ' : '' }}on hold
                    @else
                        <i class="bi bi-x-circle"></i> Cancel {{ $statusPart ? 'part' : 'RFQ' }}
                    @endif
                </button>
            </li>
        @endforeach
    </ul>
</div>
