{{--
    GM Assistant's Client Details / Payment Terms modal — one shared modal,
    populated per-row via JS, see public/js/admin.js (.js-gm-assistant-rfq).

    Expects: nothing extra — the form's action, the part (none, for a whole
    RFQ) and its label, and what's already been given for the RFQ, are set
    per-row by JS.
--}}
@php
    $isFailedGmAssistant = $errors->gm_assistant->any();
@endphp

<div class="modal fade" id="gmAssistantModal" tabindex="-1" aria-labelledby="gmAssistantModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="gmAssistantForm" action="#" data-confirm="Forward this {{ old('part') ? 'part' : 'RFQ' }} to the General Manager?">
                @csrf
                @method('PATCH')
                {{-- Not part of the request the route needs — just carried
                     along so a failed submission can be reopened against
                     the right RFQ, since the form's real action is set by
                     JS per-row rather than fixed server-side. --}}
                <input type="hidden" name="gm_assistant_rfq_id" value="{{ old('gm_assistant_rfq_id') }}">
                {{-- The part it's for, if it's one — empty for a whole RFQ. --}}
                <input type="hidden" name="part" value="{{ old('part') }}">
                <input type="hidden" name="gm_assistant_label" value="{{ old('gm_assistant_label') }}">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="gmAssistantModalLabel">Client Details &amp; Payment Terms</h5>
                        <div class="text-muted-soft small" id="gmAssistantTarget">{{ old('gm_assistant_label') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    {{-- One client and one set of terms per RFQ, however many parts. --}}
                    <p class="text-muted-soft small {{ old('part') ? '' : 'd-none' }}" id="gmAssistantWholeNote">
                        Client details and payment terms are kept for the whole RFQ, not just this part.
                    </p>
                    <div class="mb-3">
                        <label for="gm-assistant-client-details" class="form-label">Client details</label>
                        <textarea name="client_details" id="gm-assistant-client-details" rows="3" class="form-control @error('client_details', 'gm_assistant') is-invalid @enderror">{{ $isFailedGmAssistant ? old('client_details') : '' }}</textarea>
                        @error('client_details', 'gm_assistant')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-0">
                        <label for="gm-assistant-payment-terms" class="form-label">
                            Payment terms <span class="text-muted-soft fw-normal">(optional)</span>
                        </label>
                        <textarea name="payment_terms" id="gm-assistant-payment-terms" rows="3" class="form-control @error('payment_terms', 'gm_assistant') is-invalid @enderror">{{ $isFailedGmAssistant ? old('payment_terms') : '' }}</textarea>
                        @error('payment_terms', 'gm_assistant')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Forward to General Manager</button>
                </div>
            </form>
        </div>
    </div>
</div>
