{{--
    Quick-detail modal shared by Data Entry's "By Sourcing" list and
    Sourcing's own "My Pending RFQs" — one per (RFQ, Sourcing part) row,
    opened via .js-de-sourcing-row (see admin.js) when that row is
    clicked. Shows the subject, description, that one part's status,
    and a comment thread scoped to them — their own comments plus anyone
    else's who isn't one of the *other* Sourcing assignees on this RFQ, so
    one sourcer's split doesn't leak into another's. On a split RFQ each part
    keeps its own thread: a completion or return recorded against another
    part stays on that part's modal (RfqComment::concernsPart()), while
    ordinary comments and RFQ-wide actions show on every part. The thread is for
    reading: comments go in with a Mark Complete or a Return to Sourcing,
    each of which asks for one (_complete_modal.blade.php). For replying or
    @mention autocomplete, Data Entry/Admin can open the full RFQ page.

    Actions shown depend on who's looking, and sit together in the footer: the
    part's assignee themselves gets Mark Complete (if it's not done yet);
    Data Entry/Admin get Return to Sourcing next to Mark Complete (once
    Sourcing's done, until Data Entry has been).

    Expects: $rfq, $assignee (a member of $rfq->assignees — one part, with
    comments.author, comments.replies.author eager-loaded on $rfq, see
    RfqController::index()). Optional: $redirectView — 'returns' on Sourcing's
    Returns list, so completing the part from here lands back on it (it reaches
    the Mark Complete button inside, see _complete_button.blade.php).
--}}
@php
    $part = $assignee->pivot->part_number;
    $partLabel = $rfq->partNumberLabel($part);
    $modalId = 'rfq-detail-modal-'.$rfq->id.'-p'.$part;
    $isMe = $assignee->id === auth()->id();
    $isDone = $assignee->pivot->completed_at !== null;
    $isReturned = ! $isDone && $assignee->pivot->returned_at !== null;

    // Everything except comments from the *other* Sourcing assignees on
    // this RFQ — this assignee's own comments and anyone else's (Data
    // Entry, Operations, etc.) still show, only cross-sourcer chatter is
    // hidden — and except actions recorded against a different part of the
    // split, which belong on that part's own modal.
    $otherAssigneeIds = $rfq->assignees->where('id', '!=', $assignee->id)->pluck('id');
    $visibleComments = $rfq->comments
        ->whereNotIn('user_id', $otherAssigneeIds)
        ->filter(fn ($comment) => $comment->concernsPart($part));

    // Sourcing works entirely from this modal on "My Pending RFQs" — no
    // need for an escape hatch to the full RFQ page there. Data Entry/Admin
    // still get it from "By Sourcing".
    $canOpenFullRfq = ! auth()->user()->hasRole('Sourcing');

    $canCompleteAsSourcing = $isMe && ! $isDone;
    $canCompleteAsDataEntry = $isDone
        && $assignee->pivot->data_entry_completed_at === null
        && auth()->user()->hasAnyRole(['Data Entry', 'Admin']);
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="{{ $modalId }}-label">{{ $rfq->subject }}</h5>
                    <div class="text-muted-soft small">{{ $rfq->wc_number }} &middot; {{ $partLabel }}</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <h6 class="fw-bold small text-uppercase text-muted-soft">Description</h6>
                    @if ($rfq->description)
                        <p class="mb-0" style="white-space: pre-line;">{{ $rfq->description }}</p>
                    @else
                        <p class="text-muted-soft mb-0">No description provided.</p>
                    @endif
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold small text-uppercase text-muted-soft">Sourcing Assignee</h6>
                    <div class="rfq-de-sourcing-rows">
                        <div class="rfq-de-sourcing-row {{ $isDone ? 'is-completed' : '' }}">
                            <i class="bi {{ $isDone ? 'bi-check-circle-fill' : ($isReturned ? 'bi-arrow-counterclockwise' : 'bi-circle') }}"></i>
                            <span class="rfq-de-sourcing-row-name">{{ $assignee->name }}</span>
                            @if ($rfq->isSplit())
                                <span class="rfq-de-sourcing-row-number">{{ $partLabel }}</span>
                            @endif
                            <span class="rfq-de-sourcing-row-status">
                                {{ $isDone ? 'Completed' : ($isReturned ? 'Returned' : 'Pending') }}
                            </span>
                        </div>
                    </div>

                    @if ($isReturned)
                        <div class="rfq-list-subnote rfq-list-subnote-returned mt-2">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            {{ $assignee->pivot->return_reason }}
                        </div>
                    @endif
                </div>

                <hr>

                <h6 class="fw-bold small text-uppercase text-muted-soft mb-2">Comments</h6>

                {{-- A vertical timeline, animated in each time the modal opens. --}}
                @if ($visibleComments->isNotEmpty())
                    <div class="rfq-timeline rfq-thread">
                        @foreach ($visibleComments as $comment)
                            @include('admin.rfqs._modal_comment', ['comment' => $comment, 'rfq' => $rfq, 'index' => $loop->index, 'excludedAuthorIds' => $otherAssigneeIds])
                        @endforeach
                    </div>
                @else
                    <p class="text-muted-soft small mb-3">No comments yet.</p>
                @endif
            </div>
            <div class="modal-footer justify-content-between">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    @if ($canOpenFullRfq)
                        <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-arrows-fullscreen"></i> Open full RFQ
                        </a>
                    @endif
                </div>

                {{-- Bottom-right corner, last thing in the modal — kept out of the
                     way of the comment thread above it. Mark Complete and Return to
                     Sourcing each ask for a comment in the shared prompt, and Back
                     there comes back here. --}}
                @if ($canCompleteAsSourcing)
                    @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $part, 'backModal' => $modalId])
                @elseif ($canCompleteAsDataEntry)
                    {{-- Only this one part — see RfqController::completeDataEntry()
                         and returnSourcing(), which never touch any other part on the
                         same RFQ. --}}
                    <div class="d-flex gap-2">
                        @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $part, 'kind' => 'return', 'who' => $assignee->name, 'backModal' => $modalId, 'label' => 'Return to Sourcing'])
                        @include('admin.rfqs._complete_button', ['rfq' => $rfq, 'part' => $part, 'kind' => 'data_entry', 'who' => $assignee->name, 'backModal' => $modalId])
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
