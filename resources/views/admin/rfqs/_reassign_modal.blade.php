{{--
    Reassign — Senior Operations gives a part still with Sourcing (not yet
    marked complete) to another Sourcing member, from the Assigned tab. Shared
    across the part lines, pointed at a part by the Reassign button that opens
    it (admin.js, .js-reassign-part); after a refused submission it comes back
    open against the same part. See RfqController::reassignSourcing().

    Expects: $sourcingUsers (the Sourcing members, with their workload
    counts — User::scopeWithSourcingWorkloadCounts()).
--}}
@php
    $isFailedReassign = $errors->reassign->any();
    $failedReassignRfqId = $isFailedReassign ? old('reassign_rfq_id') : null;
@endphp

<div class="modal fade" id="reassignModal" tabindex="-1" aria-labelledby="reassignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" id="reassignForm" class="modal-content" novalidate
              action="{{ filled($failedReassignRfqId) ? route('admin.rfqs.reassign-sourcing', $failedReassignRfqId) : '#' }}">
            @csrf
            @method('PATCH')
            {{-- Carried along so a refused submission reopens against the same part. --}}
            <input type="hidden" name="reassign_rfq_id" value="{{ $failedReassignRfqId }}">
            <input type="hidden" name="reassign_target" value="{{ $isFailedReassign ? old('reassign_target') : '' }}">
            <input type="hidden" name="part" value="{{ $isFailedReassign ? old('part') : '' }}">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="reassignModalLabel">Reassign Sourcing</h5>
                    <div class="text-muted-soft small" id="reassignTarget">{{ $isFailedReassign ? old('reassign_target') : '' }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <p class="text-muted-soft small">Sourcing hasn't marked it complete yet, so it can go to someone else. It leaves its current member's list and starts afresh — and its countdown — with whoever takes it over.</p>

                <label for="reassign-user" class="form-label">Give it to</label>
                <select name="user_id" id="reassign-user" class="form-select @error('user_id', 'reassign') is-invalid @enderror">
                    <option value="">Pick a Sourcing member…</option>
                    @foreach ($sourcingUsers as $member)
                        <option value="{{ $member->id }}" @selected($isFailedReassign && (string) old('user_id') === (string) $member->id)>
                            {{ $member->name }} · {{ $member->pending_rfqs_count ?? 0 }} open
                        </option>
                    @endforeach
                </select>
                @error('user_id', 'reassign')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror

                <div class="mt-3">
                    @include('admin.rfqs._acting_as', ['role' => 'Senior Operations', 'id' => 'reassign-acting-as', 'label' => 'Reassigned by'])
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
