{{--
    Plain table row for the company-wide RFQ list — shared by the default
    (unscoped) table and Operations' "Unassigned"/"Assigned" tabs, so the
    same row markup and action rules don't have to be kept in sync in two
    places.

    Expects: $rfq, $statusFilter, $restrictAssignment,
    $canSeeAssignOperationsButton, $canSeeAssignButtons.
--}}
<tr>
    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
    <td>{{ $rfq->rfq_number }}</td>
    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
    @unless ($statusFilter)
        <td><span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->status }}</span></td>
    @endunless
    <td>{{ $rfq->subject }}</td>
    <td class="text-muted-soft">
        {{ $rfq->created_at->format('M d, Y') }}
        @if ($rfq->creator)
            <div class="small">by {{ $rfq->creator->name }}</div>
        @endif
    </td>
    <td class="text-end">
        @if ($rfq->assignees->isNotEmpty())
            <span class="assignee-cluster {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                  title="{{ $restrictAssignment ? 'Restricted for your role' : 'Assigned: '.$rfq->assignees->pluck('name')->implode(', ') }}">
                @foreach ($rfq->assignees->take(3) as $assignee)
                    <span class="assignee-avatar">{{ strtoupper(substr($assignee->name, 0, 1)) }}</span>
                @endforeach
                @if ($rfq->assignees->count() > 3)
                    <span class="assignee-avatar assignee-avatar-more">+{{ $rfq->assignees->count() - 3 }}</span>
                @endif
            </span>
        @endif
        <a href="{{ route('admin.rfqs.show', $rfq) }}{{ $statusFilter ? '?status='.$statusFilter : '' }}"
           class="btn btn-sm btn-outline-secondary" title="View details">
            <i class="bi bi-eye"></i>
        </a>
        @can('rfqs.edit')
            @if ($rfq->assignees->isEmpty())
                @if ($canSeeAssignOperationsButton)
                    <button type="button" class="btn btn-sm btn-outline-secondary js-assign-operations-rfq {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                            {{ $restrictAssignment ? 'disabled' : '' }}
                            data-bs-toggle="modal" data-bs-target="#assignOperationsModal"
                            data-action="{{ route('admin.rfqs.assign-operations', $rfq) }}"
                            data-operations-user-id="{{ $rfq->operations_assigned_by }}"
                            title="{{ $restrictAssignment ? 'Restricted for your role' : 'Assign Operations' }}">
                        <i class="bi bi-diagram-2"></i>
                    </button>
                @endif
                @if ($canSeeAssignButtons)
                    <button type="button" class="btn btn-sm btn-outline-secondary js-assign-rfq {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                            {{ $restrictAssignment ? 'disabled' : '' }}
                            data-bs-toggle="modal" data-bs-target="#assignRfqModal"
                            data-action="{{ route('admin.rfqs.assign', $rfq) }}"
                            data-assigned="{{ $rfq->assignees->pluck('id')->implode(',') }}"
                            title="{{ $restrictAssignment ? 'Restricted for your role' : 'Assign Sourcing' }}">
                        <i class="bi bi-person-plus"></i>
                    </button>
                @endif
            @endif
        @endcan
        @if (auth()->user()->hasRole('Admin'))
            <button type="button" class="btn btn-sm btn-outline-secondary js-edit-rfq"
                    data-bs-toggle="modal" data-bs-target="#editRfqModal"
                    data-action="{{ route('admin.rfqs.update', $rfq) }}"
                    data-id="{{ $rfq->id }}"
                    data-wc-number="{{ $rfq->wc_number }}"
                    data-rfq-number="{{ $rfq->rfq_number }}"
                    data-priority-level="{{ $rfq->priority_level }}"
                    data-status="{{ $rfq->status }}"
                    data-subject="{{ $rfq->subject }}"
                    data-description="{{ $rfq->description }}">
                <i class="bi bi-pencil"></i>
            </button>
        @endif
        @can('rfqs.delete')
            <form action="{{ route('admin.rfqs.destroy', $rfq) }}" method="POST" class="d-inline" data-confirm="Delete this RFQ?">
                @csrf
                @method('DELETE')
                @if ($statusFilter)
                    <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                @endif
                <button type="submit" class="btn btn-sm btn-outline-danger">
                    <i class="bi bi-trash"></i>
                </button>
            </form>
        @endcan
    </td>
</tr>
