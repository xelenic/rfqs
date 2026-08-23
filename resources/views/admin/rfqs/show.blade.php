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
    $canSeeAssignOperationsButton = $canSeeAssignButtons && ! auth()->user()->hasRole('Operations');

    // A Sourcing assignee sees their own split RFQ number (e.g.
    // "RFQ1001-P2 of P3") once more than one person is sharing the work;
    // everyone else sees the master number. See Rfq::sourcingSplitNumberFor().
    $displayRfqNumber = ($restrictSourcingView && $rfq->assignees->contains('id', auth()->id()))
        ? $rfq->sourcingSplitNumberFor(auth()->user())
        : $rfq->rfq_number;
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
            <div class="rfq-flow">
                <div class="rfq-flow-node rfq-flow-node-created">
                    <div class="rfq-flow-icon"><i class="bi bi-file-earmark-plus"></i></div>
                    <div>
                        <div class="rfq-flow-title">RFQ Created</div>
                        <div class="rfq-flow-meta">{{ $rfq->created_at->format('M d, Y g:i A') }}</div>
                        <div class="rfq-flow-meta {{ $restrictSourcingView ? 'rfq-blurred' : '' }}">by {{ $rfq->creator?->name ?? 'Unknown' }}</div>
                    </div>
                </div>

                <div class="rfq-flow-connector"></div>

                @if ($rfq->operationsAssignee)
                    <div class="rfq-flow-node rfq-flow-node-operations">
                        <div class="rfq-flow-icon"><i class="bi bi-diagram-2"></i></div>
                        <div>
                            <div class="rfq-flow-title">Assigned by Operations</div>
                            <div class="{{ $restrictSourcingView ? 'rfq-blurred' : '' }}">
                                <div class="rfq-flow-meta fw-semibold">{{ $rfq->operationsAssignee->name }}</div>
                                <div class="rfq-flow-meta">{{ $rfq->operations_assigned_at->format('M d, Y g:i A') }}</div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="rfq-flow-node rfq-flow-node-pending">
                        <div class="rfq-flow-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="rfq-flow-title">Awaiting Operations</div>
                            <div class="rfq-flow-meta">Not yet assigned</div>
                        </div>
                    </div>
                @endif

                <div class="rfq-flow-connector"></div>

                <div class="rfq-flow-branches">
                    @forelse ($rfq->assignees->sortBy('pivot.created_at') as $assignee)
                        @php $isOtherSourcingPartner = $restrictSourcingView && $assignee->id !== auth()->id(); @endphp
                        <div class="rfq-flow-branch">
                            <div class="rfq-flow-node rfq-flow-node-assigned">
                                <div class="rfq-flow-icon"><i class="bi bi-person-check"></i></div>
                                <div>
                                    <div class="rfq-flow-title">Assigned to Sourcing</div>
                                    <div class="{{ ($restrictAssignment || $isOtherSourcingPartner) ? 'rfq-blurred' : '' }}">
                                        <div class="rfq-flow-meta fw-semibold">{{ $assignee->name }}</div>
                                        <div class="rfq-flow-meta">{{ $assignee->pivot->created_at->format('M d, Y g:i A') }}</div>
                                        @if ($assignee->pivot->completed_at)
                                            <div class="rfq-flow-meta rfq-flow-meta-done">
                                                <i class="bi bi-check-circle-fill"></i> Part completed
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="rfq-flow-branch">
                            <div class="rfq-flow-node rfq-flow-node-pending">
                                <div class="rfq-flow-icon"><i class="bi bi-hourglass-split"></i></div>
                                <div>
                                    <div class="rfq-flow-title">Awaiting Sourcing</div>
                                    <div class="rfq-flow-meta">Not yet assigned</div>
                                </div>
                            </div>
                        </div>
                    @endforelse
                </div>

                <div class="rfq-flow-connector"></div>

                @if ($rfq->isWithDataEntry())
                    <div class="rfq-flow-node rfq-flow-node-operations">
                        <div class="rfq-flow-icon"><i class="bi bi-clipboard-check"></i></div>
                        <div>
                            <div class="rfq-flow-title">Handed to Data Entry</div>
                            <div class="{{ ($restrictSourcingView && $rfq->sourcing_completed_by !== auth()->id()) ? 'rfq-blurred' : '' }}">
                                <div class="rfq-flow-meta fw-semibold">{{ $rfq->sourcingCompletedBy?->name ?? 'Unknown' }}</div>
                                <div class="rfq-flow-meta">{{ $rfq->sourcing_completed_at->format('M d, Y g:i A') }}</div>
                            </div>
                        </div>
                    </div>
                @else
                    <div class="rfq-flow-node rfq-flow-node-pending">
                        <div class="rfq-flow-icon"><i class="bi bi-hourglass-split"></i></div>
                        <div>
                            <div class="rfq-flow-title">Awaiting Data Entry</div>
                            <div class="rfq-flow-meta">Sourcing not yet complete</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span>{{ $rfq->subject }}</span>
                    <div class="d-flex gap-2">
                        <span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->status }}</span>
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
@endsection
