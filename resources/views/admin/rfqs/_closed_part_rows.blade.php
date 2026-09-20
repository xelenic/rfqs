{{--
    A split RFQ's closed parts on the Closed RFQs list — one row for each part
    Business Development has closed, so a part shows up there as soon as it's
    closed, whatever the rest of its RFQ is doing. An RFQ that was closed
    whole, before parts were closed one by one, lists all its parts as closed
    when it was. No Sourcing names: Business Development doesn't see who holds
    a part.

    Expects: $rfq (with its assignees), $bdClosedNames (user id => name).
--}}
@foreach ($rfq->assignees as $assignee)
    @php
        $isClosed = $assignee->pivot->bd_closed_at !== null || $rfq->status === 'Completed';
        $closedAt = $assignee->pivot->bd_closed_at ?? $rfq->bd_closed_at;
        $closedByName = $bdClosedNames->get($assignee->pivot->bd_closed_by) ?? ($assignee->pivot->bd_closed_at === null ? $rfq->bdClosedBy?->name : null);
    @endphp
    @continue(! $isClosed)
    <tr>
        <td class="fw-semibold">{{ $rfq->wc_number }}</td>
        <td class="text-nowrap">{{ $rfq->partNumberLabel($assignee->pivot->part_number) }}</td>
        <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
        <td>{{ $rfq->subject }}</td>
        <td class="text-muted-soft">
            {{ $rfq->created_at->format('M d, Y') }}
            @if ($rfq->creator)
                <div class="small">by {{ $rfq->creator->name }}</div>
            @endif
        </td>
        <td class="text-muted-soft">
            {{ $closedAt?->format('M d, Y g:i A') ?? '—' }}
            @if ($closedByName)
                <div class="small">by {{ $closedByName }}</div>
            @endif
        </td>
        <td class="text-end">
            <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Completed" class="btn btn-sm btn-outline-secondary" title="View details">
                <i class="bi bi-eye"></i>
            </a>
        </td>
    </tr>
@endforeach
