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

    // Only a Sourcing member actually assigned to a part of this RFQ can
    // mark that part complete, and only once. Every part is completed on its
    // own — someone holding several gets a Mark Complete for each — and the
    // RFQ only hands off to Data Entry once all of its parts have been. See
    // Rfq::completeSourcingPart().
    $myParts = $restrictSourcingView ? $rfq->assignees->where('id', auth()->id()) : collect();
    $myOpenParts = $myParts->whereNull('pivot.completed_at');

    // Data Entry sent one of this Sourcing viewer's parts back for rework —
    // flagged here so a banner with the reason can show up front, rather
    // than the reappearing Mark Complete button being the only clue.
    $myReturnedParts = $myOpenParts->whereNotNull('pivot.returned_at');

    // Admin can mark any open part complete too — as its assigned member,
    // who the prompt names — see RfqController::completeSourcing(). Not the
    // ones already offered above, if Admin also holds Sourcing.
    $adminOpenParts = auth()->user()->hasRole('Admin')
        ? $rfq->assignees->whereNull('pivot.completed_at')->whereNotIn('pivot.part_number', $myOpenParts->pluck('pivot.part_number'))
        : collect();

    // Sourcing never gets access to the assign controls at all — it's a
    // receiving role, not an assigning one — enforced server-side too, see
    // RfqController::assign()/assignOperations().
    $canSeeAssignButtons = ! $restrictSourcingView;

    // Operations doesn't need a separate "Assign Operations" picker — when
    // they assign Sourcing, they're implicitly recorded as the one routing
    // it (see RfqController::assign()), so just the one button is shown.
    // Admin isn't offered it either: it's only a way to name someone in
    // Operations, which is Operations' own call.
    $canSeeAssignOperationsButton = $canSeeAssignButtons && ! auth()->user()->hasAnyRole(['Senior Operations', 'Admin']);

    // Senior Operations' second review — only shown while the RFQ is
    // actually sitting in that stage, same as it only appears on the
    // Review queue while it's there. See Rfq::completeSeniorOpsReview() /
    // rejectToStage().
    $canApproveSeniorOpsReview = auth()->user()->hasAnyRole(['Senior Operations', 'Admin']) && $rfq->stage === 'senior_ops_review';
    $canRejectSeniorOps = $canApproveSeniorOpsReview;

    // Head of Business Development's own review — same "only while it's
    // actually theirs to decide" rule. See Rfq::approveByHeadOfBd() /
    // rejectToStage().
    $canDecideHeadOfBdReview = auth()->user()->hasAnyRole(['Head of Business Development', 'Admin']) && $rfq->stage === 'head_of_bd_review';

    // GM Assistant's own turn — same rule again. See
    // Rfq::recordGmAssistantDetails().
    $canSubmitGmAssistantDetails = auth()->user()->hasAnyRole(['GM Assistant', 'Admin']) && $rfq->stage === 'gm_assistant';

    // General Manager's final approval — same rule again. See
    // Rfq::approveByGm() / rejectToStage().
    $canApproveGm = auth()->user()->hasAnyRole(['General Manager', 'Admin']) && $rfq->stage === 'gm_review';
    $canRejectGm = $canApproveGm;

    // The one shared reject modal (_reject_modal) works for whichever of
    // the three is theirs to decide right now — its route name and the
    // stages it can send the RFQ back to (Rfq::rejectTargetStages()) both
    // follow from which.
    $rejectFromStage = match (true) {
        $canRejectSeniorOps => 'senior_ops_review',
        $canDecideHeadOfBdReview => 'head_of_bd_review',
        $canRejectGm => 'gm_review',
        default => null,
    };
    $rejectRouteName = match ($rejectFromStage) {
        'senior_ops_review' => 'admin.rfqs.reject-senior-ops',
        'head_of_bd_review' => 'admin.rfqs.reject-head-of-bd',
        'gm_review' => 'admin.rfqs.reject-gm',
        default => null,
    };
    $rejectTargetStages = $rejectFromStage ? \App\Models\Rfq::rejectTargetStages($rejectFromStage) : [];
    $rejectRole = match ($rejectFromStage) {
        'senior_ops_review' => 'Senior Operations',
        'head_of_bd_review' => 'Head of Business Development',
        'gm_review' => 'General Manager',
        default => null,
    };

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

    // Most stages are taken part by part on a split RFQ, each part going on as
    // soon as it's through — so how many parts have got past each one, for
    // the tabs below the chart to show while the stage as a whole isn't done.
    $stepPartColumns = [
        'sourcing' => 'completed_at',
        'data_entry' => 'data_entry_completed_at',
        'senior_ops' => 'senior_ops_reviewed_at',
        'head_of_bd' => 'head_of_bd_approved_at',
        'gm_assistant' => 'gm_assistant_completed_at',
        'gm_review' => 'gm_approved_at',
        'closed' => 'bd_closed_at',
    ];
    $stepPartsDone = collect($stepPartColumns)->map(
        fn (string $column) => $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->{$column} !== null)->count()
    );

    // The latest time the Head sent something back — which part it was about,
    // if it was one part (RfqComment::concernsPart()) — so only that part's
    // branch shows it as sent back, rather than every one.
    $latestRejection = $rfq->comments->where('action', 'rejected')->last();

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
    // its own complete path top to bottom. Each part goes through those
    // stages on its own, so every branch shows its own part's progress: a
    // stage goes green on the branch as soon as that part has been through
    // it, whatever the others are up to — Closed included, since Business
    // Development closes part by part too. See public/js/admin.js
    // (renderRfqProgressChart) and the #rfq-progress-data script below.
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
    // comment above), each copy going by its own part's progress — or the
    // RFQ's own when there's no part to speak of ($part null: nobody's been
    // assigned to that branch). $rfqNumber identifies which split a given
    // copy belongs to once there's more than one — null for a single, unsplit
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
    $buildTailChain = function (?string $rfqNumber, ?\App\Models\RfqAssignment $part = null) use ($rfq, $maskIfBlurred, $restrictSourcingView, $nodeState, $stepDone, $latestRejection) {
        // Who did each stage and when, for this part — or the RFQ, with no part.
        $seniorOpsAt = $part ? $part->senior_ops_reviewed_at : $rfq->senior_ops_reviewed_at;
        $seniorOpsBy = $part ? $part->seniorOpsReviewedBy : $rfq->seniorOpsReviewedBy;
        $headAt = $part ? $part->head_of_bd_approved_at : $rfq->head_of_bd_approved_at;
        $headBy = $part ? $part->headOfBdApprovedBy : $rfq->headOfBdApprovedBy;
        $assistantAt = $part ? $part->gm_assistant_completed_at : $rfq->gm_assistant_completed_at;
        $assistantBy = $part ? $part->gmAssistantCompletedBy : $rfq->gmAssistantCompletedBy;
        $gmAt = $part ? $part->gm_approved_at : $rfq->gm_approved_at;
        $gmBy = $part ? $part->gmApprovedBy : $rfq->gmApprovedBy;
        // An RFQ closed whole, before parts were closed one by one, has no
        // closing recorded on its parts — it's the RFQ's own.
        $closedAt = $part?->bd_closed_at ?? $rfq->bd_closed_at;
        $closedBy = $part?->bd_closed_at ? $part->bdClosedBy : $rfq->bdClosedBy;
        $dataEntryDone = $part ? $part->data_entry_completed_at !== null : $stepDone['data_entry'];

        // Sent back from here — Senior Operations' second review, the Head,
        // or the General Manager — and not yet redone: on every branch when
        // it was the whole RFQ, on just the part's own when it was one part.
        $rejectedHere = fn (string $stage, $doneAt) => $rfq->reject_from_stage === $stage
            && $rfq->rejected_at !== null
            && $doneAt === null
            && ($part === null || $latestRejection === null || $latestRejection->concernsPart($part->part_number));
        $seniorOpsRejected = $rejectedHere('senior_ops_review', $seniorOpsAt);
        $headRejected = $rejectedHere('head_of_bd_review', $headAt);
        $gmRejected = $rejectedHere('gm_review', $gmAt);

        $closed = [
            'name' => 'Closed',
            'step' => 'closed',
            'rfq_number' => $rfqNumber,
            'meta' => $closedAt
                ? [$maskIfBlurred($closedBy?->name, $restrictSourcingView), $closedAt->format('M d, Y g:i A')]
                : [$gmAt !== null ? 'Ready for Business Development' : 'Not yet reached'],
            'state' => $nodeState($closedAt !== null, $gmAt !== null && $closedAt === null),
            'children' => [],
        ];

        $gmReview = [
            'name' => 'General Manager',
            'step' => 'gm_review',
            'rfq_number' => $rfqNumber,
            'meta' => match (true) {
                $gmAt !== null => ['Approved by '.$maskIfBlurred($gmBy?->name, $restrictSourcingView), $gmAt->format('M d, Y g:i A')],
                $gmRejected => ['Rejected by '.$maskIfBlurred($rfq->rejectedBy?->name, $restrictSourcingView), 'Returned to '.\App\Models\Rfq::stageLabel($rfq->reject_target_stage)],
                default => [$assistantAt !== null ? 'Awaiting approval' : 'Not yet reached'],
            },
            'state' => $nodeState($gmAt !== null, $assistantAt !== null && $gmAt === null, $gmRejected),
            'children' => [$closed],
        ];

        $gmAssistant = [
            'name' => 'GM Assistant',
            'step' => 'gm_assistant',
            'rfq_number' => $rfqNumber,
            'meta' => $assistantAt
                ? [$maskIfBlurred($assistantBy?->name, $restrictSourcingView), $assistantAt->format('M d, Y g:i A')]
                : [$headAt !== null ? 'Awaiting details' : 'Not yet reached'],
            'state' => $nodeState($assistantAt !== null, $headAt !== null && $assistantAt === null),
            'children' => [$gmReview],
        ];

        $headOfBd = [
            'name' => 'Head of Business Development',
            'step' => 'head_of_bd',
            'rfq_number' => $rfqNumber,
            'meta' => match (true) {
                $headAt !== null => ['Approved by '.$maskIfBlurred($headBy?->name, $restrictSourcingView), $headAt->format('M d, Y g:i A')],
                $headRejected => ['Rejected by '.$maskIfBlurred($rfq->rejectedBy?->name, $restrictSourcingView), 'Returned to '.\App\Models\Rfq::stageLabel($rfq->reject_target_stage)],
                default => [$seniorOpsAt !== null ? 'Awaiting review' : 'Not yet reached'],
            },
            'state' => $nodeState($headAt !== null, $seniorOpsAt !== null && $headAt === null, $headRejected),
            'children' => [$gmAssistant],
        ];

        return [
            'name' => 'Senior Operations Approval',
            'step' => 'senior_ops',
            'rfq_number' => $rfqNumber,
            'meta' => match (true) {
                $seniorOpsAt !== null => [$maskIfBlurred($seniorOpsBy?->name, $restrictSourcingView), $seniorOpsAt->format('M d, Y g:i A')],
                $seniorOpsRejected => ['Rejected by '.$maskIfBlurred($rfq->rejectedBy?->name, $restrictSourcingView), 'Returned to '.\App\Models\Rfq::stageLabel($rfq->reject_target_stage)],
                default => [$dataEntryDone ? 'Awaiting review' : 'Not yet reached'],
            },
            'state' => $nodeState($seniorOpsAt !== null, $dataEntryDone && $seniorOpsAt === null, $seniorOpsRejected),
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
            'children' => [$buildTailChain($rfqNumber, $assignee->pivot)],
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
            @foreach ($myOpenParts as $myPart)
                @include('admin.rfqs._complete_button', [
                    'rfq' => $rfq,
                    'part' => $myPart->pivot->part_number,
                    'returnTo' => 'show',
                    'label' => 'Mark '.($rfq->isSplit() ? 'P'.$myPart->pivot->part_number.' ' : '').'Complete',
                ])
            @endforeach
            @foreach ($adminOpenParts as $adminPart)
                @include('admin.rfqs._complete_button', [
                    'rfq' => $rfq,
                    'part' => $adminPart->pivot->part_number,
                    'returnTo' => 'show',
                    'label' => 'Mark '.($rfq->isSplit() ? 'P'.$adminPart->pivot->part_number.' ' : '').'Complete',
                ])
            @endforeach
            @if ($canApproveSeniorOpsReview)
                <form action="{{ route('admin.rfqs.complete-senior-ops-review', $rfq) }}" method="POST"
                      data-confirm="Approve this RFQ? It moves on to Head of Business Development.">
                    @csrf
                    @method('PATCH')
                    @include('admin.rfqs._acting_as', ['role' => 'Senior Operations'])
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check2-circle"></i> Approve
                    </button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger js-reject-rfq"
                        data-bs-toggle="modal" data-bs-target="#rejectRfqModal"
                        data-action="{{ route('admin.rfqs.reject-senior-ops', $rfq) }}"
                        data-rfq-id="{{ $rfq->id }}">
                    <i class="bi bi-arrow-counterclockwise"></i> Reject
                </button>
            @endif
            @if ($canDecideHeadOfBdReview)
                <form action="{{ route('admin.rfqs.approve-head-of-bd', $rfq) }}" method="POST"
                      data-confirm="Approve this RFQ? It moves on to GM Assistant.">
                    @csrf
                    @method('PATCH')
                    @include('admin.rfqs._acting_as', ['role' => 'Head of Business Development'])
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
                    @include('admin.rfqs._acting_as', ['role' => 'General Manager'])
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="bi bi-check2-circle"></i> Approve
                    </button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger js-reject-rfq"
                        data-bs-toggle="modal" data-bs-target="#rejectRfqModal"
                        data-action="{{ route('admin.rfqs.reject-gm', $rfq) }}"
                        data-rfq-id="{{ $rfq->id }}">
                    <i class="bi bi-arrow-counterclockwise"></i> Reject
                </button>
            @endif
            @if ($canCloseRfq)
                <form action="{{ route('admin.rfqs.close', $rfq) }}" method="POST"
                      data-confirm="Close this RFQ? It moves out of Pending into Closed RFQs.">
                    @csrf
                    @method('PATCH')
                    @include('admin.rfqs._acting_as', ['role' => 'Business Development'])
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
        </div>
    </div>

    @foreach ($myReturnedParts as $returnedPart)
        <div class="alert alert-danger d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-arrow-counterclockwise fs-5"></i>
            <div>
                <div class="fw-bold">Data Entry sent {{ $rfq->isSplit() ? $rfq->partNumberLabel($returnedPart->pivot->part_number) : 'your part' }} back for rework</div>
                <div>{{ $returnedPart->pivot->return_reason }}</div>
            </div>
        </div>
    @endforeach

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
            <div class="rfq-progress-chart-wrap" data-live-scroll="progress-chart">
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
                            @else
                                {{-- A split goes through most stages part by part: some parts done. --}}
                                @if ($rfq->isSplit() && ($stepPartsDone[$stepKey] ?? 0) > 0)
                                    <span class="badge badge-soft-success ms-1" title="{{ $stepPartsDone[$stepKey] }} of {{ $rfq->splitTotal() }} parts done">{{ $stepPartsDone[$stepKey] }}/{{ $rfq->splitTotal() }}</span>
                                @endif
                                @if ($currentStep === $stepKey)
                                    <i class="bi bi-arrow-right-circle text-primary ms-1" title="Current"></i>
                                @endif
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

                {{-- Senior Operations Approval, the Head, GM Assistant and the General
                     Manager each take a split part by part (_step_parts) — an RFQ
                     kept whole has just the one record of each. --}}
                <div class="tab-pane fade {{ $activeStep === 'senior_ops' ? 'show active' : '' }}" id="step-pane-senior_ops" role="tabpanel" aria-labelledby="step-tab-senior_ops">
                    @if ($rfq->isSplit())
                        @include('admin.rfqs._step_parts', ['doneColumn' => 'senior_ops_reviewed_at', 'byRelation' => 'seniorOpsReviewedBy', 'reachedColumn' => 'data_entry_completed_at', 'awaiting' => 'Awaiting review'])
                    @elseif ($rfq->senior_ops_reviewed_at)
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
                    @endif
                    @if ($rfq->reject_from_stage === 'senior_ops_review' && $rfq->rejected_at && ! $rfq->senior_ops_reviewed_at)
                        {{-- The latest thing sent back, and which part it was if it was one. --}}
                        <dl class="rfq-detail-grid mb-0 {{ $rfq->isSplit() ? 'mt-3' : '' }}">
                            <div>
                                <dt>Rejected by</dt>
                                <dd>{{ $maskIfBlurred($rfq->rejectedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Rejected at</dt>
                                <dd>{{ $rfq->rejected_at->format('M d, Y g:i A') }}</dd>
                            </div>
                            @if ($latestRejection?->meta['label'] ?? null)
                                <div>
                                    <dt>Part</dt>
                                    <dd>{{ $latestRejection->meta['label'] }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt>Returned to</dt>
                                <dd>{{ \App\Models\Rfq::stageLabel($rfq->reject_target_stage) }}</dd>
                            </div>
                            <div>
                                <dt>Reason</dt>
                                <dd>{{ $rfq->reject_reason }}</dd>
                            </div>
                        </dl>
                    @elseif (! $rfq->isSplit() && ! $rfq->senior_ops_reviewed_at)
                        <p class="text-muted-soft mb-0">{{ $stepDone['data_entry'] ? 'Awaiting review.' : 'Not yet reached.' }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'head_of_bd' ? 'show active' : '' }}" id="step-pane-head_of_bd" role="tabpanel" aria-labelledby="step-tab-head_of_bd">
                    @if ($rfq->isSplit())
                        @include('admin.rfqs._step_parts', ['doneColumn' => 'head_of_bd_approved_at', 'byRelation' => 'headOfBdApprovedBy', 'reachedColumn' => 'senior_ops_reviewed_at', 'awaiting' => 'Awaiting review'])
                    @elseif ($rfq->head_of_bd_approved_at)
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
                    @endif
                    @if ($rfq->reject_from_stage === 'head_of_bd_review' && $rfq->rejected_at && ! $rfq->head_of_bd_approved_at)
                        {{-- The latest thing sent back, and which part it was if it was one. --}}
                        <dl class="rfq-detail-grid mb-0 {{ $rfq->isSplit() ? 'mt-3' : '' }}">
                            <div>
                                <dt>Rejected by</dt>
                                <dd>{{ $maskIfBlurred($rfq->rejectedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Rejected at</dt>
                                <dd>{{ $rfq->rejected_at->format('M d, Y g:i A') }}</dd>
                            </div>
                            @if ($latestRejection?->meta['label'] ?? null)
                                <div>
                                    <dt>Part</dt>
                                    <dd>{{ $latestRejection->meta['label'] }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt>Returned to</dt>
                                <dd>{{ \App\Models\Rfq::stageLabel($rfq->reject_target_stage) }}</dd>
                            </div>
                            <div>
                                <dt>Reason</dt>
                                <dd>{{ $rfq->reject_reason }}</dd>
                            </div>
                        </dl>
                    @elseif (! $rfq->isSplit() && ! $rfq->head_of_bd_approved_at)
                        <p class="text-muted-soft mb-0">{{ $stepDone['senior_ops'] ? 'Awaiting review.' : 'Not yet reached.' }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'gm_assistant' ? 'show active' : '' }}" id="step-pane-gm_assistant" role="tabpanel" aria-labelledby="step-tab-gm_assistant">
                    @if ($rfq->isSplit())
                        @include('admin.rfqs._step_parts', ['doneColumn' => 'gm_assistant_completed_at', 'byRelation' => 'gmAssistantCompletedBy', 'reachedColumn' => 'head_of_bd_approved_at', 'awaiting' => 'Awaiting details'])
                    @elseif ($rfq->gm_assistant_completed_at)
                        <dl class="rfq-detail-grid mb-0">
                            <div>
                                <dt>Completed by</dt>
                                <dd>{{ $maskIfBlurred($rfq->gmAssistantCompletedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Completed at</dt>
                                <dd>{{ $rfq->gm_assistant_completed_at->format('M d, Y g:i A') }}</dd>
                            </div>
                        </dl>
                    @else
                        <p class="text-muted-soft mb-0">{{ $stepDone['head_of_bd'] ? 'Awaiting details.' : 'Not yet reached.' }}</p>
                    @endif
                    {{-- Given once, for the RFQ — from the first part GM Assistant does. --}}
                    @if ($rfq->client_details)
                        <div class="fw-semibold small mb-1 mt-3">Client Details</div>
                        <p class="mb-0" style="white-space: pre-line;">{{ $rfq->client_details }}</p>
                    @endif
                    @if ($rfq->payment_terms)
                        <div class="fw-semibold small mb-1 mt-3">Payment Terms</div>
                        <p class="mb-0" style="white-space: pre-line;">{{ $rfq->payment_terms }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'gm_review' ? 'show active' : '' }}" id="step-pane-gm_review" role="tabpanel" aria-labelledby="step-tab-gm_review">
                    @if ($rfq->isSplit())
                        @include('admin.rfqs._step_parts', ['doneColumn' => 'gm_approved_at', 'byRelation' => 'gmApprovedBy', 'reachedColumn' => 'gm_assistant_completed_at', 'awaiting' => 'Awaiting approval'])
                    @elseif ($rfq->gm_approved_at)
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
                    @endif
                    @if ($rfq->reject_from_stage === 'gm_review' && $rfq->rejected_at && ! $rfq->gm_approved_at)
                        {{-- The latest thing sent back, and which part it was if it was one. --}}
                        <dl class="rfq-detail-grid mb-0 {{ $rfq->isSplit() ? 'mt-3' : '' }}">
                            <div>
                                <dt>Rejected by</dt>
                                <dd>{{ $maskIfBlurred($rfq->rejectedBy?->name, $restrictSourcingView) }}</dd>
                            </div>
                            <div>
                                <dt>Rejected at</dt>
                                <dd>{{ $rfq->rejected_at->format('M d, Y g:i A') }}</dd>
                            </div>
                            @if ($latestRejection?->meta['label'] ?? null)
                                <div>
                                    <dt>Part</dt>
                                    <dd>{{ $latestRejection->meta['label'] }}</dd>
                                </div>
                            @endif
                            <div>
                                <dt>Returned to</dt>
                                <dd>{{ \App\Models\Rfq::stageLabel($rfq->reject_target_stage) }}</dd>
                            </div>
                            <div>
                                <dt>Reason</dt>
                                <dd>{{ $rfq->reject_reason }}</dd>
                            </div>
                        </dl>
                    @elseif (! $rfq->isSplit() && ! $rfq->gm_approved_at)
                        <p class="text-muted-soft mb-0">{{ $stepDone['gm_assistant'] ? 'Awaiting approval.' : 'Not yet reached.' }}</p>
                    @endif
                </div>

                <div class="tab-pane fade {{ $activeStep === 'closed' ? 'show active' : '' }}" id="step-pane-closed" role="tabpanel" aria-labelledby="step-tab-closed">
                    @if ($rfq->isSplit())
                        @include('admin.rfqs._step_parts', ['doneColumn' => 'bd_closed_at', 'byRelation' => 'bdClosedBy', 'reachedColumn' => 'gm_approved_at', 'awaiting' => 'Ready to close'])
                    @elseif ($rfq->bd_closed_at)
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

        // Data Entry/Admin can complete an assignee's finished split or send
        // it back to Sourcing right from here — the same actions as the
        // quick-detail modal's, side by side, each asking for a comment (the
        // reason, for a return) in the shared prompt. One line per part still
        // eligible for it (its Sourcing work is done, but Data Entry hasn't
        // processed it yet) — a split RFQ can have more than one at once,
        // including several held by the same person. See
        // RfqController::completeDataEntry() and returnSourcing().
        $canReturnSourcing = auth()->user()->hasAnyRole(['Data Entry', 'Admin']);
        $returnEligibleAssignees = $canReturnSourcing
            ? $rfq->assignees->filter(fn ($assignee) => $assignee->pivot->completed_at !== null && $assignee->pivot->data_entry_completed_at === null)
            : collect();
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
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-3 border-bottom">
                            <div class="fw-semibold small">
                                {{ $returnAssignee->name }}'s part{{ $rfq->isSplit() ? ' ('.$rfq->partNumberLabel($returnAssignee->pivot->part_number).')' : '' }}
                            </div>
                            <div class="d-flex gap-2">
                                @include('admin.rfqs._complete_button', [
                                    'rfq' => $rfq,
                                    'part' => $returnAssignee->pivot->part_number,
                                    'kind' => 'return',
                                    'who' => $returnAssignee->name,
                                ])
                                @include('admin.rfqs._complete_button', [
                                    'rfq' => $rfq,
                                    'part' => $returnAssignee->pivot->part_number,
                                    'kind' => 'data_entry',
                                    'who' => $returnAssignee->name,
                                ])
                            </div>
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
    @if ($myOpenParts->isNotEmpty() || $adminOpenParts->isNotEmpty() || $returnEligibleAssignees->isNotEmpty())
        @include('admin.rfqs._complete_modal')
    @endif
    @if ($rejectFromStage)
        @include('admin.rfqs._reject_modal', ['rejectTargetStages' => $rejectTargetStages, 'rejectRole' => $rejectRole])
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

    @if ($rejectRouteName && $errors->reject->any())
        @push('scripts')
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalEl = document.getElementById('rejectRfqModal');
                    var form = document.getElementById('rejectRfqForm');
                    if (modalEl && form) {
                        form.action = @json(route($rejectRouteName, $rfq));
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
