{{--
    The popup Senior Operations puts an RFQ on hold, or cancels it, from —
    asking why, which is kept with it and posted to its thread
    (RfqController::changeStatus()) — or one part of a split, on its own
    (data-part). Pointed at the change, and on a list at the RFQ too (its
    data-action, data-rfq-id and data-label), by the .js-rfq-status button
    that opens it (see admin.js); after a refused submission — no reason — it
    comes back open, as it was, against the same RFQ and part.

    Optional: $rfq — the one RFQ it's for, on that RFQ's own page. Without
    it, it's shared by a list's rows.
--}}
@php
    $isFailedStatus = $errors->rfq_status->any();
    $failedStatus = old('status');
    $failedRfqId = $isFailedStatus ? old('status_rfq_id') : null;
    $statusAction = match (true) {
        isset($rfq) => route('admin.rfqs.change-status', $rfq),
        filled($failedRfqId) => route('admin.rfqs.change-status', $failedRfqId),
        default => '#',
    };
    $statusTarget = isset($rfq) ? "{$rfq->rfq_number} — {$rfq->subject}" : ($isFailedStatus ? old('status_label') : '');
    $failedPart = $isFailedStatus ? old('part') : null;
    $isCancelling = $isFailedStatus && $failedStatus === \App\Models\Rfq::CANCELLED;
    $statusTitle = match (true) {
        $isCancelling => filled($failedPart) ? 'Cancel part' : 'Cancel RFQ',
        default => filled($failedPart) ? 'Put part on hold' : 'Put on hold',
    };
@endphp

<div class="modal fade" id="rfqStatusModal" tabindex="-1" aria-labelledby="rfqStatusModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="rfqStatusForm" action="{{ $statusAction }}" novalidate>
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="{{ $isFailedStatus ? $failedStatus : '' }}">
                {{-- Carried along so a refused submission from a list reopens
                     against the right RFQ. --}}
                <input type="hidden" name="status_rfq_id" value="{{ $failedRfqId }}">
                <input type="hidden" name="status_label" value="{{ $isFailedStatus ? old('status_label') : '' }}">
                <input type="hidden" name="part" value="{{ $failedPart }}">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="rfqStatusModalLabel">{{ $statusTitle }}</h5>
                        <div class="text-muted-soft small" id="rfqStatusTarget">{{ $statusTarget }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="text-muted-soft small" id="rfqStatusHint"></p>
                    <label for="rfq-status-reason" class="form-label">Reason</label>
                    <textarea name="reason" id="rfq-status-reason" rows="3" maxlength="1000"
                              class="form-control @error('reason', 'rfq_status') is-invalid @enderror">{{ $isFailedStatus ? old('reason') : '' }}</textarea>
                    @error('reason', 'rfq_status')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @else
                        <div class="form-text">Kept with the RFQ, and posted to its comments.</div>
                    @enderror
                    @error('status', 'rfq_status')
                        <div class="text-danger small mt-2">{{ $message }}</div>
                    @enderror
                    <div class="mt-3">
                        @include('admin.rfqs._acting_as', ['role' => 'Senior Operations', 'id' => 'rfq-status-acting-as', 'label' => 'Done by'])
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Back</button>
                    <button type="submit" class="btn btn-danger" id="rfqStatusSubmit">
                        <i class="bi {{ $isCancelling ? 'bi-x-circle' : 'bi-pause-circle' }}"></i> <span>{{ $statusTitle }}</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@if ($isFailedStatus)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('rfqStatusModal')).show();
            });
        </script>
    @endpush
@endif
