{{--
    Reject modal — Head of Business Development sends an RFQ, or just one part
    of it, back to an earlier stage with a reason. Shared across the index
    (list) page, populated per-row via JS, see public/js/admin.js
    (.js-reject-rfq).

    Expects: nothing extra — Rfq::REJECT_TARGET_STAGES/stageLabel() are
    static, and the form's action, the part (none, for a whole RFQ) and its
    label are set per-row by JS.
--}}
@php
    $isFailedReject = $errors->reject->any();
@endphp

<div class="modal fade" id="rejectRfqModal" tabindex="-1" aria-labelledby="rejectRfqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="rejectRfqForm" action="#" data-confirm="Send this {{ old('part') ? 'part' : 'RFQ' }} back? Any approval already given for it is undone.">
                @csrf
                @method('PATCH')
                {{-- Not part of the request the route needs — just carried
                     along so a failed submission can be reopened against
                     the right RFQ, since the form's real action is set by
                     JS per-row rather than fixed server-side. Its own name
                     (not "rfq_id") avoids colliding with the create/edit
                     modal's own rfq_id field elsewhere on this page. --}}
                <input type="hidden" name="reject_rfq_id" value="{{ old('reject_rfq_id') }}">
                {{-- The part being sent back, if it's one — empty for a whole RFQ. --}}
                <input type="hidden" name="part" value="{{ old('part') }}">
                <input type="hidden" name="reject_label" value="{{ old('reject_label') }}">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="rejectRfqModalLabel">Reject &amp; Return</h5>
                        <div class="text-muted-soft small" id="rejectRfqTarget">{{ old('reject_label') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="reject-target-stage" class="form-label">Send back to</label>
                        <select name="target_stage" id="reject-target-stage" class="form-select @error('target_stage', 'reject') is-invalid @enderror" required>
                            @foreach (\App\Models\Rfq::REJECT_TARGET_STAGES as $stage)
                                <option value="{{ $stage }}" {{ old('target_stage') === $stage ? 'selected' : '' }}>
                                    {{ \App\Models\Rfq::stageLabel($stage) }}
                                </option>
                            @endforeach
                        </select>
                        @error('target_stage', 'reject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-0">
                        <label for="reject-reason" class="form-label">Reason</label>
                        <textarea name="reason" id="reject-reason" rows="3" class="form-control @error('reason', 'reject') is-invalid @enderror">{{ $isFailedReject ? old('reason') : '' }}</textarea>
                        @error('reason', 'reject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
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
