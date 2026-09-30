{{--
    "Get Details Again" modal — Senior Operations sends an RFQ on their
    Unassigned queue back to Business Development's Returns page, with what
    they need. Shared across the rows of that queue, populated per-row via
    JS, see public/js/admin.js (.js-request-details) and
    RfqController::requestDetails().
--}}
@php
    $isFailedRequest = $errors->requestDetails->any();
@endphp

<div class="modal fade" id="requestDetailsModal" tabindex="-1" aria-labelledby="requestDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="requestDetailsForm" action="#" data-confirm="Send this RFQ back to Business Development? Any Sourcing part already assigned on it is freed.">
                @csrf
                @method('PATCH')
                {{-- Carried along so a failed submission can be reopened
                     against the right RFQ — see the reject modal's own. --}}
                <input type="hidden" name="request_details_rfq_id" value="{{ old('request_details_rfq_id') }}">
                <input type="hidden" name="request_details_label" value="{{ old('request_details_label') }}">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="requestDetailsModalLabel">Get Details Again</h5>
                        <div class="text-muted-soft small" id="requestDetailsTarget">{{ old('request_details_label') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted-soft small">It goes on Business Development's Returns page with your note, and stays marked "With Business Development" here until they've updated it.</p>
                    <div class="mb-0">
                        <label for="request-details-reason" class="form-label">What's needed</label>
                        <textarea name="reason" id="request-details-reason" rows="3" class="form-control @error('reason', 'requestDetails') is-invalid @enderror" required>{{ $isFailedRequest ? old('reason') : '' }}</textarea>
                        @error('reason', 'requestDetails')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mt-3">
                        @include('admin.rfqs._acting_as', ['role' => 'Senior Operations', 'id' => 'request-details-acting-as', 'label' => 'Sent back by'])
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Send back</button>
                </div>
            </form>
        </div>
    </div>
</div>
