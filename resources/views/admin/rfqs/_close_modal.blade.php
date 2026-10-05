{{--
    The Close popup — Business Development closing a part, or a whole RFQ,
    the General Manager has approved. A reference code is required to close
    anything, and is kept with what's closed (RfqController::closePart() /
    close()). One shared popup per page, pointed at the right RFQ and part by
    the .js-close-rfq button that opens it (see admin.js); after a refused
    submission — no code — it comes back open, pointed at the same one.
--}}
@php
    $isFailedClose = $errors->close->any() && old('close_rfq_id');
@endphp

<div class="modal fade" id="closeRfqModal" tabindex="-1" aria-labelledby="closeRfqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="closeRfqForm" novalidate
                  action="{{ $isFailedClose ? (old('part') ? route('admin.rfqs.close-part', old('close_rfq_id')) : route('admin.rfqs.close', old('close_rfq_id'))) : '#' }}">
                @csrf
                @method('PATCH')
                {{-- Carried along so a refused submission comes back pointed
                     at the same RFQ and part. --}}
                <input type="hidden" name="close_rfq_id" value="{{ old('close_rfq_id') }}">
                <input type="hidden" name="part" value="{{ old('part') }}">
                <input type="hidden" name="close_label" value="{{ old('close_label') }}">

                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="closeRfqModalLabel">Close</h5>
                        <div class="text-muted-soft small" id="closeRfqTarget">{{ old('close_label') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="close-reference-code" class="form-label">Reference code</label>
                        <input type="text" name="reference_code" id="close-reference-code" maxlength="100" required autocomplete="off"
                               class="form-control @error('reference_code', 'close') is-invalid @enderror"
                               value="{{ $isFailedClose ? old('reference_code') : '' }}">
                        @error('reference_code', 'close')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @else
                            <div class="form-text">Needed to close it — kept with it in Closed RFQs.</div>
                        @enderror
                    </div>
                    <p class="text-muted-soft small mb-0" id="closeRfqHint"></p>
                    <div class="mt-3">
                        @include('admin.rfqs._acting_as', ['role' => 'Business Development', 'id' => 'close-acting-as', 'label' => 'Closed by'])
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-flag"></i> Close</button>
                </div>
            </form>
        </div>
    </div>
</div>

@if ($isFailedClose)
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('closeRfqModal')).show();
            });
        </script>
    @endpush
@endif
