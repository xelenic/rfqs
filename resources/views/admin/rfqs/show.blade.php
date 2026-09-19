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

    // Which tab opens by default in the Step Details panel below the
    // Progress chart — whichever stage is currently in play, or Closed
    // once every stage is done and there's nothing left "current".
    $activeStep = $currentStep ?? 'closed';

    $stepTabs = [
        'created' => ['label' => 'Created', 'icon' => 'bi-flag'],
        'operations' => ['label' => 'Operations', 'icon' => 'bi-diagram-2'],
        'sourcing' => ['label' => 'Sourcing', 'icon' => 'bi-people'],
        'data_entry' => ['label' => 'Data Entry', 'icon' => 'bi-keyboard'],
        'senior_ops' => ['label' => 'Senior Ops', 'icon' => 'bi-clipboard2-check'],
        'head_of_bd' => ['label' => 'Head of BD', 'icon' => 'bi-person-check'],
        'gm_assistant' => ['label' => 'GM Assistant', 'icon' => 'bi-file-earmark-text'],
        'gm_review' => ['label' => 'General Manager', 'icon' => 'bi-award'],
        'closed' => ['label' => 'Closed', 'icon' => 'bi-check2-all'],
    ];

    // Progress is rendered by Apache ECharts (a single tree series) —
    // real connector-line geometry instead of fragile pseudo-element math.
    // Once Sourcing splits, every branch keeps its own copy of the rest of
    // the chain (Senior Operations Approval onward) all the way to Closed,
    // rather than merging back into one shared node — each split reads as
    // its own complete path top to bottom. Those later stages are still
    // recorded once on the RFQ as a whole (one review, not one per split),
    // so every branch's copy of them shows the same underlying approval —
    // this is purely how the chart lays the same state out per branch. See
    // public/js/admin.js (renderRfqProgressChart) and the
    // #rfq-progress-data script below.
    //
    // Blurred content (Sourcing not seeing another partner's identity, or
    // who created/routed/approved outside their own sphere) is masked
    // server-side into a plain label here rather than styled — a canvas
    // chart can't selectively CSS-blur one rendered text run the way the
    // rest of the page does.
    $maskIfBlurred = fn (?string $label, bool $blurred) => $blurred ? 'Restricted' : ($label ?? 'Unknown');

    $nodeState = fn (bool $done, bool $current = false, bool $returned = false) => match (true) {
        $returned => 'returned',
        $done => 'done',
        $current => 'current',
        default => 'pending',
    };

    // Senior Operations Approval → Closed, duplicated per branch (see
    // comment above). $rfqNumber identifies which split a given copy
    // belongs to once there's more than one — null for a single, unsplit
    // RFQ or a branch that's currently masked from this viewer, and
    // rendered on its own line rather than folded into the title. Each
    // node's `meta` is a list of 0-2 further short lines — who (role/
    // actor) on its own line, when (date and time) on its own, rather
    // than "Name · Date" packed together. `step` tags which Step Details
    // tab (below the chart) this node belongs to, so selecting a tab can
    // highlight every node — across every branch — for that stage. See
    // public/js/admin.js (renderRfqProgressChart), which renders name,
    // rfq_number, and every meta entry each as their own line, and reads
    // `step` to drive the tab-select highlight.
    $buildTailChain = function (?string $rfqNumber) use ($rfq, $maskIfBlurred, $restrictSourcingView, $nodeState, $stepDone, $currentStep) {
        $closed = [
            'name' => 'Closed',
            'step' => 'closed',
            'rfq_number' => $rfqNumber,
            'meta' => $rfq->bd_closed_at
                ? [$maskIfBlurred($rfq->bdClosedBy?->name, $restrictSourcingView), $rfq->bd_closed_at->format('M d, Y g:i A')]
                : [$stepDone['gm_review'] ? 'Ready for Business Development' : 'Not yet reached'],
            'state' => $nodeState($stepDone['closed'], $currentStep === 'closed'),
            'children' => [],
        ];

        $gmReview = [
            'name' => 'General Manager',
            'step' => 'gm_review',
            'rfq_number' => $rfqNumber,
            'meta' => $rfq->gm_approved_at
                ? ['Approved by '.$maskIfBlurred($rfq->gmApprovedBy?->name, $restrictSourcingView), $rfq->gm_approved_at->format('M d, Y g:i A')]
                : [$stepDone['gm_assistant'] ? 'Awaiting approval' : 'Not yet reached'],
            'state' => $nodeState($stepDone['gm_review'], $currentStep === 'gm_review'),
            'children' => [$closed],
        ];

        $gmAssistant = [
            'name' => 'GM Assistant',
            'step' => 'gm_assistant',
            'rfq_number' => $rfqNumber,
            'meta' => $rfq->gm_assistant_completed_at
                ? [$maskIfBlurred($rfq->gmAssistantCompletedBy?->name, $restrictSourcingView), $rfq->gm_assistant_completed_at->format('M d, Y g:i A')]
                : [$stepDone['head_of_bd'] ? 'Awaiting details' : 'Not yet reached'],
            'state' => $nodeState($stepDone['gm_assistant'], $currentStep === 'gm_assistant'),
            'children' => [$gmReview],
        ];

        $headOfBd = [
            'name' => 'Head of Business Development',
            'step' => 'head_of_bd',
            'rfq_number' => $rfqNumber,
            'meta' => match (true) {
                (bool) $rfq->head_of_bd_approved_at => ['Approved by '.$maskIfBlurred($rfq->headOfBdApprovedBy?->name, $restrictSourcingView), $rfq->head_of_bd_approved_at->format('M d, Y g:i A')],
                (bool) $rfq->head_of_bd_rejected_at => ['Rejected by '.$maskIfBlurred($rfq->headOfBdRejectedBy?->name, $restrictSourcingView), 'Returned to '.\App\Models\Rfq::stageLabel($rfq->head_of_bd_reject_target_stage)],
                default => [$stepDone['senior_ops'] ? 'Awaiting review' : 'Not yet reached'],
            },
            'state' => $nodeState($stepDone['head_of_bd'], $currentStep === 'head_of_bd', (bool) $rfq->head_of_bd_rejected_at && ! $stepDone['head_of_bd']),
            'children' => [$gmAssistant],
        ];

        return [
            'name' => 'Senior Operations Approval',
            'step' => 'senior_ops',
            'rfq_number' => $rfqNumber,
            'meta' => $rfq->senior_ops_reviewed_at
                ? [$maskIfBlurred($rfq->seniorOpsReviewedBy?->name, $restrictSourcingView), $rfq->senior_ops_reviewed_at->format('M d, Y g:i A')]
                : [$stepDone['data_entry'] ? 'Awaiting review' : 'Not yet reached'],
            'state' => $nodeState($stepDone['senior_ops'], $currentStep === 'senior_ops'),
            'children' => [$headOfBd],
        ];
    };

    // One branch per planned Sourcing part — including parts nobody's been
    // assigned to yet, which show as an "Unassigned" branch that stays
    // pending all the way down (see Rfq::sourcingParts()).
    $sourcingBranches = [];
    foreach ($rfq->sourcingParts() as $part) {
        $assignee = $part['assignee'];

        if ($assignee === null) {
            $rfqNumber = $rfq->split_count !== null && $rfq->isSplit() ? $part['number'] : null;

            $sourcingBranches[] = [
                'name' => 'Unassigned',
                'role' => 'Sourcing',
                'step' => 'sourcing',
                'rfq_number' => $rfqNumber,
                'meta' => ['Not yet assigned'],
                'state' => 'pending',
                'children' => [[
                    'name' => 'Unassigned',
                    'role' => 'Data Entry',
                    'step' => 'data_entry',
                    'rfq_number' => $rfqNumber,
                    'meta' => ['Awaiting Sourcing'],
                    'state' => 'pending',
                    'children' => [$buildTailChain($rfqNumber)],
                ]],
            ];

            continue;
        }

        $isOtherSourcingPartner = $restrictSourcingView && $assignee->id !== auth()->id();
        $blurred = $restrictAssignment || $isOtherSourcingPartner;
        $nameLabel = $maskIfBlurred($assignee->name, $blurred);
        $rfqNumber = $blurred ? null : $part['number'];

        $sourcingDone = $assignee->pivot->completed_at !== null;
        $deIsDone = $assignee->pivot->data_entry_completed_at !== null;
        $deIsReturned = ! $deIsDone && $assignee->pivot->returned_at !== null;
        $deActorName = $restrictSourcingView ? 'Data Entry' : ($assignee->pivot->dataEntryCompletedBy?->name ?? 'Unknown');

        $deMeta = match (true) {
            $deIsDone => [$deActorName, $assignee->pivot->data_entry_completed_at->format('M d, Y g:i A')],
            $deIsReturned => ['Returned — rework needed'],
            $sourcingDone => ['Awaiting review'],
            default => ['Awaiting Sourcing'],
        };

        $dataEntryNode = [
            'name' => $nameLabel,
            'role' => 'Data Entry',
            'step' => 'data_entry',
            'rfq_number' => $rfqNumber,
            'meta' => $deMeta,
            'state' => $nodeState($deIsDone, returned: $deIsReturned),
            'children' => [$buildTailChain($rfqNumber)],
        ];

        $sourcingBranches[] = [
            'name' => $nameLabel,
            'role' => 'Sourcing',
            'step' => 'sourcing',
            'rfq_number' => $rfqNumber,
            'meta' => $sourcingDone
                ? ['Completed', $assignee->pivot->completed_at->format('M d, Y g:i A')]
                : ['Pending since', $assignee->pivot->created_at->format('M d, Y g:i A')],
            'state' => $nodeState($sourcingDone),
            'children' => [$dataEntryNode],
        ];
    }

    $rfqProgressTree = [
        'name' => 'RFQ Created',
        'step' => 'created',
        'rfq_number' => $rfq->rfq_number,
        'meta' => ['Created by '.$maskIfBlurred($rfq->creator?->name, $restrictSourcingView), $rfq->created_at->format('M d, Y g:i A')],
        'state' => 'done',
        'children' => [[
            'name' => 'Assigned by Operations',
            'step' => 'operations',
            'rfq_number' => $rfq->rfq_number,
            'meta' => $rfq->operationsAssignee
                ? [$maskIfBlurred($rfq->operationsAssignee->name, $restrictSourcingView), $rfq->operations_assigned_at->format('M d, Y g:i A')]
                : ['Not yet assigned'],
            'state' => $nodeState($stepDone['operations'], $currentStep === 'operations'),
            'children' => [[
                'name' => 'Assigned to Sourcing',
                'step' => 'sourcing',
                'rfq_number' => $rfq->rfq_number,
                'meta' => $rfq->assignees->isEmpty() ? ['Not yet assigned'] : ($rfq->hasUnassignedParts() ? ['Some parts still open'] : []),
                'state' => $nodeState($stepDone['sourcing'], $currentStep === 'sourcing'),
                'children' => $sourcingBranches,
            ]],
        ]],
    ];
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
                      data-confirm="{{ $rfq->isSplit() ? 'Mark your part of this split RFQ complete? It only hands off to Data Entry once every assignee has completed theirs.' : 'Mark your Sourcing work done and hand this RFQ off to Data Entry?' }}">
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
                @if ($rfq->assignees->isEmpty() && $canSeeAssignOperationsButton)
                    <button type="button" class="btn btn-sm btn-outline-secondary js-assign-operations-rfq {{ $restrictAssignment ? 'rfq-blurred' : '' }}"
                            {{ $restrictAssignment ? 'disabled' : '' }}
                            data-bs-toggle="modal" data-bs-target="#assignOperationsModal"
                            data-action="{{ route('admin.rfqs.assign-operations', $rfq) }}"
                            data-operations-user-id="{{ $rfq->operations_assigned_by }}"
                            title="{{ $restrictAssignment ? 'Restricted for your role' : '' }}">
                        <i class="bi bi-diagram-2"></i> Assign Operations
                    </button>
                @endif
                @if ($canSeeAssignButtons && $rfq->hasUnassignedParts())
                    @include('admin.rfqs._assign_sourcing_button', ['rfq' => $rfq, 'restrictAssignment' => $restrictAssignment, 'showLabel' => true])
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
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Progress</span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Zoom">
                <button type="button" class="btn btn-outline-secondary" id="rfq-progress-zoom-out" title="Zoom out">
                    <i class="bi bi-zoom-out"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary" id="rfq-progress-zoom-reset" title="Reset zoom">
                    <i class="bi bi-aspect-ratio"></i>
                </button>
                <button type="button" class="btn btn-outline-secondary" id="rfq-progress-zoom-in" title="Zoom in">
                    <i class="bi bi-zoom-in"></i>
                </button>
            </div>
        </div>
        <div class="card-body">
            {{-- Rendered by Apache ECharts (tree series, left-to-right) —
                 real connector-line geometry instead of hand-rolled CSS.
                 Sourcing splits into one branch per assignee, and each
                 branch keeps going as its own path — Data Entry, Senior
                 Operations Approval, Head of Business Development, GM
                 Assistant, General Manager, Closed — rather than merging
                 back together, so a 3-way split shows three full chains
                 stacked as parallel rows. The zoom buttons above re-render
                 the chart bigger/smaller (rather than CSS-scaling it,
                 which would blur the canvas) — see public/js/admin.js
                 (renderRfqProgressChart). --}}
            <script type="application/json" id="rfq-progress-data">{!! json_encode(['tree' => $rfqProgressTree]) !!}</script>
            <div class="rfq-progress-chart-wrap">
                <div id="rfq-progress-chart" class="rfq-progress-chart"></div>
            </div>
            <noscript><p class="text-muted-soft mb-0">Enable JavaScript to see the progress chart.</p></noscript>
        </div>

        {{-- One tab per lifecycle stage — the same 9 stages the Progress
             chart above draws as nodes, but here as a compact read-out of
             exactly what happened (or is still pending) at each one,
             without needing to zoom/pan the chart to read a node's text.
             Opens on whichever stage is currently in play ($activeStep).
             Sourcing/Data Entry re-list every split assignee, since those
             two stages can have more than one of each. --}}
        <div class="card-body border-top">
            <ul class="nav nav-tabs mb-3" id="rfqStepTabs" role="tablist">
                @foreach ($stepTabs as $stepKey => $tab)
                    <li class="nav-item" role="presentation">
                        <button class="nav-link {{ $activeStep === $stepKey ? 'active' : '' }}"
                                id="step-tab-{{ $stepKey }}" data-bs-toggle="tab"
                                data-bs-target="#step-pane-{{ $stepKey }}" type="button" role="tab"
                                aria-controls="step-pane-{{ $stepKey }}"
                                aria-selected="{{ $activeStep === $stepKey ? 'true' : 'false' }}">
                            <i class="bi {{ $tab['icon'] }}"></i>
                            {{ $tab['label'] }}
                            @if ($stepDone[$stepKey])
                                <i class="bi bi-check-circle-fill text-success ms-1" title="Done"></i>
                            @elseif ($currentStep === $stepKey)
                                <i class="bi bi-arrow-right-circle text-primary ms-1" title="Current"></i>
                            @endif
                        </button>
                    </li>
                @endforeach
            </ul>

            <div class="tab-content" id="rfqStepTabsContent">
                <div class="tab-pane fade {{ $activeStep === 'created' ? 'show active' : '' }}" id="step-pane-created" role="tabpanel" aria-labelledby="step-tab-created">
                    <dl class="rfq-detail-grid mb-0">
                        <div>
                            <dt>Created</dt>
                            <dd>{{ $rfq->created_at->format('M d, Y g:i A') }}</dd>
                        </div>
                        <div>
                            <dt>Created by</dt>
                            <dd>{{ $maskIfBlurred($rfq->creator?->name, $restrictSourcingView) }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="tab-pane fade {{ $activeStep === 'operations' ? 'show active' : '' }}" id="step-pane-operations" role="tabpanel" aria-labelledby="step-tab-operations">
                    @if ($rfq->operationsAssignee)
                        <dl class="rfq-detail-grid mb-0">
                            <div>
                                <dt>Assigned by</dt>
                                <dd>{{ $maskIfBlurred($rfq->operationsAssignee->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Assigned at</dt>
                                <dd>{{ $rfq->operations_assigned_at->format('M d, Y g:i A') }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-muted-soft mb-0">Not yet assigned.</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'sourcing' ? 'show active' : '' }}" id="step-pane-sourcing" role="tabpanel" aria-labelledby="step-tab-sourcing">
                    @if ($rfq->assignees->isEmpty() && $rfq->split_count === null)
                        <p class="text-muted-soft mb-0">Not yet assigned.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Assignee</th>
                                        <th>Split</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rfq->sourcingParts() as $part)
                                        @php
                                            $tabAssignee = $part['assignee'];
                                            $tabBlurred = $tabAssignee && ($restrictAssignment || ($restrictSourcingView && $tabAssignee->id !== auth()->id()));
                                        @endphp
                                        <tr>
                                            @if ($tabAssignee)
                                                <td>{{ $maskIfBlurred($tabAssignee->name, $tabBlurred) }}</td>
                                                <td>{{ $tabBlurred ? 'Restricted' : $part['number'] }}</td>
                                                <td>
                                                    @if ($tabAssignee->pivot->completed_at !== null)
                                                        <span class="badge bg-success-subtle text-success-emphasis">Completed {{ $tabAssignee->pivot->completed_at->format('M d, Y g:i A') }}</span>
                                                    @else
                                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Pending since {{ $tabAssignee->pivot->created_at->format('M d, Y g:i A') }}</span>
                                                    @endif
                                                </td>
                                            @else
                                                <td class="text-muted-soft">Unassigned</td>
                                                <td>{{ $part['number'] }}</td>
                                                <td><span class="badge bg-warning-subtle text-warning-emphasis">Open — waiting for a Sourcing member</span></td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'data_entry' ? 'show active' : '' }}" id="step-pane-data_entry" role="tabpanel" aria-labelledby="step-tab-data_entry">
                    @if ($rfq->assignees->isEmpty() && $rfq->split_count === null)
                        <p class="text-muted-soft mb-0">Not yet assigned.</p>
                    @else
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th>Assignee</th>
                                        <th>Split</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rfq->sourcingParts() as $part)
                                        @php
                                            $tabAssignee = $part['assignee'];
                                            $tabBlurred = $tabAssignee && ($restrictAssignment || ($restrictSourcingView && $tabAssignee->id !== auth()->id()));
                                            $tabDeIsDone = $tabAssignee && $tabAssignee->pivot->data_entry_completed_at !== null;
                                            $tabDeIsReturned = $tabAssignee && ! $tabDeIsDone && $tabAssignee->pivot->returned_at !== null;
                                            $tabDeActorName = $restrictSourcingView ? 'Data Entry' : ($tabAssignee?->pivot->dataEntryCompletedBy?->name ?? 'Unknown');
                                        @endphp
                                        <tr>
                                            @if ($tabAssignee)
                                                <td>{{ $maskIfBlurred($tabAssignee->name, $tabBlurred) }}</td>
                                                <td>{{ $tabBlurred ? 'Restricted' : $part['number'] }}</td>
                                                <td>
                                                    @if ($tabDeIsDone)
                                                        <span class="badge bg-success-subtle text-success-emphasis">{{ $tabDeActorName }} · {{ $tabAssignee->pivot->data_entry_completed_at->format('M d, Y g:i A') }}</span>
                                                    @elseif ($tabDeIsReturned)
                                                        <span class="badge bg-danger-subtle text-danger-emphasis">Returned — rework needed</span>
                                                    @elseif ($tabAssignee->pivot->completed_at !== null)
                                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Awaiting review</span>
                                                    @else
                                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">Awaiting Sourcing</span>
                                                    @endif
                                                </td>
                                            @else
                                                <td class="text-muted-soft">Unassigned</td>
                                                <td>{{ $part['number'] }}</td>
                                                <td><span class="badge bg-secondary-subtle text-secondary-emphasis">Awaiting Sourcing</span></td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'senior_ops' ? 'show active' : '' }}" id="step-pane-senior_ops" role="tabpanel" aria-labelledby="step-tab-senior_ops">
                    @if ($rfq->senior_ops_reviewed_at)
                        <dl class="rfq-detail-grid mb-0">
                            <div>
                                <dt>Approved by</dt>
                                <dd>{{ $maskIfBlurred($rfq->seniorOpsReviewedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Approved at</dt>
                                <dd>{{ $rfq->senior_ops_reviewed_at->format('M d, Y g:i A') }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-muted-soft mb-0">{{ $stepDone['data_entry'] ? 'Awaiting review.' : 'Not yet reached.' }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'head_of_bd' ? 'show active' : '' }}" id="step-pane-head_of_bd" role="tabpanel" aria-labelledby="step-tab-head_of_bd">
                    @if ($rfq->head_of_bd_approved_at)
                        <dl class="rfq-detail-grid mb-0">
                            <div>
                                <dt>Approved by</dt>
                                <dd>{{ $maskIfBlurred($rfq->headOfBdApprovedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Approved at</dt>
                                <dd>{{ $rfq->head_of_bd_approved_at->format('M d, Y g:i A') }}</dd>
                            </div>
                        </dl>
                    @elseif ($rfq->head_of_bd_rejected_at)
                        <dl class="rfq-detail-grid mb-0">
                            <div>
                                <dt>Rejected by</dt>
                                <dd>{{ $maskIfBlurred($rfq->headOfBdRejectedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Rejected at</dt>
                                <dd>{{ $rfq->head_of_bd_rejected_at->format('M d, Y g:i A') }}</dd>
                            </div>
                            <div>
                                <dt>Returned to</dt>
                                <dd>{{ \App\Models\Rfq::stageLabel($rfq->head_of_bd_reject_target_stage) }}</dd>
                            </div>
                            <div>
                                <dt>Reason</dt>
                                <dd>{{ $rfq->head_of_bd_reject_reason }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-muted-soft mb-0">{{ $stepDone['senior_ops'] ? 'Awaiting review.' : 'Not yet reached.' }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'gm_assistant' ? 'show active' : '' }}" id="step-pane-gm_assistant" role="tabpanel" aria-labelledby="step-tab-gm_assistant">
                    @if ($rfq->gm_assistant_completed_at)
                        <dl class="rfq-detail-grid mb-3">
                            <div>
                                <dt>Completed by</dt>
                                <dd>{{ $maskIfBlurred($rfq->gmAssistantCompletedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Completed at</dt>
                                <dd>{{ $rfq->gm_assistant_completed_at->format('M d, Y g:i A') }}</dd>
                            </div>
                        </dl>
                        @if ($rfq->client_details)
                            <div class="fw-semibold small mb-1">Client Details</div>
                            <p class="mb-3" style="white-space: pre-line;">{{ $rfq->client_details }}</p>
                        @endif
                        @if ($rfq->payment_terms)
                            <div class="fw-semibold small mb-1">Payment Terms</div>
                            <p class="mb-0" style="white-space: pre-line;">{{ $rfq->payment_terms }}</p>
                        @endif
                    @else
                        <p class="text-muted-soft mb-0">{{ $stepDone['head_of_bd'] ? 'Awaiting details.' : 'Not yet reached.' }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'gm_review' ? 'show active' : '' }}" id="step-pane-gm_review" role="tabpanel" aria-labelledby="step-tab-gm_review">
                    @if ($rfq->gm_approved_at)
                        <dl class="rfq-detail-grid mb-0">
                            <div>
                                <dt>Approved by</dt>
                                <dd>{{ $maskIfBlurred($rfq->gmApprovedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Approved at</dt>
                                <dd>{{ $rfq->gm_approved_at->format('M d, Y g:i A') }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-muted-soft mb-0">{{ $stepDone['gm_assistant'] ? 'Awaiting approval.' : 'Not yet reached.' }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'closed' ? 'show active' : '' }}" id="step-pane-closed" role="tabpanel" aria-labelledby="step-tab-closed">
                    @if ($rfq->bd_closed_at)
                        <dl class="rfq-detail-grid mb-0">
                            <div>
                                <dt>Closed by</dt>
                                <dd>{{ $maskIfBlurred($rfq->bdClosedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Closed at</dt>
                                <dd>{{ $rfq->bd_closed_at->format('M d, Y g:i A') }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-muted-soft mb-0">{{ $stepDone['gm_review'] ? 'Ready for Business Development to close.' : 'Not yet reached.' }}</p>
                    @endif
                </div>
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
                        @if ($rfq->category)
                            <div>
                                <dt>Category</dt>
                                <dd>{{ $rfq->category }}</dd>
                            </div>
                        @endif
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
                        @foreach ($rfq->sourcingParts() as $part)
                            @php $assignee = $part['assignee']; @endphp
                            @if ($assignee)
                                <div class="d-flex align-items-center gap-2 mb-2 {{ ($restrictSourcingView && $assignee->id !== auth()->id()) ? 'rfq-blurred' : '' }}">
                                    <span class="assignee-avatar">{{ strtoupper(substr($assignee->name, 0, 1)) }}</span>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold small">{{ $assignee->name }}</div>
                                        <div class="text-muted-soft assignee-email">{{ $assignee->email }}</div>
                                        @if ($rfq->isSplit())
                                            <div class="text-muted-soft small fw-semibold">{{ $part['number'] }}</div>
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
                            @elseif ($rfq->split_count !== null)
                                {{-- A planned part nobody holds yet (Operations left it
                                     empty in the Assign Sourcing wizard). --}}
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="assignee-avatar assignee-avatar-empty"><i class="bi bi-person-dash"></i></span>
                                    <div class="flex-grow-1">
                                        <div class="fw-semibold small text-muted-soft">Unassigned</div>
                                        <div class="text-muted-soft small fw-semibold">{{ $part['number'] }}</div>
                                    </div>
                                    <span class="badge badge-soft-warning">Open</span>
                                </div>
                            @else
                                <p class="text-muted-soft mb-0">Not assigned yet.</p>
                            @endif
                        @endforeach
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

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/echarts@5.5.1/dist/echarts.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                if (window.renderRfqProgressChart) {
                    window.renderRfqProgressChart();
                }
            });
        </script>
    @endpush
@endsection
