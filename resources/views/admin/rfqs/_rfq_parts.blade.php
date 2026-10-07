{{--
    The parts of one RFQ, nested under its row in Senior Operations' Assigned
    tab — a line for each part with who holds it and where it stands, so it's
    clear who has what without opening the RFQ. An RFQ kept whole is a single
    "Whole task" line.

    Senior Operations changes status from these lines, not the RFQ's row
    (RfqController::changeStatus(), _status_modal.blade.php): on a split still
    in progress, each part can be put on hold or cancelled on its own — or,
    once it has been, set going again — until it's closed; an RFQ kept whole
    is put on hold or cancelled from its one line, the whole RFQ with it.

    A part still with Sourcing — not yet marked complete — can be reassigned
    to another Sourcing member from its line (Reassign, _reassign_modal);
    once it's gone on to Data Entry, it can't (Rfq::canReassignPart()).

    Expects: $rfq (with its assignees), $columns (the table's column count).
--}}
@php
    $isSplit = $rfq->splitTotal() > 1;
    $canChangeStatus = $rfq->status === 'Pending' && auth()->user()->canChangeRfqStatus();
    $canReassignSourcing = auth()->user()->hasAnyRole(['Senior Operations', 'Admin']);
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
                            @case ('finalized')
                                <span class="rfq-part-detail">
                                    Data Entry {{ $pivot->data_entry_completed_at?->format('M d, g:i A') }}
                                    · Finalized {{ $pivot->finalized_at->format('M d, g:i A') }}
                                </span>
                                @break
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
                        @if ($pivot->isStopped())
                            <span class="rfq-part-detail rfq-part-detail-stopped">
                                <span class="badge {{ $pivot->isOnHold() ? 'badge-soft-info' : 'badge-soft-secondary' }}">
                                    <i class="bi {{ $pivot->isOnHold() ? 'bi-pause-circle' : 'bi-x-circle' }}"></i>
                                    {{ $pivot->isOnHold() ? 'On hold' : 'Cancelled' }}
                                </span>
                                {{ $pivot->status_reason }}
                            </span>
                        @endif
                    </span>

                    @php
                        $canReassign = $canReassignSourcing && $rfq->canReassignPart($part['part']);
                        $canChangeThisStatus = $canChangeStatus && (! $isSplit || ! $pivot->isBdClosed());
                    @endphp
                    @if ($canReassign || $canChangeThisStatus)
                        <span class="rfq-part-actions">
                            @if ($canReassign)
                                <button type="button" class="btn btn-sm btn-outline-primary js-reassign-part"
                                        data-bs-toggle="modal" data-bs-target="#reassignModal"
                                        data-action="{{ route('admin.rfqs.reassign-sourcing', $rfq) }}"
                                        data-rfq-id="{{ $rfq->id }}"
                                        data-part="{{ $part['part'] }}"
                                        data-label="{{ $isSplit ? $part['number'] : $rfq->rfq_number }}"
                                        data-current-id="{{ $assignee->id }}"
                                        data-current-name="{{ $assignee->name }}"
                                        title="Give this part to another Sourcing member">
                                    <i class="bi bi-person-gear"></i> Reassign
                                </button>
                            @endif
                            @if ($canChangeThisStatus && ! $isSplit)
                                @include('admin.rfqs._status_options', [
                                    'rfq' => $rfq,
                                    'statusPart' => null,
                                    'statusOptions' => [\App\Models\Rfq::ON_HOLD, \App\Models\Rfq::CANCELLED],
                                ])
                            @elseif ($canChangeThisStatus)
                                @if ($pivot->isStopped())
                                    @include('admin.rfqs._status_resume', ['rfq' => $rfq, 'stoppedPart' => $pivot])
                                @endif
                                @unless ($pivot->isCancelled())
                                    @include('admin.rfqs._status_options', [
                                        'rfq' => $rfq,
                                        'statusPart' => $part['part'],
                                        'statusOptions' => $pivot->isOnHold() ? [\App\Models\Rfq::CANCELLED] : [\App\Models\Rfq::ON_HOLD, \App\Models\Rfq::CANCELLED],
                                    ])
                                @endunless
                            @endif
                        </span>
                    @endif
                @else
                    <span class="rfq-part-who is-empty">Not assigned yet</span>
                @endif
            </div>
        </td>
    </tr>
@endforeach
