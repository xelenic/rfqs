{{--
    Admin's view of Sourcing's queues (?role=sourcing) — every member's open
    parts, one row per part, rather than the signed-in person's own. Read-only:
    completing a part is its assignee's call, and sending one back is Data
    Entry's. See RfqController::index() ($sourcingOverview).

    Expects: $rfqs, $scopedToReturns (Returns rather than all pending parts).
--}}
<div class="table-responsive">
    <table class="table table-hover mb-0">
        <thead>
            <tr>
                <th>WC Number</th>
                <th>RFQ Number</th>
                <th>Subject</th>
                <th>Sourcing</th>
                <th>Priority</th>
                <th>{{ $scopedToReturns ? 'Returned At' : 'Assigned At' }}</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rfqs as $rfq)
                @php
                    $openParts = $rfq->assignees->whereNull('pivot.completed_at');
                    $listedParts = $scopedToReturns ? $openParts->whereNotNull('pivot.returned_at') : $openParts;
                @endphp
                @foreach ($listedParts as $partAssignment)
                    <tr>
                        <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                        <td class="text-nowrap">{{ $rfq->partNumberLabel($partAssignment->pivot->part_number) }}</td>
                        <td>
                            {{ $rfq->subject }}
                            @if ($partAssignment->pivot->returned_at)
                                <div class="rfq-list-subnote rfq-list-subnote-returned">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                    Returned by Data Entry: {{ $partAssignment->pivot->return_reason }}
                                </div>
                            @endif
                        </td>
                        <td>{{ $partAssignment->name }}</td>
                        <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                        <td class="text-muted-soft">
                            {{ ($scopedToReturns ? $partAssignment->pivot->returned_at : $partAssignment->pivot->created_at)?->format('M d, Y g:i A') ?? '—' }}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted-soft py-4">
                        {{ $scopedToReturns ? 'Nothing has been returned to Sourcing.' : 'No Sourcing parts are pending right now.' }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
