@extends('layouts.app')

@php
    // Business Development can see that Sourcing/Operations assignment and
    // the comment thread exist on an RFQ, but they're blurred and inert —
    // a deliberate role-specific UI choice, not a permission gap.
    $restrictAssignment = auth()->user()->hasRole('Business Development');

    // Sourcing can work their assigned task without seeing who created it
    // (Business Development), who routed it (Operations), or which other
    // Sourcing partners are also on it — they still see their own entry.
    $restrictSourcingView = auth()->user()->hasRole('Sourcing');

    // Only a Sourcing member actually assigned to this RFQ can mark their
    // own split of the work complete, and only once. With more than one
    // assignee, everyone has to complete their own part — the RFQ only
    // hands off to Data Entry once all of them have. See
    // Rfq::completeSourcingPartFor().
    $myAssignment = $rfq->assignees->firstWhere('id', auth()->id());
    $canCompleteSourcing = $restrictSourcingView
        && $myAssignment !== null
        && $myAssignment->pivot->completed_at === null;

    // Data Entry sent this Sourcing viewer's own split back for rework —
    // flagged here so a banner with the reason can show up front, rather
    // than the reappearing Mark Complete button being the only clue.
    $myPartWasReturned = $restrictSourcingView && $rfq->hasReturnedSourcingPart(auth()->user());

    // Sourcing never gets access to the assign controls at all — it's a
    // receiving role, not an assigning one — enforced server-side too, see
    // RfqController::assign()/assignOperations().
    $canSeeAssignButtons = ! $restrictSourcingView;

    // Operations doesn't need a separate "Assign Operations" picker — when
    // they assign Sourcing, they're implicitly recorded as the one routing
    // it (see RfqController::assign()), so just the one button is shown.
    $canSeeAssignOperationsButton = $canSeeAssignButtons && ! auth()->user()->hasRole('Senior Operations');

    // Senior Operations' second review — only shown while the RFQ is
    // actually sitting in that stage, same as it only appears on the
    // Review queue while it's there. See Rfq::completeSeniorOpsReview().
    $canApproveSeniorOpsReview = auth()->user()->hasAnyRole(['Senior Operations', 'Admin']) && $rfq->stage === 'senior_ops_review';

    // Head of Business Development's own review — same "only while it's
    // actually theirs to decide" rule. See Rfq::approveByHeadOfBd() /
    // rejectToStage().
    $canDecideHeadOfBdReview = auth()->user()->hasAnyRole(['Head of Business Development', 'Admin']) && $rfq->stage === 'head_of_bd_review';

    // GM Assistant's own turn — same rule again. See
    // Rfq::recordGmAssistantDetails().
    $canSubmitGmAssistantDetails = auth()->user()->hasAnyRole(['GM Assistant', 'Admin']) && $rfq->stage === 'gm_assistant';

    // General Manager's final approval — same rule again. See
    // Rfq::approveByGm().
    $canApproveGm = auth()->user()->hasAnyRole(['General Manager', 'Admin']) && $rfq->stage === 'gm_review';

    // Business Development's closing action — the true end of the
    // lifecycle. See Rfq::closeOut().
    $canCloseRfq = auth()->user()->hasAnyRole(['Business Development', 'Admin']) && $rfq->stage === 'bd_closing';

    // A Sourcing assignee sees their own split RFQ number (e.g.
    // "RFQ1001-P2 of P3") once more than one person is sharing the work;
    // everyone else sees the master number. See Rfq::sourcingSplitNumberFor().
    $displayRfqNumber = ($restrictSourcingView && $rfq->assignees->contains('id', auth()->id()))
        ? $rfq->sourcingSplitNumberFor(auth()->user())
        : $rfq->rfq_number;

    // Drives the vertical Progress stepper below — one boolean per stage,
    // in the same top-to-bottom order they're drawn in, so the first not
    // yet done is "where things stand right now". A closed RFQ has every
    // stage done, so $currentStep comes back null — nothing to highlight,
    // it's finished.
    $stepDone = [
        'created' => true,
        'operations' => $rfq->operationsAssignee !== null,
        'sourcing' => $rfq->assignees->isNotEmpty() && $rfq->allSourcingPartsCompleted(),
        'data_entry' => $rfq->data_entry_completed_at !== null,
        'senior_ops' => $rfq->senior_ops_reviewed_at !== null,
        'head_of_bd' => $rfq->head_of_bd_approved_at !== null,
        'gm_assistant' => $rfq->gm_assistant_completed_at !== null,
        'gm_review' => $rfq->gm_approved_at !== null,
        'closed' => $rfq->bd_closed_at !== null,
    ];
    $currentStep = collect($stepDone)->search(false, true);
