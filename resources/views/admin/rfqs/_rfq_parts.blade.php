{{--
    The parts of one RFQ, nested under its row in Senior Operations' Assigned
    tab — a line for each part with who holds it and where it stands, so it's
    clear who has what without opening the RFQ. An RFQ kept whole is a single
    "Whole task" line.

    Expects: $rfq (with its assignees), $columns (the table's column count).
--}}
@php
    $isSplit = $rfq->splitTotal() > 1;
@endphp
@foreach ($rfq->sourcingParts() as $part)
    @php
        $assignee = $part['assignee'];
        $pivot = $assignee?->pivot;
    @endphp
    <tr class="rfq-part-row">
        <td colspan="{{ $columns }}">
            <div class="rfq-part-line">
                <span class="rfq-part-name">{{ $isSplit ? $part['number'] : 'Whole task' }}</span>

                @if ($assignee)
                    <span class="rfq-part-who">
                        <span class="assignee-avatar">{{ strtoupper(substr($assignee->name, 0, 1)) }}</span>
                        <span>
                            <span class="rfq-part-user">{{ $assignee->name }}</span>
                            <span class="assignee-email text-muted-soft d-block">{{ $assignee->email }}</span>
                        </span>
                    </span>

                    <span class="rfq-part-assigned">
                        <span class="rfq-part-caption">Assigned</span>
                        {{ $pivot->created_at?->format('M d, Y g:i A') ?? '—' }}
                    </span>

                    <span class="rfq-part-status">
                        <span class="badge {{ $pivot->progressBadgeClass() }}">{{ $pivot->progressLabel() }}</span>
                        @switch ($pivot->progressState())
                            @case ('data_entry_done')
                                <span class="rfq-part-detail">
                                    Sourcing done {{ $pivot->completed_at?->format('M d, g:i A') }}
                                    · Data Entry {{ $pivot->data_entry_completed_at->format('M d, g:i A') }}
                                </span>
                                @break
                            @case ('with_data_entry')
                                <span class="rfq-part-detail">Sourcing done {{ $pivot->completed_at->format('M d, g:i A') }}</span>
                                @break
                            @case ('returned')
                                <span class="rfq-part-detail rfq-part-detail-returned">
                                    Returned {{ $pivot->returned_at->format('M d, g:i A') }}@if ($pivot->return_reason): {{ $pivot->return_reason }}@endif
                                </span>
                                @break
                        @endswitch
                    </span>
                @else
                    <span class="rfq-part-who is-empty">Not assigned yet</span>
                @endif
            </div>
        </td>
    </tr>
@endforeach
