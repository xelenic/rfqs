@php
    $pageTitle = match (true) {
        $scopedToReturns => 'Returns',
        $scopedToSeniorOpsReview => 'Review',
        $sourcingOverview && $scopedToMe => 'Pending RFQs',
        $statusFilter === 'Pending' && $scopedToMe => 'My Pending RFQs',
        $statusFilter === 'Pending' && $scopedToDataEntry => 'Ready for Data Entry',
        $statusFilter === 'Pending' && $scopedToUnassigned => 'Unassigned RFQs',
        $statusFilter === 'Pending' && $scopedToHeadOfBdReview => 'Review',
        $statusFilter === 'Pending' && $scopedToGmAssistant => 'Review',
        $statusFilter === 'Pending' && $scopedToGmReview => 'Review',
        $statusFilter === 'Pending' && $scopedToBdClosing => 'Ready to Close',
        $statusFilter === 'Pending' => 'Pending RFQs',
        $statusFilter === 'Completed' => 'Closed RFQs',
        default => 'RFQs',
    };

    // Business Development can see that Sourcing/Operations assignment
    // exists on an RFQ, but the controls are blurred and inert for them —
    // that's a deliberate role-specific UI choice, not a permission gap.
    $restrictAssignment = auth()->user()->hasRole('Business Development');

    // Sourcing never gets access to the assign controls at all — it's a
    // receiving role, not an assigning one — enforced server-side too, see
    // RfqController::assign()/assignOperations().
    $canSeeAssignButtons = ! auth()->user()->hasRole('Sourcing');

    // Operations doesn't need a separate "Assign Operations" picker — when
    // they assign Sourcing, they're implicitly recorded as the one routing
    // it (see RfqController::assign()), so just the one button is shown.
    $canSeeAssignOperationsButton = $canSeeAssignButtons && ! auth()->user()->hasRole('Senior Operations');
@endphp

@extends('layouts.app')