@endphp

@section('title', $displayRfqNumber)

@section('content')
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
        <a href="{{ route('admin.rfqs.index', $statusFilter ? ['status' => $statusFilter] : []) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to RFQs
        </a>

        <div class="d-flex gap-2">
            @if ($canCompleteSourcing)
                <form action="{{ route('admin.rfqs.complete-sourcing', $rfq) }}" method="POST"
                      data-confirm="{{ $rfq->assignees->count() > 1 ? 'Mark your part of this split RFQ complete? It only hands off to Data Entry once every assignee has completed theirs.' : 'Mark your Sourcing work done and hand this RFQ off to Data Entry?' }}">
                    @csrf
                    @method('PATCH')
                    @if ($statusFilter)
                        <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                    @endif
                    <input type="hidden" name="return_to" value="show">
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check2-circle"></i> Mark Complete
                    </button>
                </form>
            @endif
            @if ($canApproveSeniorOpsReview)
                <form action="{{ route('admin.rfqs.complete-senior-ops-review', $rfq) }}" method="POST"
                      data-confirm="Approve this RFQ? It moves on to Head of Business Development.">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check2-circle"></i> Approve
                    </button>
                </form>
            @endif
            @if ($canDecideHeadOfBdReview)
                <form action="{{ route('admin.rfqs.approve-head-of-bd', $rfq) }}" method="POST"
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
            @endif
            @if ($canSubmitGmAssistantDetails)
                <button type="button" class="btn btn-sm btn-primary js-gm-assistant-rfq"
                        data-bs-toggle="modal" data-bs-target="#gmAssistantModal"
                        data-action="{{ route('admin.rfqs.gm-assistant-details', $rfq) }}"
                        data-rfq-id="{{ $rfq->id }}">
                    <i class="bi bi-pencil-square"></i> Add Details
                </button>
            @endif
            @if ($canApproveGm)
                <form action="{{ route('admin.rfqs.approve-gm', $rfq) }}" method="POST"
                      data-confirm="Approve this RFQ? It moves on to Business Development to close.">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check2-circle"></i> Approve
                    </button>
                </form>
            @endif
            @if ($canCloseRfq)
                <form action="{{ route('admin.rfqs.close', $rfq) }}" method="POST"
                      data-confirm="Close this RFQ? It moves out of Pending into Closed RFQs.">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-flag"></i> Close
                    </button>
                </form>
            @endif
            @can('rfqs.edit')
                @if ($rfq->assignees->isEmpty())
                    @if ($canSeeAssignOperationsButton)
                        <button type="button" class="btn btn-sm btn-outline-secondary js-assign-operations-rfq {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                                {{ $restrictAssignment ? 'disabled' : '' }}
                                data-bs-toggle="modal" data-bs-target="#assignOperationsModal"
                                data-action="{{ route('admin.rfqs.assign-operations', $rfq) }}"
                                data-operations-user-id="{{ $rfq->operations_assigned_by }}"
                                title="{{ $restrictAssignment ? 'Restricted for your role' : '' }}">
                            <i class="bi bi-diagram-2"></i> Assign Operations
                        </button>
                    @endif
                    @if ($canSeeAssignButtons)
                        <button type="button" class="btn btn-sm btn-outline-secondary js-assign-rfq {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                                {{ $restrictAssignment ? 'disabled' : '' }}
                                data-bs-toggle="modal" data-bs-target="#assignRfqModal"
                                data-action="{{ route('admin.rfqs.assign', $rfq) }}"
                                data-assigned="{{ $rfq->assignees->pluck('id')->implode(',') }}"
                                title="{{ $restrictAssignment ? 'Restricted for your role' : '' }}">
                            <i class="bi bi-person-plus"></i> Assign Sourcing
                        </button>
                    @endif
                @endif
            @endcan
            @if (auth()->user()->hasRole('Admin'))
                <button type="button" class="btn btn-sm btn-primary js-edit-rfq"
                        data-bs-toggle="modal" data-bs-target="#editRfqModal"
                        data-action="{{ route('admin.rfqs.update', $rfq) }}"
                        data-id="{{ $rfq->id }}"
                        data-wc-number="{{ $rfq->wc_number }}"
                        data-rfq-number="{{ $rfq->rfq_number }}"
                        data-priority-level="{{ $rfq->priority_level }}"
                        data-status="{{ $rfq->status }}"
                        data-subject="{{ $rfq->subject }}"
                        data-description="{{ $rfq->description }}">
                    <i class="bi bi-pencil"></i> Edit
                </button>
            @endif
            @can('rfqs.delete')
                <form action="{{ route('admin.rfqs.destroy', $rfq) }}" method="POST" data-confirm="Delete this RFQ?">
                    @csrf
                    @method('DELETE')
                    @if ($statusFilter)
                        <input type="hidden" name="redirect_status" value="{{ $statusFilter }}">
                    @endif
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                </form>
            @endcan
        </div>
    </div>

    @if ($myPartWasReturned)
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-arrow-counterclockwise fs-5"></i>
            <div>
                <div class="fw-bold">Data Entry sent your part back for rework</div>
                <div>{{ $myAssignment->pivot->return_reason }}</div>
            </div>
        </div>
    @endif

    <div class="card mb-3">
        <div class="card-header">Progress</div>
        <div class="card-body">
            <div class="rfq-tree-scroll">
                {{-- RFQ Created → Assigned by Operations → Assigned to
                     Sourcing, which forks into one branch per assignee —
                     each completing their own split independently, then
                     forking again into their own Data Entry status. Real
                     connector lines rather than a flat list, since this is
                     genuinely a fork, not a sequence — one branch finishing
                     doesn't mean the others have. See
                     Rfq::completeSourcingPartFor()/completeDataEntryPartFor(). --}}
                <ul class="rfq-tree">
                    <li>
                        <div class="rfq-tree-node is-done rfq-tree-root">
                            <div class="rfq-tree-node-title">RFQ Created</div>
                            <div class="rfq-tree-node-meta">
                                {{ $rfq->created_at->format('M d, Y g:i A') }}
                                &middot; <span class="{{ $restrictSourcingView ? 'rfq-blurred' : '' }}">by {{ $rfq->creator?->name ?? 'Unknown' }}</span>
                            </div>
                        </div>
                        <ul>
                            <li>
                                <div class="rfq-tree-node {{ $stepDone['operations'] ? 'is-done' : ($currentStep === 'operations' ? 'is-current' : 'is-pending') }}">
                                    <div class="rfq-tree-node-title">Assigned by Operations</div>
                                    @if ($rfq->operationsAssignee)
                                        <div class="rfq-tree-node-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                            {{ $rfq->operationsAssignee->name }} &middot; {{ $rfq->operations_assigned_at->format('M d, Y g:i A') }}
                                        </div>
                                    @else
                                        <div class="rfq-tree-node-meta">Not yet assigned</div>
                                    @endif
                                </div>
                                <ul>
                                    <li>
                                        <div class="rfq-tree-node {{ $stepDone['sourcing'] ? 'is-done' : ($currentStep === 'sourcing' ? 'is-current' : 'is-pending') }}">
                                            <div class="rfq-tree-node-title">Assigned to Sourcing</div>
                                            @if ($rfq->assignees->isEmpty())
                                                <div class="rfq-tree-node-meta">Not yet assigned</div>
                                            @endif
                                        </div>
                                        @if ($rfq->assignees->isNotEmpty())
                                            <ul>
                                                @foreach ($rfq->assignees->sortBy('pivot.created_at') as $assignee)
                                                    @php
                                                        $isOtherSourcingPartner = $restrictSourcingView && $assignee->id !== auth()->id();
                                                        $branchBlurred = $restrictAssignment || $isOtherSourcingPartner;
                                                        $sourcingDone = $assignee->pivot->completed_at !== null;
                                                        $deIsDone = $assignee->pivot->data_entry_completed_at !== null;
                                                        $deIsReturned = ! $deIsDone && $assignee->pivot->returned_at !== null;
                                                        // Sourcing never learns exactly who in Data
                                                        // Entry touched a split — same substitution
                                                        // used in the activity timeline and the
                                                        // return-reason banner.
                                                        $deActorName = $restrictSourcingView ? 'Data Entry' : ($assignee->pivot->dataEntryCompletedBy?->name ?? 'Unknown');
                                                    @endphp
                                                    <li>
                                                        <div class="rfq-tree-node {{ $sourcingDone ? 'is-done' : 'is-pending' }} {{ $branchBlurred ? 'rfq-blurred' : '' }}">
                                                            <div class="rfq-tree-node-title">
                                                                {{ $assignee->name }}
                                                                <span class="rfq-tree-node-tag">{{ $rfq->sourcingSplitNumberFor($assignee) }}</span>
                                                            </div>
                                                            @if ($sourcingDone)
                                                                <div class="rfq-tree-node-meta">Completed {{ $assignee->pivot->completed_at->format('M d, Y g:i A') }}</div>
                                                            @else
                                                                <div class="rfq-tree-node-meta">Pending since {{ $assignee->pivot->created_at->format('M d, Y g:i A') }}</div>
                                                            @endif
                                                        </div>
                                                        <ul>
                                                            <li>
                                                                <div class="rfq-tree-node {{ $deIsDone ? 'is-done' : ($deIsReturned ? 'is-returned' : 'is-pending') }} {{ $branchBlurred ? 'rfq-blurred' : '' }}">
                                                                    <div class="rfq-tree-node-title">
                                                                        {{ $assignee->name }}
                                                                        <span class="rfq-tree-node-tag">{{ $rfq->sourcingSplitNumberFor($assignee) }}</span>
                                                                    </div>
                                                                    @if ($deIsDone)
                                                                        <div class="rfq-tree-node-meta">{{ $deActorName }} &middot; {{ $assignee->pivot->data_entry_completed_at->format('M d, Y g:i A') }}</div>
                                                                    @elseif ($deIsReturned)
                                                                        <div class="rfq-tree-node-meta">Returned — rework needed</div>
                                                                    @elseif ($sourcingDone)
                                                                        <div class="rfq-tree-node-meta">Awaiting review</div>
                                                                    @else
                                                                        <div class="rfq-tree-node-meta">Awaiting Sourcing</div>
                                                                    @endif
                                                                </div>
                                                            </li>
                                                        </ul>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>

            {{-- Every split's done — the branches above converge back into
                 one whole-RFQ pipeline: Senior Operations' second review,
                 through Business Development's close. --}}
            <div class="rfq-tree-bridge"></div>

            <div class="rfq-tree-scroll">
                <ul class="rfq-tree rfq-tree-tail">
                    <li>
                        <div class="rfq-tree-node {{ $stepDone['senior_ops'] ? 'is-done' : ($currentStep === 'senior_ops' ? 'is-current' : 'is-pending') }}">
                            <div class="rfq-tree-node-title">Senior Operations Approval</div>
                            @if ($rfq->senior_ops_reviewed_at)
                                <div class="rfq-tree-node-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                    {{ $rfq->seniorOpsReviewedBy?->name ?? 'Unknown' }} &middot; {{ $rfq->senior_ops_reviewed_at->format('M d, Y g:i A') }}
                                </div>
                            @else
                                <div class="rfq-tree-node-meta">{{ $stepDone['data_entry'] ? 'Awaiting review' : 'Not yet reached' }}</div>
                            @endif
                        </div>
                        <ul>
                            <li>
                                <div class="rfq-tree-node {{ $stepDone['head_of_bd'] ? 'is-done' : ($currentStep === 'head_of_bd' ? 'is-current' : 'is-pending') }} {{ $rfq->head_of_bd_rejected_at && ! $stepDone['head_of_bd'] ? 'is-returned' : '' }}">
                                    <div class="rfq-tree-node-title">Head of Business Development</div>
                                    @if ($rfq->head_of_bd_approved_at)
                                        <div class="rfq-tree-node-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                            Approved by {{ $rfq->headOfBdApprovedBy?->name ?? 'Unknown' }} &middot; {{ $rfq->head_of_bd_approved_at->format('M d, Y g:i A') }}
                                        </div>
                                    @elseif ($rfq->head_of_bd_rejected_at)
                                        <div class="rfq-tree-node-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                            Rejected by {{ $rfq->headOfBdRejectedBy?->name ?? 'Unknown' }} &middot; {{ $rfq->head_of_bd_rejected_at->format('M d, Y g:i A') }}
                                        </div>
                                        <div class="rfq-tree-node-meta text-danger">Returned to {{ \App\Models\Rfq::stageLabel($rfq->head_of_bd_reject_target_stage) }}</div>
                                    @else
                                        <div class="rfq-tree-node-meta">{{ $stepDone['senior_ops'] ? 'Awaiting review' : 'Not yet reached' }}</div>
                                    @endif
                                </div>
                                <ul>
                                    <li>
                                        <div class="rfq-tree-node {{ $stepDone['gm_assistant'] ? 'is-done' : ($currentStep === 'gm_assistant' ? 'is-current' : 'is-pending') }}">
                                            <div class="rfq-tree-node-title">GM Assistant</div>
                                            @if ($rfq->gm_assistant_completed_at)
                                                <div class="rfq-tree-node-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                                    {{ $rfq->gmAssistantCompletedBy?->name ?? 'Unknown' }} &middot; {{ $rfq->gm_assistant_completed_at->format('M d, Y g:i A') }}
                                                </div>
                                            @else
                                                <div class="rfq-tree-node-meta">{{ $stepDone['head_of_bd'] ? 'Awaiting details' : 'Not yet reached' }}</div>
                                            @endif
                                        </div>
                                        <ul>
                                            <li>
                                                <div class="rfq-tree-node {{ $stepDone['gm_review'] ? 'is-done' : ($currentStep === 'gm_review' ? 'is-current' : 'is-pending') }}">
                                                    <div class="rfq-tree-node-title">General Manager</div>
                                                    @if ($rfq->gm_approved_at)
                                                        <div class="rfq-tree-node-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                                            Approved by {{ $rfq->gmApprovedBy?->name ?? 'Unknown' }} &middot; {{ $rfq->gm_approved_at->format('M d, Y g:i A') }}
                                                        </div>
                                                    @else
                                                        <div class="rfq-tree-node-meta">{{ $stepDone['gm_assistant'] ? 'Awaiting approval' : 'Not yet reached' }}</div>
                                                    @endif
                                                </div>
                                                <ul>
                                                    <li>
                                                        <div class="rfq-tree-node {{ $stepDone['closed'] ? 'is-done' : ($currentStep === 'closed' ? 'is-current' : 'is-pending') }}">
                                                            <div class="rfq-tree-node-title">Closed</div>
                                                            @if ($rfq->bd_closed_at)
                                                                <div class="rfq-tree-node-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                                                    {{ $rfq->bdClosedBy?->name ?? 'Unknown' }} &middot; {{ $rfq->bd_closed_at->format('M d, Y g:i A') }}
                                                                </div>
                                                            @else
                                                                <div class="rfq-tree-node-meta">{{ $stepDone['gm_review'] ? 'Ready for Business Development' : 'Not yet reached' }}</div>
                                                            @endif
                                                        </div>
                                                    </li>
                                                </ul>
                                            </li>
                                        </ul>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>{{ $rfq->subject }}</span>
                    <div class="d-flex gap-2">
                        <span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->statusLabel() }}</span>
                        <span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priority_level }} priority</span>
                    </div>
                </div>
                <div class="card-body">
                    <dl class="rfq-detail-grid mb-0">
                        <div>
                            <dt>WC Number</dt>
                            <dd>{{ $rfq->wc_number }}</dd>
                        </div>
                        <div>
                            <dt>RFQ Number</dt>
                            <dd>{{ $displayRfqNumber }}</dd>
                        </div>
                        <div>
                            <dt>Created by</dt>
                            <dd class="{{ $restrictSourcingView ? 'rfq-blurred' : '' }}">{{ $rfq->creator?->name ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt>Created</dt>
                            <dd>{{ $rfq->created_at->format('M d, Y \a\t g:i A') }}</dd>
                        </div>
                        <div>
                            <dt>Last updated</dt>
                            <dd>{{ $rfq->updated_at->format('M d, Y \a\t g:i A') }}</dd>
                        </div>
                    </dl>

                    <hr class="my-3">

                    <h2 class="h6 fw-bold mb-2">Description</h2>
                    @if ($rfq->description)
                        <p class="mb-0" style="white-space: pre-line;">{{ $rfq->description }}</p>
                    @else
                        <p class="text-muted-soft mb-0">No description provided.</p>
                    @endif

                    @if ($rfq->client_details)
                        <hr class="my-3">

                        <h2 class="h6 fw-bold mb-2">Client Details</h2>
                        <p class="mb-3" style="white-space: pre-line;">{{ $rfq->client_details }}</p>

                        @if ($rfq->payment_terms)
                            <h2 class="h6 fw-bold mb-2">Payment Terms</h2>
                            <p class="mb-0" style="white-space: pre-line;">{{ $rfq->payment_terms }}</p>
                        @endif
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Assigned Sourcing</div>

                <div class="{{ $restrictAssignment ? 'rfq-locked-panel' : '' }}">
                    <div class="card-body {{ $restrictAssignment ? 'rfq-blurred' : '' }}">
                        @forelse ($rfq->assignees as $assignee)
                            <div class="d-flex align-items-center gap-2 mb-2 {{ ($restrictSourcingView && $assignee->id !== auth()->id()) ? 'rfq-blurred' : '' }}">
                                <span class="assignee-avatar">{{ strtoupper(substr($assignee->name, 0, 1)) }}</span>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold small">{{ $assignee->name }}</div>
                                    <div class="text-muted-soft assignee-email">{{ $assignee->email }}</div>
                                    @if ($rfq->assignees->count() > 1)
                                        <div class="text-muted-soft small fw-semibold">{{ $rfq->sourcingSplitNumberFor($assignee) }}</div>
                                    @endif
                                </div>
                                @if ($assignee->pivot->completed_at)
                                    <span class="badge badge-soft-success" title="Completed {{ $assignee->pivot->completed_at->diffForHumans() }}">
                                        <i class="bi bi-check-circle-fill"></i> Done
                                    </span>
                                @elseif ($assignee->pivot->returned_at)
                                    <span class="badge badge-soft-danger" title="{{ $assignee->pivot->return_reason }}">
                                        <i class="bi bi-arrow-counterclockwise"></i> Returned
                                    </span>
                                @else
                                    <span class="badge badge-soft-secondary">Pending</span>
                                @endif
                            </div>
                        @empty
                            <p class="text-muted-soft mb-0">Not assigned yet.</p>
                        @endforelse
                    </div>

                    @if ($restrictAssignment)
                        <div class="rfq-locked-overlay">
                            <i class="bi bi-lock-fill"></i>
                            <span>Restricted for your role</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @php
        // A failed reply flashes its parent comment's id — used to decide
        // which single reply box (among possibly many) should re-show the
        // old input and error, and stay expanded after the redirect.
        $failedReplyParentId = old('parent_id');
        $commentCount = $rfq->comments->sum(fn ($comment) => 1 + $comment->replies->count());

        // Data Entry/Admin can send an assignee's completed split back to
        // Sourcing right from here — same action as the quick-detail
        // modal's Return to Sourcing, just reachable from the full RFQ
        // page too, and presented as a plain text box alongside the
        // comment box rather than hidden behind a toggle. One box per
        // assignee still eligible for it (their Sourcing part is done, but
        // Data Entry hasn't processed it yet) — a split RFQ can have more
        // than one at once. See RfqController::returnSourcing().
        $canReturnSourcing = auth()->user()->hasAnyRole(['Data Entry', 'Admin']);
        $returnEligibleAssignees = $canReturnSourcing
            ? $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->completed_at !== null && ! $rfq->hasDataEntryCompletedPart($assignee))
            : collect();
        $failedReturnAssigneeId = old('assignee_id');
    @endphp

    {{-- Sourcing team roster for the @mention dropdown in comments — read
         by admin.js, kept as inert JSON so it never executes as markup. --}}
    <script type="application/json" id="rfq-mention-users">{!! $sourcingUsers->map(fn ($user) => ['id' => $user->id, 'name' => $user->name])->values()->toJson() !!}</script>

    <div class="card mb-3">
        <div class="card-header">Activity Timeline ({{ $commentCount }} comments)</div>

        <div class="{{ $restrictAssignment ? 'rfq-locked-panel' : '' }}">
            <div class="card-body {{ $restrictAssignment ? 'rfq-blurred' : '' }}">
                <div class="rfq-timeline">
                    @foreach ($rfq->activityTimeline() as $entry)
                        @include('admin.rfqs._timeline_entry', [
                            'entry' => $entry,
                            'rfq' => $rfq,
                            'restrictSourcingView' => $restrictSourcingView,
                            'restrictAssignment' => $restrictAssignment,
                            'sourcingUsers' => $sourcingUsers,
                            'failedReplyParentId' => $failedReplyParentId,
                        ])
                    @endforeach
                </div>
            </div>

            @unless ($restrictAssignment)
                <div class="card-footer bg-white">
                    @foreach ($returnEligibleAssignees as $returnAssignee)
                        @php
                            $isFailedReturn = $failedReturnAssigneeId && (string) $failedReturnAssigneeId === (string) $returnAssignee->id;
                        @endphp
                        <div class="mb-3 pb-3 border-bottom">
                            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                                <label class="fw-semibold small mb-0">
                                    <i class="bi bi-arrow-counterclockwise"></i> Return {{ $returnAssignee->name }}'s part to Sourcing
                                </label>
                                <form action="{{ route('admin.rfqs.complete-data-entry', $rfq) }}" method="POST"
                                      data-confirm="Mark {{ $returnAssignee->name }}'s part complete?">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="assignee_id" value="{{ $returnAssignee->id }}">
                                    <button type="submit" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-check2-circle"></i> Mark Complete
                                    </button>
                                </form>
                            </div>
                            <form action="{{ route('admin.rfqs.return-sourcing', $rfq) }}" method="POST"
                                  data-confirm="Send {{ $returnAssignee->name }}'s part back to Sourcing for rework?">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="assignee_id" value="{{ $returnAssignee->id }}">
                                <div class="mb-2">
                                    <textarea name="reason" rows="2" class="form-control @error('reason', 'return') is-invalid @enderror">{{ $isFailedReturn ? old('reason') : '' }}</textarea>
                                    @if ($isFailedReturn)
                                        @error('reason', 'return')
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    @endif
                                </div>
                                <button type="submit" class="btn btn-sm btn-danger">
                                    Send back to {{ $returnAssignee->name }}
                                </button>
                            </form>
                        </div>
                    @endforeach

                    <form action="{{ route('admin.rfqs.comments.store', $rfq) }}" method="POST">
                        @csrf
                        <div class="mb-2">
                            <textarea name="body" rows="2" class="form-control js-mention-input @error('body', 'comment') is-invalid @enderror" placeholder="Write a comment... (@ to mention Sourcing)">{{ ! $failedReplyParentId ? old('body') : '' }}</textarea>
                            @error('body', 'comment')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Post comment</button>
                    </form>
                </div>
            @endunless

            @if ($restrictAssignment)
                <div class="rfq-locked-overlay">
                    <i class="bi bi-lock-fill"></i>
                    <span>Restricted for your role</span>
                </div>
            @endif
        </div>
    </div>

    @include('admin.rfqs._edit_modal', ['statusFilter' => $statusFilter, 'returnTo' => 'show'])
    @include('admin.rfqs._assign_modal', ['statusFilter' => $statusFilter, 'returnTo' => 'show'])
    @include('admin.rfqs._assign_operations_modal', ['statusFilter' => $statusFilter, 'returnTo' => 'show'])
    @if ($canDecideHeadOfBdReview)
        @include('admin.rfqs._reject_modal')
    @endif
    @if ($canSubmitGmAssistantDetails)
        @include('admin.rfqs._gm_assistant_modal')
    @endif

    @if ($errors->edit->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById('editRfqModal');
                    if (modalEl) {
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif

    @if ($canDecideHeadOfBdReview && $errors->reject->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById('rejectRfqModal');
                    var form = document.getElementById('rejectRfqForm');
                    if (modalEl && form) {
                        form.action = @json(route('admin.rfqs.reject-head-of-bd', $rfq));
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif

    @if ($canSubmitGmAssistantDetails && $errors->gm_assistant->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById('gmAssistantModal');
                    var form = document.getElementById('gmAssistantForm');
                    if (modalEl && form) {
                        form.action = @json(route('admin.rfqs.gm-assistant-details', $rfq));
                        bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                });
            </script>
        @endpush
    @endif
@endsection
