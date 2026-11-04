{{--
    Reassign — Senior Operations gives a part to someone else, from the
    Assigned tab or the RFQ's page: its Sourcing member, at whatever step it's
    got to, its progress kept; and, till it's sent to finalize, who has it in
    Data Entry — someone by name, or anyone there — named ahead while it's
    still with Sourcing, which then can't change it. Either or both. Shared across the
    parts, pointed at one by the Reassign button that opens it (_reassign_button,
    admin.js .js-reassign-part); after a refused submission it comes back open
    against the same part. See RfqController::reassign(),
    Rfq::replaceSourcingPart(), Rfq::replaceDataEntryPart().

    Expects: $sourcingUsers (the Sourcing members, with their workload
    counts — User::scopeWithSourcingWorkloadCounts()).
--}}
@php
    $isFailedReassign = $errors->reassign->any();
    $failedReassignRfqId = $isFailedReassign ? old('reassign_rfq_id') : null;
    $failedReassignRfq = filled($failedReassignRfqId) ? \App\Models\Rfq::query()->find($failedReassignRfqId) : null;
    $failedDataEntry = $failedReassignRfq?->dataEntryStanding((int) old('part'));
@endphp

<div class="modal fade" id="reassignModal" tabindex="-1" aria-labelledby="reassignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="reassignForm" class="modal-content" novalidate
              action="{{ filled($failedReassignRfqId) ? route('admin.rfqs.reassign', $failedReassignRfqId) : '#' }}">
            @csrf
            @method('PATCH')
            {{-- Carried along so a refused submission reopens against the same part. --}}
            <input type="hidden" name="reassign_rfq_id" value="{{ $failedReassignRfqId }}">
            <input type="hidden" name="reassign_target" value="{{ $isFailedReassign ? old('reassign_target') : '' }}">
            <input type="hidden" name="part" value="{{ $isFailedReassign ? old('part') : '' }}">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="reassignModalLabel">Reassign</h5>
                    <div class="text-muted-soft small" id="reassignTarget">{{ $isFailedReassign ? old('reassign_target') : '' }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <label for="reassign-user" class="form-label">Sourcing</label>
                <div class="small text-muted-soft mb-1">Now: <span class="fw-semibold text-body" id="reassign-sourcing-now">{{ $failedReassignRfq?->assigneeForPart((int) old('part'))?->name }}</span></div>
                <select name="user_id" id="reassign-user" class="form-select @error('user_id', 'reassign') is-invalid @enderror">
                    <option value="">Keep as it is</option>
                    @foreach ($sourcingUsers as $member)
                        <option value="{{ $member->id }}" @selected($isFailedReassign && (string) old('user_id') === (string) $member->id)>
                            {{ $member->name }} · {{ $member->pending_rfqs_count ?? 0 }} open
                        </option>
                    @endforeach
                </select>
                @error('user_id', 'reassign')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="form-text">Whoever takes it over has it from here, at whatever step it's at — on their list, theirs to finalize or rework. Everything done on it so far stays, and the time already spent stays with the current member.</div>

                <label for="reassign-data-entry" class="form-label mt-3">Data Entry</label>
                <div class="small text-muted-soft mb-1">Now: <span class="fw-semibold text-body" id="reassign-de-now">{{ $failedDataEntry['label'] ?? '' }}</span></div>
                <div id="reassign-de-field" @class(['d-none' => $isFailedReassign && ! ($failedDataEntry['can_replace'] ?? false)])>
                    <select name="data_entry_user_id" id="reassign-data-entry" class="form-select @error('data_entry_user_id', 'reassign') is-invalid @enderror">
                        <option value="">Keep as it is</option>
                        <option value="anyone" @selected($isFailedReassign && old('data_entry_user_id') === 'anyone')>{{ ($failedDataEntry['handed_over'] ?? true) ? 'Anyone in Data Entry — whoever starts it first' : 'Nobody by name — Sourcing picks who' }}</option>
                        @include('admin.rfqs._data_entry_person_options', ['selectedPerson' => $isFailedReassign ? old('data_entry_user_id') : null])
                    </select>
                    @error('data_entry_user_id', 'reassign')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                    <div @class(['form-text', 'd-none' => ! ($failedDataEntry['handed_over'] ?? true)]) id="reassign-de-hint-handed">If someone's on it, the time they've spent stays theirs. It goes back to waiting for a Start, and the new person's countdown starts from their own Start.</div>
                    <div @class(['form-text', 'd-none' => $failedDataEntry['handed_over'] ?? true]) id="reassign-de-hint-ahead">It goes to them as soon as Sourcing hands it over. Sourcing can't change it, but you can.</div>
                </div>

                <label for="reassign-reason" class="form-label mt-3">Reason <span class="text-muted-soft fw-normal">(optional)</span></label>
                <textarea name="reason" id="reassign-reason" rows="2" maxlength="1000" class="form-control @error('reason', 'reassign') is-invalid @enderror"
                          placeholder="Say, they're on leave this week">{{ $isFailedReassign ? old('reason') : '' }}</textarea>
                <div class="form-text">Posted to the RFQ's comments with the change.</div>
                @error('reason', 'reassign')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror

                <div class="mt-3">
                    @include('admin.rfqs._acting_as', ['role' => 'HBDs Operations', 'id' => 'reassign-acting-as', 'label' => 'Reassigned by'])
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-person-gear"></i> Reassign</button>
            </div>
        </form>
    </div>
</div>

@if (filled($failedReassignRfqId))
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('reassignModal')).show();
            });
        </script>
    @endpush
@endif