{{-- On one of Admin's per-role pages, say whose it is. --}}
@section('title', $lensRole ? $pageTitle.' · '.$lensRole : $pageTitle)

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <form method="GET" class="d-flex gap-2">
                @if ($statusFilter)
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
                @endif
                {{-- Keep the queue being searched: a role's second view
                     (Returns, Review, …) and Admin's per-role page. --}}
                @if (in_array(request('view'), \App\Models\Rfq::QUEUE_VIEWS, true))
                    <input type="hidden" name="view" value="{{ request('view') }}">
                @endif
                @if ($lensRole)
                    <input type="hidden" name="role" value="{{ \Illuminate\Support\Str::slug($lensRole) }}">
                @endif
                @if ($opsFilters)
                    @include('admin.rfqs._ops_filters_carry')
                @endif
                <input type="search" name="search" value="{{ $search }}" class="form-control form-control-sm" placeholder="Search WC number, RFQ number, subject..." style="min-width:260px;">
                <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
            </form>

            @can('rfqs.create')
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createRfqModal">
                    <i class="bi bi-plus-lg"></i> Add RFQ
                </button>
            @endcan
        </div>

        @if ($sourcingOverview && ($scopedToMe || $scopedToReturns))
            {{-- Admin looking at Sourcing's queues: every member's open
                 parts rather than one person's own. --}}
            @include('admin.rfqs._sourcing_overview')
        @elseif ($scopedToDataEntry)
            {{-- Data Entry's queue — one row per Sourcing assignee's
                 completed part, shown as soon as *they* mark it done, each
                 by each, rather than waiting for every assignee on a split
                 RFQ to finish. See RfqController::index() ($bySourcingRfqs). --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Sourcing</th>
                            <th>Assigned At</th>
                            <th>Completed At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bySourcingRfqs as $rfq)
                            @foreach ($rfq->assignees->whereNotNull('pivot.completed_at')->whereNull('pivot.data_entry_completed_at') as $assignee)
                                {{-- Clicking the row opens a quick-detail modal scoped to
                                     this one assignee (subject, description, their own
                                     comments only — other split sourcers' comments stay
                                     out of it) — handled in admin.js rather than
                                     data-bs-toggle directly on the row, so the nested Mark
                                     Complete button still works on its own. --}}
                                <tr class="js-de-sourcing-row" data-bs-target="#rfq-detail-modal-{{ $rfq->id }}-p{{ $assignee->pivot->part_number }}" role="button" tabindex="0">
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->partNumberLabel($assignee->pivot->part_number) }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td>{{ $assignee->name }}</td>
                                    <td class="text-muted-soft">{{ $assignee->pivot->created_at?->format('M d, Y g:i A') ?? '—' }}</td>
                                    <td class="text-muted-soft">{{ $assignee->pivot->completed_at->format('M d, Y g:i A') }}</td>
                                    <td class="text-end text-nowrap">
                                        {{-- Only this one part — see
                                             RfqController::completeDataEntry() and returnSourcing(),
                                             which never touch any other part on the same RFQ. Each
                                             asks for a comment first. --}}
                                        <div class="d-inline-flex gap-2">
                                            @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $assignee->pivot->part_number, 'kind' => 'return', 'who' => $assignee->name])
                                            @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $assignee->pivot->part_number, 'kind' => 'data_entry', 'who' => $assignee->name])
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted-soft py-4">
                                    Nothing's been completed by Sourcing yet.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($bySourcingRfqs->hasPages())
                <div class="card-footer bg-white">
                    {{ $bySourcingRfqs->links() }}
                </div>
            @endif

            @foreach ($bySourcingRfqs as $rfq)
                @foreach ($rfq->assignees->whereNotNull('pivot.completed_at')->whereNull('pivot.data_entry_completed_at') as $assignee)
                    @include('admin.rfqs._rfq_detail_modal', ['rfq' => $rfq, 'assignee' => $assignee])
                @endforeach
            @endforeach
        @elseif ($scopedToReturns)
            {{-- Parts Data Entry sent back for rework, scoped to this
                 Sourcing member and not yet completed again — see
                 RfqController::index() ($scopedToReturns) and
                 Rfq::returnSourcingPartFor(). --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Returned At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            {{-- One row per returned part of mine — someone holding
                                 several parts of a split can have more than one. --}}
                            @foreach ($rfq->assignees->where('id', auth()->id())->whereNotNull('pivot.returned_at')->whereNull('pivot.completed_at') as $myAssignment)
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->partNumberLabel($myAssignment->pivot->part_number) }}</td>
                                    <td>
                                        {{ $rfq->subject }}
                                        @if ($myAssignment->pivot->return_reason)
                                            <div class="rfq-list-subnote rfq-list-subnote-returned">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                                {{ $myAssignment->pivot->return_reason }}
                                            </div>
                                        @endif
                                    </td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">{{ $myAssignment->pivot->returned_at?->format('M d, Y g:i A') ?? '—' }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted-soft py-4">
                                    Nothing's been returned to you — you're all caught up.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($scopedToSeniorOpsReview)
            {{-- Senior Operations' second review — one row per Sourcing part that
                 has been through both Sourcing and Data Entry, each approved on
                 its own (and only those: a part still with Sourcing or Data
                 Entry, or already approved, isn't listed). The RFQ escalates to
                 Head of Business Development once every part has been approved.
                 An RFQ kept whole is one row. See RfqController::index()
                 ($scopedToSeniorOpsReview) and Rfq::approveSeniorOpsPart(). --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Sourcing</th>
                            <th>Sourcing Done</th>
                            <th>Data Entry Done</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($seniorOpsReviewRfqs as $rfq)
                            @php $readyParts = $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->isAwaitingSeniorOpsReview()); @endphp
                            @foreach ($readyParts as $assignee)
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->partNumberLabel($assignee->pivot->part_number) }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td>{{ $assignee->name }}</td>
                                    <td class="text-muted-soft">{{ $assignee->pivot->completed_at->format('M d, Y g:i A') }}</td>
                                    <td class="text-muted-soft">
                                        {{ $assignee->pivot->data_entry_completed_at->format('M d, Y g:i A') }}
                                        @if ($dataEntryNames->has($assignee->pivot->data_entry_completed_by))
                                            <div class="text-muted-soft small">by {{ $dataEntryNames->get($assignee->pivot->data_entry_completed_by) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        {{-- Only this one part — see RfqController::approveSeniorOpsPart(). --}}
                                        <form action="{{ route('admin.rfqs.approve-senior-ops-part', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Approve {{ $rfq->partNumberLabel($assignee->pivot->part_number) }}?{{ $rfq->isSplit() ? ' The RFQ moves on to Head of Business Development once every part is approved.' : ' It moves on to Head of Business Development.' }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="part" value="{{ $assignee->pivot->part_number }}">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Approve
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            @if ($readyParts->isEmpty())
                                {{-- At its review stage with no part of its own to list: the RFQ
                                     as a whole. --}}
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->rfq_number }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">—</td>
                                    <td class="text-muted-soft">—</td>
                                    <td class="text-muted-soft">
                                        {{ $rfq->data_entry_completed_at?->format('M d, Y g:i A') ?? '—' }}
                                        @if ($rfq->dataEntryCompletedBy)
                                            <div class="text-muted-soft small">by {{ $rfq->dataEntryCompletedBy->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <form action="{{ route('admin.rfqs.complete-senior-ops-review', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Approve this RFQ? It moves on to Head of Business Development.">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Approve
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted-soft py-4">
                                    Nothing's waiting on your review right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($seniorOpsReviewRfqs->hasPages())
                <div class="card-footer bg-white">
                    {{ $seniorOpsReviewRfqs->links() }}
                </div>
            @endif
        @elseif ($scopedToHeadOfBdReview)
            {{-- Head of Business Development's approval queue — one row per part
                 Senior Operations has approved, each as it comes rather than once
                 the whole RFQ has been. Approving a part is on its own; the RFQ
                 escalates to GM Assistant once every part has been approved.
                 Rejecting sends just that part back to an earlier stage with a
                 reason. An RFQ kept whole is one row. See RfqController::index()
                 ($scopedToHeadOfBdReview), Rfq::approveHeadOfBdPart() /
                 rejectPartToStage(). --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Sourcing</th>
                            <th>Approved by Senior Operations</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            @php $readyParts = $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->isAwaitingHeadOfBdReview()); @endphp
                            @foreach ($readyParts as $assignee)
                                @php $partLabel = $rfq->partNumberLabel($assignee->pivot->part_number); @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $partLabel }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td>{{ $assignee->name }}</td>
                                    <td class="text-muted-soft">
                                        {{ $assignee->pivot->senior_ops_reviewed_at->format('M d, Y g:i A') }}
                                        @if ($seniorOpsNames->has($assignee->pivot->senior_ops_reviewed_by))
                                            <div class="text-muted-soft small">by {{ $seniorOpsNames->get($assignee->pivot->senior_ops_reviewed_by) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        {{-- Only this one part — see
                                             RfqController::approveHeadOfBdPart() and rejectHeadOfBd(). --}}
                                        <form action="{{ route('admin.rfqs.approve-head-of-bd-part', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Approve {{ $partLabel }}?{{ $rfq->isSplit() ? ' The RFQ moves on to GM Assistant once every part is approved.' : ' It moves on to GM Assistant.' }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="part" value="{{ $assignee->pivot->part_number }}">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Approve
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger js-reject-rfq"
                                                data-bs-toggle="modal" data-bs-target="#rejectRfqModal"
                                                data-action="{{ route('admin.rfqs.reject-head-of-bd', $rfq) }}"
                                                data-rfq-id="{{ $rfq->id }}"
                                                data-part="{{ $assignee->pivot->part_number }}"
                                                data-label="{{ $partLabel }}">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reject
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            @if ($readyParts->isEmpty())
                                {{-- At its review stage with no part of its own to list: the RFQ
                                     as a whole. --}}
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->rfq_number }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">—</td>
                                    <td class="text-muted-soft">
                                        {{ $rfq->senior_ops_reviewed_at?->format('M d, Y g:i A') ?? '—' }}
                                        @if ($rfq->seniorOpsReviewedBy)
                                            <div class="text-muted-soft small">by {{ $rfq->seniorOpsReviewedBy->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <form action="{{ route('admin.rfqs.approve-head-of-bd', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Approve this RFQ? It moves on to GM Assistant.">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Approve
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-outline-danger js-reject-rfq"
                                                data-bs-toggle="modal" data-bs-target="#rejectRfqModal"
                                                data-action="{{ route('admin.rfqs.reject-head-of-bd', $rfq) }}"
                                                data-rfq-id="{{ $rfq->id }}">
                                            <i class="bi bi-arrow-counterclockwise"></i> Reject
                                        </button>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted-soft py-4">
                                    Nothing's waiting on your review right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($scopedToGmAssistant)
            {{-- GM Assistant's queue — one row per part Head of Business
                 Development has approved, each as it comes rather than once the
                 whole RFQ has been, waiting on client details and payment terms
                 before going on to the General Manager. The details belong to
                 the RFQ, so the form comes with what's already been given. The
                 RFQ goes on to the General Manager once every part has been
                 through. An RFQ kept whole is one row. See
                 RfqController::index() ($scopedToGmAssistant),
                 Rfq::recordGmAssistantPart(). --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Sourcing</th>
                            <th>Approved by Head of BD</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            @php $readyParts = $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->isAwaitingGmAssistant()); @endphp
                            @foreach ($readyParts as $assignee)
                                @php $partLabel = $rfq->partNumberLabel($assignee->pivot->part_number); @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $partLabel }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td>{{ $assignee->name }}</td>
                                    <td class="text-muted-soft">
                                        {{ $assignee->pivot->head_of_bd_approved_at->format('M d, Y g:i A') }}
                                        @if ($headOfBdNames->has($assignee->pivot->head_of_bd_approved_by))
                                            <div class="text-muted-soft small">by {{ $headOfBdNames->get($assignee->pivot->head_of_bd_approved_by) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        {{-- Only this one part — see RfqController::submitGmAssistantDetails(). --}}
                                        <button type="button" class="btn btn-sm btn-primary js-gm-assistant-rfq"
                                                data-bs-toggle="modal" data-bs-target="#gmAssistantModal"
                                                data-action="{{ route('admin.rfqs.gm-assistant-details', $rfq) }}"
                                                data-rfq-id="{{ $rfq->id }}"
                                                data-part="{{ $assignee->pivot->part_number }}"
                                                data-label="{{ $partLabel }}"
                                                data-client-details="{{ $rfq->client_details }}"
                                                data-payment-terms="{{ $rfq->payment_terms }}">
                                            <i class="bi bi-pencil-square"></i> Add Details
                                        </button>
                                    </td>
                                </tr>
                            @endforeach
                            @if ($readyParts->isEmpty())
                                {{-- At their step with no part of its own to list: the RFQ as a
                                     whole. --}}
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->rfq_number }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">—</td>
                                    <td class="text-muted-soft">
                                        {{ $rfq->head_of_bd_approved_at?->format('M d, Y g:i A') ?? '—' }}
                                        @if ($rfq->headOfBdApprovedBy)
                                            <div class="text-muted-soft small">by {{ $rfq->headOfBdApprovedBy->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-sm btn-primary js-gm-assistant-rfq"
                                                data-bs-toggle="modal" data-bs-target="#gmAssistantModal"
                                                data-action="{{ route('admin.rfqs.gm-assistant-details', $rfq) }}"
                                                data-rfq-id="{{ $rfq->id }}"
                                                data-client-details="{{ $rfq->client_details }}"
                                                data-payment-terms="{{ $rfq->payment_terms }}">
                                            <i class="bi bi-pencil-square"></i> Add Details
                                        </button>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted-soft py-4">
                                    Nothing's waiting on you right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($scopedToGmReview)
            {{-- General Manager's final approval queue — one row per part GM
                 Assistant has finished adding client details/payment terms to,
                 each as it comes rather than once the whole RFQ has been.
                 Approving a part is on its own; the RFQ is ready for Business
                 Development to close once every part has been approved. An RFQ
                 kept whole is one row. See RfqController::index()
                 ($scopedToGmReview), Rfq::approveGmPart(). --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Sourcing</th>
                            <th>Details Added</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            @php $readyParts = $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->isAwaitingGmApproval()); @endphp
                            @foreach ($readyParts as $assignee)
                                @php $partLabel = $rfq->partNumberLabel($assignee->pivot->part_number); @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $partLabel }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td>{{ $assignee->name }}</td>
                                    <td class="text-muted-soft">
                                        {{ $assignee->pivot->gm_assistant_completed_at->format('M d, Y g:i A') }}
                                        @if ($gmAssistantNames->has($assignee->pivot->gm_assistant_completed_by))
                                            <div class="text-muted-soft small">by {{ $gmAssistantNames->get($assignee->pivot->gm_assistant_completed_by) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        {{-- Only this one part — see RfqController::approveGmPart(). --}}
                                        <form action="{{ route('admin.rfqs.approve-gm-part', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Approve {{ $partLabel }}?{{ $rfq->isSplit() ? ' The RFQ moves on to Business Development to close once every part is approved.' : ' It moves on to Business Development to close.' }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="part" value="{{ $assignee->pivot->part_number }}">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Approve
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            @if ($readyParts->isEmpty())
                                {{-- At their step with no part of its own to list: the RFQ as a
                                     whole. --}}
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->rfq_number }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">—</td>
                                    <td class="text-muted-soft">
                                        {{ $rfq->gm_assistant_completed_at?->format('M d, Y g:i A') ?? '—' }}
                                        @if ($rfq->gmAssistantCompletedBy)
                                            <div class="text-muted-soft small">by {{ $rfq->gmAssistantCompletedBy->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <form action="{{ route('admin.rfqs.approve-gm', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Approve this RFQ? It moves on to Business Development to close.">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Approve
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="7" class="text-center text-muted-soft py-4">
                                    Nothing's waiting on your approval right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($scopedToBdClosing)
            {{-- Business Development's closing queue — one row per part the
                 General Manager has approved, each as it comes rather than once
                 the whole RFQ has been, ready to send to the client and close.
                 Closing a part is on its own and puts it in Closed RFQs; the RFQ
                 itself closes once every part has been. An RFQ kept whole is one
                 row. No Sourcing names: Business Development doesn't see who
                 holds a part. See RfqController::index() ($scopedToBdClosing),
                 Rfq::closePart(). --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Approved by GM</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            @php $readyParts = $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->isAwaitingBdClosing()); @endphp
                            @foreach ($readyParts as $assignee)
                                @php $partLabel = $rfq->partNumberLabel($assignee->pivot->part_number); @endphp
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $partLabel }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">
                                        {{ $assignee->pivot->gm_approved_at->format('M d, Y g:i A') }}
                                        @if ($gmNames->has($assignee->pivot->gm_approved_by))
                                            <div class="text-muted-soft small">by {{ $gmNames->get($assignee->pivot->gm_approved_by) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        {{-- Only this one part — see RfqController::closePart(). --}}
                                        <form action="{{ route('admin.rfqs.close-part', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Close {{ $partLabel }}? It moves into Closed RFQs.{{ $rfq->isSplit() ? ' The RFQ closes once every part is closed.' : '' }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="part" value="{{ $assignee->pivot->part_number }}">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-flag"></i> Close
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                            @if ($readyParts->isEmpty())
                                {{-- At its closing stage with no part of its own to list: the RFQ
                                     as a whole. --}}
                                <tr>
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->rfq_number }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">
                                        {{ $rfq->gm_approved_at?->format('M d, Y g:i A') ?? '—' }}
                                        @if ($rfq->gmApprovedBy)
                                            <div class="text-muted-soft small">by {{ $rfq->gmApprovedBy->name }}</div>
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <form action="{{ route('admin.rfqs.close', $rfq) }}" method="POST" class="d-inline"
                                              data-confirm="Close this RFQ? It moves out of Pending into Closed RFQs.">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-flag"></i> Close
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted-soft py-4">
                                    Nothing's ready to close right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @elseif ($scopedToMe)
            {{-- Sourcing's personal reference — their own split RFQ number
                 and when they were assigned, rather than the fuller
                 workflow view everyone else gets. A split task stays
                 listed here for every assignee even after a teammate marks
                 it complete and hands the whole RFQ to Data Entry — it's
                 shown as a "handed off" sub-note rather than disappearing,
                 so nobody's own split assignment silently vanishes. --}}
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Assigned At</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            {{-- One row per part assigned to me — someone holding
                                 several parts of a split RFQ sees each one on its
                                 own, with its own part number and its own Mark
                                 Complete. --}}
                            @foreach ($rfq->assignees->where('id', auth()->id()) as $myAssignment)
                                @php
                                    $myPart = $myAssignment->pivot->part_number;
                                    $iHaveCompletedMyPart = $myAssignment->pivot->completed_at !== null;
                                    $myPartWasReturned = ! $iHaveCompletedMyPart && $myAssignment->pivot->returned_at !== null;
                                @endphp
                                {{-- Clicking the row (but not the Mark Complete button) opens
                                     the same quick-detail modal Data Entry uses (description,
                                     status, comments scoped to you) — see admin.js, which
                                     already skips nested buttons/forms. --}}
                                <tr class="js-de-sourcing-row" data-bs-target="#rfq-detail-modal-{{ $rfq->id }}-p{{ $myPart }}" role="button" tabindex="0">
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td class="text-nowrap">{{ $rfq->partNumberLabel($myPart) }}</td>
                                    <td>
                                        {{ $rfq->subject }}
                                        @if ($rfq->isWithDataEntry())
                                            <div class="rfq-list-subnote">
                                                <i class="bi bi-check2-circle"></i>
                                                Handed off to Data Entry
                                                @if ($rfq->sourcingCompletedBy)
                                                    &middot; completed by {{ $rfq->sourcing_completed_by === auth()->id() ? 'you' : $rfq->sourcingCompletedBy->name }}
                                                @endif
                                            </div>
                                        @elseif ($myPartWasReturned)
                                            <div class="rfq-list-subnote rfq-list-subnote-returned">
                                                <i class="bi bi-arrow-counterclockwise"></i>
                                                Returned by Data Entry: {{ $myAssignment->pivot->return_reason }}
                                            </div>
                                        @endif
                                    </td>
                                    <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                    <td class="text-muted-soft">{{ $myAssignment->pivot->created_at?->format('M d, Y g:i A') ?? '—' }}</td>
                                    <td class="text-end text-nowrap">
                                        @if (! $iHaveCompletedMyPart)
                                            @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $myPart])
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted-soft py-4">
                                    No pending RFQs are assigned to you right now.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @foreach ($rfqs as $rfq)
                @foreach ($rfq->assignees->where('id', auth()->id()) as $myAssignment)
                    @include('admin.rfqs._rfq_detail_modal', ['rfq' => $rfq, 'assignee' => $myAssignment])
                @endforeach
            @endforeach
        @elseif ($scopedToUnassigned)
            {{-- Operations gets a choice of two tabs on their own scoped
                 "Pending RFQs": their actionable backlog (nobody's
                 assigned to Sourcing yet), and a reference view of what's
                 already routed but still Pending overall. --}}
            @php
                // Filters narrow both tabs, and what they left is what the
                // counts on the tabs show; clearing them keeps the search, the
                // tab and (for Admin) the role page.
                $opsClearUrl = route('admin.rfqs.index', array_filter([
                    'status' => 'Pending',
                    'role' => $lensRole ? \Illuminate\Support\Str::slug($lensRole) : null,
                    'search' => $search !== '' ? $search : null,
                    'tab' => $opsFilters['tab'],
                ]));
                $opsNarrowed = $opsFilters['count'] > 0 || $search !== '';
                $opsOnAssigned = $opsFilters['tab'] === 'assigned';
            @endphp

            @include('admin.rfqs._ops_filters')

            <ul class="nav nav-pills rfq-view-toggle m-3 mb-0" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $opsOnAssigned ? '' : 'active' }}" data-bs-toggle="pill" data-bs-target="#rfq-ops-unassigned" data-ops-tab="unassigned" type="button" role="tab" aria-selected="{{ $opsOnAssigned ? 'false' : 'true' }}">
                        <i class="bi bi-exclamation-circle"></i> Unassigned <span class="rfq-tab-count">{{ $rfqs->total() }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link {{ $opsOnAssigned ? 'active' : '' }}" data-bs-toggle="pill" data-bs-target="#rfq-ops-assigned" data-ops-tab="assigned" type="button" role="tab" aria-selected="{{ $opsOnAssigned ? 'true' : 'false' }}">
                        <i class="bi bi-person-check"></i> Assigned <span class="rfq-tab-count">{{ $assignedRfqs->total() }}</span>
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade {{ $opsOnAssigned ? '' : 'show active' }}" id="rfq-ops-unassigned" role="tabpanel">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>WC Number</th>
                                    <th>RFQ Number</th>
                                    <th>Priority</th>
                                    <th>Subject</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($rfqs as $rfq)
                                    @include('admin.rfqs._rfq_row', ['rfq' => $rfq, 'statusFilter' => $statusFilter, 'restrictAssignment' => $restrictAssignment, 'canSeeAssignOperationsButton' => $canSeeAssignOperationsButton, 'canSeeAssignButtons' => $canSeeAssignButtons])
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted-soft py-4">
                                            @if ($opsNarrowed)
                                                No RFQs match{{ $opsFilters['count'] > 0 ? ' these filters' : ' that search' }}.
                                                @if ($opsFilters['count'] > 0)
                                                    <a href="{{ $opsClearUrl }}" class="fw-semibold">Clear filters</a>
                                                @endif
                                            @else
                                                Nothing's waiting on Sourcing assignment — you're all caught up.
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($rfqs->hasPages())
                        <div class="card-footer bg-white">
                            {{ $rfqs->links() }}
                        </div>
                    @endif
                </div>

                <div class="tab-pane fade {{ $opsOnAssigned ? 'show active' : '' }}" id="rfq-ops-assigned" role="tabpanel">
                    {{-- Each RFQ is a group of its own: its row, then a line per part
                         with who's got it and how it's going (_rfq_parts). --}}
                    @if ($assignedRfqs->isNotEmpty())
                        <div class="rfq-groups-toolbar">
                            <button type="button" class="btn btn-sm btn-outline-secondary js-toggle-all-parts">
                                <i class="bi bi-arrows-collapse"></i> <span>Collapse all</span>
                            </button>
                        </div>
                    @endif
                    <div class="table-responsive">
                        <table class="table mb-0 rfq-groups">
                            <thead>
                                <tr>
                                    <th>WC Number</th>
                                    <th>RFQ Number</th>
                                    <th>Priority</th>
                                    <th>Subject</th>
                                    <th>Created</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            @forelse ($assignedRfqs as $rfq)
                                <tbody class="rfq-group">
                                    @include('admin.rfqs._rfq_row', ['rfq' => $rfq, 'statusFilter' => $statusFilter, 'restrictAssignment' => $restrictAssignment, 'canSeeAssignOperationsButton' => $canSeeAssignOperationsButton, 'canSeeAssignButtons' => $canSeeAssignButtons, 'groupedParts' => true])
                                    @include('admin.rfqs._rfq_parts', ['rfq' => $rfq, 'columns' => 6])
                                </tbody>
                            @empty
                                <tbody>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted-soft py-4">
                                            @if ($opsNarrowed)
                                                No RFQs match{{ $opsFilters['count'] > 0 ? ' these filters' : ' that search' }}.
                                                @if ($opsFilters['count'] > 0)
                                                    <a href="{{ $opsClearUrl }}" class="fw-semibold">Clear filters</a>
                                                @endif
                                            @else
                                                Nothing's been assigned to Sourcing yet.
                                            @endif
                                        </td>
                                    </tr>
                                </tbody>
                            @endforelse
                        </table>
                    </div>

                    @if ($assignedRfqs->hasPages())
                        <div class="card-footer bg-white">
                            {{ $assignedRfqs->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>WC Number</th>
                            <th>RFQ Number</th>
                            <th>Priority</th>
                            @unless ($statusFilter)
                                <th>Status</th>
                            @endunless
                            <th>Subject</th>
                            <th>Created</th>
                            @if ($statusFilter === 'Completed')
                                <th>Closed</th>
                            @endif
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            @if ($statusFilter === 'Completed' && $rfq->isSplit() && $rfq->assignees->isNotEmpty())
                                {{-- A split shows on the Closed list part by part — each as
                                     Business Development closes it (see Rfq::closePart()). --}}
                                @include('admin.rfqs._closed_part_rows', ['rfq' => $rfq, 'bdClosedNames' => $bdClosedNames])
                            @else
                                @include('admin.rfqs._rfq_row', ['rfq' => $rfq, 'statusFilter' => $statusFilter, 'restrictAssignment' => $restrictAssignment, 'canSeeAssignOperationsButton' => $canSeeAssignOperationsButton, 'canSeeAssignButtons' => $canSeeAssignButtons, 'showClosed' => $statusFilter === 'Completed'])
                            @endif
                        @empty
                            <tr>
                                <td colspan="{{ ($statusFilter ? 6 : 7) + ($statusFilter === 'Completed' ? 1 : 0) }}" class="text-center text-muted-soft py-4">
                                    No {{ $statusFilter ? strtolower($statusFilter).' ' : '' }}RFQs found.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Data Entry's and Operations' two tabs each render their own
             paginator scoped inside their own tab-pane (above) — a shared
             one down here would show $rfqs's page links even while a
             different paginator's tab is the active one. --}}
        @if (! $scopedToDataEntry && ! $scopedToUnassigned && ! $scopedToSeniorOpsReview && $rfqs->hasPages())
            <div class="card-footer bg-white">
                {{ $rfqs->links() }}
            </div>
        @endif
    </div>

    {{-- Add RFQ modal --}}
    <div class="modal fade" id="createRfqModal" tabindex="-1" aria-labelledby="createRfqModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.rfqs.store') }}">
                    @csrf
                    @include('admin.rfqs._redirect_fields')
                    <div class="modal-header">
                        <h5 class="modal-title" id="createRfqModalLabel">Add RFQ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('admin.rfqs._form', ['mode' => 'create', 'idPrefix' => 'create', 'priorities' => $priorities, 'nextRfqNumber' => $nextRfqNumber])
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create RFQ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @include('admin.rfqs._edit_modal', ['statusFilter' => $statusFilter])
    @include('admin.rfqs._assign_modal', ['statusFilter' => $statusFilter])
    @include('admin.rfqs._assign_operations_modal', ['statusFilter' => $statusFilter])
    @if ($scopedToDataEntry || ($scopedToMe && ! $sourcingOverview))
        @include('admin.rfqs._complete_modal')
    @endif
    @if ($scopedToHeadOfBdReview)
        @include('admin.rfqs._reject_modal')
    @endif
    @if ($scopedToGmAssistant)
        @include('admin.rfqs._gm_assistant_modal')
    @endif

    @if ($errors->create->any() || $errors->edit->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalId = @json(old('rfq_id') ? 'editRfqModal' : 'createRfqModal');
                    var modalEl = document.getElementById(modalId);
                    if (modalEl) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif

    {{-- A failed reject submission — reopen the modal with its action
         pointed back at the same RFQ (the form's action is set by JS per
         row, so there's nothing server-side to fall back on otherwise). --}}
    @if ($errors->reject->any() && old('reject_rfq_id'))
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById('rejectRfqModal');
                    var form = document.getElementById('rejectRfqForm');
                    if (modalEl && form) {
                        form.action = @json(route('admin.rfqs.reject-head-of-bd', ['rfq' => old('reject_rfq_id')]));
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif

    {{-- Same idea, for a failed GM Assistant details submission. --}}
    @if ($errors->gm_assistant->any() && old('gm_assistant_rfq_id'))
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById('gmAssistantModal');
                    var form = document.getElementById('gmAssistantForm');
                    if (modalEl && form) {
                        form.action = @json(route('admin.rfqs.gm-assistant-details', ['rfq' => old('gm_assistant_rfq_id')]));
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif
@endsection
