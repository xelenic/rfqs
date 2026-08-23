@php
    $pageTitle = match (true) {
        $scopedToReturns => 'Returns',
        $statusFilter === 'Pending' && $scopedToMe => 'My Pending RFQs',
        $statusFilter === 'Pending' && $scopedToDataEntry => 'Ready for Data Entry',
        $statusFilter === 'Pending' && $scopedToUnassigned => 'Unassigned RFQs',
        $statusFilter === 'Pending' => 'Pending RFQs',
        $statusFilter === 'Completed' => 'Completed RFQs',
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
    $canSeeAssignOperationsButton = $canSeeAssignButtons && ! auth()->user()->hasRole('Operations');
@endphp

@extends('layouts.app')

@section('title', $pageTitle)

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <form method="GET" class="d-flex gap-2">
                @if ($statusFilter)
                    <input type="hidden" name="status" value="{{ $statusFilter }}">
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

        @if ($scopedToDataEntry)
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
                                     Complete button still submits normally. --}}
                                <tr class="js-de-sourcing-row" data-bs-target="#rfq-detail-modal-{{ $rfq->id }}-{{ $assignee->id }}" role="button" tabindex="0">
                                    <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                    <td>{{ $rfq->sourcingSplitNumberFor($assignee) }}</td>
                                    <td>{{ $rfq->subject }}</td>
                                    <td>{{ $assignee->name }}</td>
                                    <td class="text-muted-soft">{{ $assignee->pivot->created_at?->format('M d, Y g:i A') ?? '—' }}</td>
                                    <td class="text-muted-soft">{{ $assignee->pivot->completed_at->format('M d, Y g:i A') }}</td>
                                    <td class="text-end">
                                        {{-- Only this assignee's split — see
                                             RfqController::completeDataEntry(), which never
                                             touches any other assignee on the same RFQ. --}}
                                        <form action="{{ route('admin.rfqs.complete-data-entry', $rfq) }}" method="POST"
                                              data-confirm="Mark {{ $assignee->name }}'s part complete?">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="assignee_id" value="{{ $assignee->id }}">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Mark Complete
                                            </button>
                                        </form>
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
                            @php $myAssignment = $rfq->assignees->firstWhere('id', auth()->id()); @endphp
                            <tr>
                                <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                <td>{{ $rfq->sourcingSplitNumberFor(auth()->user()) }}</td>
                                <td>
                                    {{ $rfq->subject }}
                                    @if ($myAssignment?->pivot->return_reason)
                                        <div class="rfq-list-subnote rfq-list-subnote-returned">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                            {{ $myAssignment->pivot->return_reason }}
                                        </div>
                                    @endif
                                </td>
                                <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                <td class="text-muted-soft">{{ $myAssignment?->pivot->returned_at?->format('M d, Y g:i A') ?? '—' }}</td>
                                <td class="text-end">
                                    <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary" title="View details">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
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
                            @php
                                $myAssignment = $rfq->assignees->firstWhere('id', auth()->id());
                                $iHaveCompletedMyPart = $myAssignment?->pivot->completed_at !== null;
                            @endphp
                            {{-- Clicking the row (but not the Mark Complete button) opens
                                 the same quick-detail modal Data Entry uses (description,
                                 status, comments scoped to you) — see admin.js, which
                                 already skips nested buttons/forms. --}}
                            <tr class="js-de-sourcing-row" data-bs-target="#rfq-detail-modal-{{ $rfq->id }}-{{ $myAssignment?->id }}" role="button" tabindex="0">
                                <td class="fw-semibold">{{ $rfq->wc_number }}</td>
                                <td>{{ $rfq->sourcingSplitNumberFor(auth()->user()) }}</td>
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
                                    @elseif ($rfq->hasReturnedSourcingPart(auth()->user()))
                                        <div class="rfq-list-subnote rfq-list-subnote-returned">
                                            <i class="bi bi-arrow-counterclockwise"></i>
                                            Returned by Data Entry: {{ $myAssignment->pivot->return_reason }}
                                        </div>
                                    @endif
                                </td>
                                <td><span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }}</span></td>
                                <td class="text-muted-soft">{{ $myAssignment?->pivot->created_at?->format('M d, Y g:i A') ?? '—' }}</td>
                                <td class="text-end">
                                    @if (! $iHaveCompletedMyPart)
                                        <form action="{{ route('admin.rfqs.complete-sourcing', $rfq) }}" method="POST"
                                              data-confirm="{{ $rfq->assignees->count() > 1 ? 'Mark your part of this split RFQ complete? It only hands off to Data Entry once every assignee has completed theirs.' : 'Mark your Sourcing work done and hand this RFQ off to Data Entry?' }}">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="redirect_status" value="Pending">
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-check2-circle"></i> Mark Complete
                                            </button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
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
                @php $myAssignment = $rfq->assignees->firstWhere('id', auth()->id()); @endphp
                @if ($myAssignment)
                    @include('admin.rfqs._rfq_detail_modal', ['rfq' => $rfq, 'assignee' => $myAssignment])
                @endif
            @endforeach
        @elseif ($scopedToUnassigned)
            {{-- Operations gets a choice of two tabs on their own scoped
                 "Pending RFQs": their actionable backlog (nobody's
                 assigned to Sourcing yet), and a reference view of what's
                 already routed but still Pending overall. --}}
            <ul class="nav nav-pills rfq-view-toggle m-3 mb-0" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" data-bs-toggle="pill" data-bs-target="#rfq-ops-unassigned" type="button" role="tab" aria-selected="true">
                        <i class="bi bi-exclamation-circle"></i> Unassigned
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-bs-toggle="pill" data-bs-target="#rfq-ops-assigned" type="button" role="tab" aria-selected="false">
                        <i class="bi bi-person-check"></i> Assigned
                    </button>
                </li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="rfq-ops-unassigned" role="tabpanel">
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
                                            Nothing's waiting on Sourcing assignment — you're all caught up.
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

                <div class="tab-pane fade" id="rfq-ops-assigned" role="tabpanel">
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
                                @forelse ($assignedRfqs as $rfq)
                                    @include('admin.rfqs._rfq_row', ['rfq' => $rfq, 'statusFilter' => $statusFilter, 'restrictAssignment' => $restrictAssignment, 'canSeeAssignOperationsButton' => $canSeeAssignOperationsButton, 'canSeeAssignButtons' => $canSeeAssignButtons])
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted-soft py-4">
                                            Nothing's been assigned to Sourcing yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
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
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rfqs as $rfq)
                            @include('admin.rfqs._rfq_row', ['rfq' => $rfq, 'statusFilter' => $statusFilter, 'restrictAssignment' => $restrictAssignment, 'canSeeAssignOperationsButton' => $canSeeAssignOperationsButton, 'canSeeAssignButtons' => $canSeeAssignButtons])
                        @empty
                            <tr>
                                <td colspan="{{ $statusFilter ? 6 : 7 }}" class="text-center text-muted-soft py-4">
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
        @if (! $scopedToDataEntry && ! $scopedToUnassigned && $rfqs->hasPages())
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
                    @if ($statusFilter)
                        <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                    @endif
                    <div class="modal-header">
                        <h5 class="modal-title" id="createRfqModalLabel">Add RFQ</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @include('admin.rfqs._form', ['mode' => 'create', 'idPrefix' => 'create', 'priorities' => $priorities, 'statuses' => $statuses, 'defaultStatus' => $statusFilter ?? 'Pending', 'nextRfqNumber' => $nextRfqNumber])
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

    {{-- A failed comment or return-to-sourcing submitted from a
         quick-detail modal (Data Entry's "By Sourcing" list or Sourcing's
         own "My Pending RFQs") — reopen that specific assignee's modal so
         the error/old input aren't silently lost on the page reload.
         Which of the two boxes shows the error is decided inside the
         modal itself (see _rfq_detail_modal.blade.php). --}}
    @if (($errors->comment->any() || $errors->return->any()) && old('modal_rfq_id') && old('modal_assignee_id'))
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById(@json('rfq-detail-modal-'.old('modal_rfq_id').'-'.old('modal_assignee_id')));
                    if (modalEl) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif
@endsection
