{{--
    Plain table row for the company-wide RFQ list — shared by the default
    (unscoped) table and Operations' "Unassigned"/"Assigned" tabs, so the
    same row markup and action rules don't have to be kept in sync in two
    places.

    Expects: $rfq, $statusFilter, $restrictAssignment,
    $canSeeAssignOperationsButton, $canSeeAssignButtons.
    Optional: $showClosed — the Closed RFQs list, which has a column for when
    it was closed and by whom.
    Optional: $groupedParts — this row heads a group whose parts follow it
    (see _rfq_parts.blade.php), so it gets the button that folds them away
    and a one-line summary of how far along they are.
    Optional: $canRequestDetails — Senior Operations' Unassigned queue, where
    an RFQ can be sent back to Business Development for details
    (_request_details_modal.blade.php) — or, once it has been, is marked as
    being with them.
    A part of a split stopped on its own (Rfq::changePartStatus()) is noted
    under the subject, with its own Resume or Reopen for Senior Operations —
    except on a row heading its parts, whose own lines show it.
--}}
@php
    $stoppedParts = $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->isStopped());
@endphp
<tr @class(['rfq-group-head' => $groupedParts ?? false])>
    <td class="fw-semibold">
        @if ($groupedParts ?? false)
            <button type="button" class="rfq-group-toggle js-toggle-parts" aria-expanded="true"
                    aria-label="Show or hide the parts of {{ $rfq->rfq_number }}">
                <i class="bi bi-chevron-right"></i>
            </button>
        @endif
        {{ $rfq->wc_number }}
    </td>
    <td>
        {{ $rfq->rfq_number }}
        @if ($groupedParts ?? false)
            @php
                $partsTotal = $rfq->splitTotal();
                $sourcingDone = $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->completed_at !== null)->count();
            @endphp
            {{-- One segment per part, coloured by where it stands. --}}
            <div class="rfq-group-progress">
                @foreach ($rfq->sourcingParts() as $part)
                    @php
                        $partPivot = $part['assignee']?->pivot;
                        $partState = match (true) {
                            $partPivot === null => 'unassigned',
                            $partPivot->isOnHold() => 'on_hold',
                            $partPivot->isCancelled() => 'cancelled',
                            default => $partPivot->progressState(),
                        };
                    @endphp
                    <span class="rfq-seg" data-state="{{ $partState }}"
                          title="{{ $part['number'] }} — {{ $partPivot?->progressLabel() ?? 'Not assigned' }}{{ $partPivot?->isStopped() ? ' · '.($partPivot->isOnHold() ? 'On hold' : 'Cancelled') : '' }}"></span>
                @endforeach
            </div>
            <div class="rfq-group-caption">{{ $sourcingDone }} of {{ $partsTotal }} {{ $partsTotal === 1 ? 'task' : 'parts' }} done by Sourcing</div>
        @endif
    </td>
    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
    @unless ($statusFilter)
        <td><span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->statusLabel() }}</span></td>
    @endunless
    <td>
        {{ $rfq->subject }}
        @if ($rfq->isStopped() && $rfq->status_reason)
            <div class="rfq-list-subnote">
                <i class="bi {{ $rfq->status === \App\Models\Rfq::ON_HOLD ? 'bi-pause-circle' : 'bi-x-circle' }}"></i>
                {{ $rfq->status_reason }}
                @if ($rfq->status_changed_at)
                    · {{ $rfq->status_changed_at->format('M d') }}
                @endif
            </div>
        @endif
        @unless ($groupedParts ?? false)
            @foreach ($stoppedParts as $stoppedAssignee)
                <div class="rfq-list-subnote">
                    <i class="bi {{ $stoppedAssignee->pivot->isOnHold() ? 'bi-pause-circle' : 'bi-x-circle' }}"></i>
                    {{ $rfq->partNumberLabel($stoppedAssignee->pivot->part_number) }} {{ $stoppedAssignee->pivot->isOnHold() ? 'on hold' : 'cancelled' }}@if ($stoppedAssignee->pivot->status_reason): {{ $stoppedAssignee->pivot->status_reason }}@endif
                </div>
            @endforeach
        @endunless
        @if (($canRequestDetails ?? false) && $rfq->isReturnedToBusinessDevelopment())
            <div class="rfq-list-subnote rfq-list-subnote-returned">
                <i class="bi bi-arrow-counterclockwise"></i>
                With Business Development: {{ $rfq->reject_reason }}
            </div>
        @endif
    </td>
    <td class="text-muted-soft">
        {{ $rfq->created_at->format('M d, Y') }}
        @if ($rfq->creator)
            <div class="small">by {{ $rfq->creator->name }}</div>
        @endif
    </td>
    @if ($showClosed ?? false)
        <td class="text-muted-soft">
            {{ $rfq->bd_closed_at?->format('M d, Y g:i A') ?? '—' }}
            @if ($rfq->bdClosedBy)
                <div class="small">by {{ $rfq->bdClosedBy->name }}</div>
            @endif
            @if ($rfq->bd_reference_code)
                <div class="small">Quotation: <span class="fw-semibold text-body">{{ $rfq->bd_reference_code }}</span></div>
            @endif
        </td>
    @endif
    <td class="text-end">
        @if ($rfq->assignees->isNotEmpty())
            {{-- One avatar per person, however many parts they hold. --}}
            @php $assigneeUsers = $rfq->assignees->unique('id')->values(); @endphp
            <span class="assignee-cluster {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                  title="{{ $restrictAssignment ? 'Restricted for your role' : 'Assigned: '.$assigneeUsers->pluck('name')->implode(', ') }}">
                @foreach ($assigneeUsers->take(3) as $assignee)
                    <span class="assignee-avatar">{{ strtoupper(substr($assignee->name, 0, 1)) }}</span>
                @endforeach
                @if ($assigneeUsers->count() > 3)
                    <span class="assignee-avatar assignee-avatar-more">+{{ $assigneeUsers->count() - 3 }}</span>
                @endif
            </span>
        @endif
        {{-- A split that's only partly assigned — the empty parts still need
             someone (see RfqController::assign()). --}}
        @if ($rfq->split_count !== null && $rfq->hasUnassignedParts() && ! $restrictAssignment)
            <span class="badge badge-soft-warning"
                  title="Parts still waiting for a Sourcing member">{{ $rfq->sourcingParts()->whereNull('assignee')->count() }} of {{ $rfq->splitTotal() }} open</span>
        @endif
        <a href="{{ route('admin.rfqs.show', $rfq) }}{{ $statusFilter ? '?status='.$statusFilter : '' }}"
           class="btn btn-sm btn-outline-secondary" title="View details">
            <i class="bi bi-eye"></i>
        </a>
        @can('rfqs.edit')
            @if ($rfq->assignees->isEmpty() && $canSeeAssignOperationsButton)
                <button type="button" class="btn btn-sm btn-outline-secondary js-assign-operations-rfq {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                        {{ $restrictAssignment ? 'disabled' : '' }}
                        data-bs-toggle="modal" data-bs-target="#assignOperationsModal"
                        data-action="{{ route('admin.rfqs.assign-operations', $rfq) }}"
                        data-operations-user-id="{{ $rfq->operations_assigned_by }}"
                        title="{{ $restrictAssignment ? 'Restricted for your role' : 'Assign Operations' }}">
                    <i class="bi bi-diagram-2"></i>
                </button>
            @endif
            @if ($canSeeAssignButtons && $rfq->hasUnassignedParts())
                @include('admin.rfqs._assign_sourcing_button', ['rfq' => $rfq, 'restrictAssignment' => $restrictAssignment])
            @endif
            @if (($canRequestDetails ?? false) && ! $rfq->isReturnedToBusinessDevelopment())
                <button type="button" class="btn btn-sm btn-outline-danger js-request-details"
                        data-bs-toggle="modal" data-bs-target="#requestDetailsModal"
                        data-action="{{ route('admin.rfqs.request-details', $rfq) }}"
                        data-rfq-id="{{ $rfq->id }}"
                        data-label="{{ $rfq->rfq_number }} — {{ $rfq->subject }}"
                        title="Send back to Business Development for details">
                    <i class="bi bi-arrow-counterclockwise"></i> Get Details Again
                </button>
            @endif
        @endcan
        @if ($rfq->isStopped() && auth()->user()->canChangeRfqStatus())
            @include('admin.rfqs._status_resume', ['rfq' => $rfq, 'stoppedPart' => null])
        @endif
        @if (! ($groupedParts ?? false) && $rfq->status === 'Pending' && auth()->user()->canChangeRfqStatus())
            @foreach ($stoppedParts as $stoppedAssignee)
                @include('admin.rfqs._status_resume', ['rfq' => $rfq, 'stoppedPart' => $stoppedAssignee->pivot])
            @endforeach
        @endif
        @if (auth()->user()->hasRole('Admin') || (auth()->user()->hasRole('Business Development') && $rfq->isReturnedToBusinessDevelopment()))
            <button type="button" class="btn btn-sm btn-outline-secondary js-edit-rfq"
                    data-bs-toggle="modal" data-bs-target="#editRfqModal"
                    data-action="{{ route('admin.rfqs.update', $rfq) }}"
                    data-id="{{ $rfq->id }}"
                    data-wc-number="{{ $rfq->wc_number }}"
                    data-rfq-number="{{ $rfq->rfq_number }}"
                    data-priority-level="{{ $rfq->priority_level }}"
                    data-number-of-items="{{ $rfq->number_of_items }}"
                    data-status="{{ $rfq->status }}"
                    data-subject="{{ $rfq->subject }}"
                    data-description="{{ $rfq->description }}">
                <i class="bi bi-pencil"></i>
            </button>
        @endif
    </td>
</tr>
