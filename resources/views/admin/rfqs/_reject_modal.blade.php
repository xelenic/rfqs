{{--
    Reject modal — Senior Operations (their own second review), the Head of
    Business Development, GM Assistant, the General Manager, or Business
    Development (from Ready to Close) sends an RFQ, or just one part of it,
    back to an earlier stage with a reason — wherever Rfq::RETURN_TARGETS
    lets it. Shared across the
    index (list) page, populated per-row via JS, see public/js/admin.js
    (.js-reject-rfq).

    The Head of Business Development and the General Manager open it as a
    Return too (_return_button, data-as-return → as_return): the same
    send-back, landing on that role's Returns page instead of its Rejected
    page (Rfq::RETURNABLE_STAGES).

    Expects: $rejectTargetStages — which of Rfq::REJECT_STAGE_ORDER this
    page's own role can use, from Rfq::rejectTargetStages($fromStage) — and
    $rejectRole, that role's name, for Admin's "Rejected by" picker; the
    form's action, the part (none, for a whole RFQ) and its label are set
    per-row by JS.
--}}
@php
    $isFailedReject = $errors->reject->any();
    $isReturn = $isFailedReject && old('as_return');
@endphp

<div class="modal fade" id="rejectRfqModal" tabindex="-1" aria-labelledby="rejectRfqModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" id="rejectRfqForm" action="#" enctype="multipart/form-data" data-confirm="Send this {{ old('part') ? 'part' : 'RFQ' }} back? Any approval already given for it is undone.">
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
                {{-- A Return rather than a Reject (_return_button). --}}
                <input type="hidden" name="as_return" value="{{ $isReturn ? '1' : '' }}">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title" id="rejectRfqModalLabel">{{ $isReturn ? 'Return' : 'Reject & Return' }}</h5>
                        <div class="text-muted-soft small" id="rejectRfqTarget">{{ old('reject_label') }}</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted-soft small" id="reject-kind-note">
                        {{ $isReturn ? 'Sent back for changes, not rejected — it goes on their Returns page.' : 'A rejection — it goes on their Rejected page.' }}
                    </p>
                    <div class="mb-3">
                        <label for="reject-target-stage" class="form-label">Send back to</label>
                        <select name="target_stage" id="reject-target-stage" class="form-select @error('target_stage', 'reject') is-invalid @enderror" required>
                            @foreach ($rejectTargetStages as $stage)
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
                        @include('admin.rfqs._attachments_input', ['attachmentsId' => 'reject-attachments'])
                    </div>
                    @isset($rejectRole)
                        <div class="mt-3">
                            @include('admin.rfqs._acting_as', ['role' => $rejectRole, 'id' => 'reject-acting-as', 'label' => 'Rejected by'])
                        </div>
                    @endisset
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn {{ $isReturn ? 'btn-primary' : 'btn-danger' }}" id="reject-submit">{{ $isReturn ? 'Return' : 'Send back' }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
