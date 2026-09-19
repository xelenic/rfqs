{{--
    Quick-detail modal shared by Data Entry's "By Sourcing" list and
    Sourcing's own "My Pending RFQs" — one per (RFQ, Sourcing assignee)
    row, opened via .js-de-sourcing-row (see admin.js) when that row is
    clicked. Shows the subject, description, that one assignee's status,
    and a comment thread scoped to them — their own comments plus anyone
    else's who isn't one of the *other* Sourcing assignees on this RFQ, so
    one sourcer's split doesn't leak into another's. A simple box posts a
    new top-level comment. For replying or @mention autocomplete, "View"
    still opens the full RFQ page.

    Actions shown depend on who's looking: the assignee themselves gets
    Mark Complete (if not done yet); Data Entry/Admin get Return to
    Sourcing (if it's done).

    Expects: $rfq, $assignee (a member of $rfq->assignees — with
    comments.author, comments.replies.author eager-loaded on $rfq, see
    RfqController::index()).
--}}
@php
    $modalId = 'rfq-detail-modal-'.$rfq->id.'-'.$assignee->id;
    $isMe = $assignee->id === auth()->id();
    $isDone = $assignee->pivot->completed_at !== null;
    $isReturned = ! $isDone && $assignee->pivot->returned_at !== null;

    // Both the comment box and the return-to-sourcing box below flash the
    // same modal_rfq_id/modal_assignee_id hidden fields (so a failure
    // reopens this exact modal) — the named error bag is what tells them
    // apart, so a failed return doesn't light up the comment box and vice
    // versa.
    $modalIdsMatch = old('modal_rfq_id') && old('modal_assignee_id')
        && (string) old('modal_rfq_id') === (string) $rfq->id
        && (string) old('modal_assignee_id') === (string) $assignee->id;
    $isFailedComment = $modalIdsMatch && $errors->comment->any();
    $isFailedReturn = $modalIdsMatch && $errors->return->any();

    // Everything except comments from the *other* Sourcing assignees on
    // this RFQ — this assignee's own comments and anyone else's (Data
    // Entry, Operations, etc.) still show, only cross-sourcer chatter is
    // hidden.
    $otherAssigneeIds = $rfq->assignees->where('id', '!=', $assignee->id)->pluck('id');
    $visibleComments = $rfq->comments->whereNotIn('user_id', $otherAssigneeIds);

    // Sourcing works entirely from this modal on "My Pending RFQs" — no
    // need for an escape hatch to the full RFQ page there. Data Entry/Admin
    // still get it from "By Sourcing".
    $canOpenFullRfq = ! auth()->user()->hasRole('Sourcing');
@endphp

<div class="modal fade" id="{{ $modalId }}" tabindex="-1" aria-labelledby="{{ $modalId }}-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="{{ $modalId }}-label">{{ $rfq->subject }}</h5>
                    <div class="text-muted-soft small">{{ $rfq->wc_number }} &middot; {{ $rfq->sourcingSplitNumberFor($assignee) }}</div>
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
                                <span class="rfq-de-sourcing-row-number">{{ $rfq->sourcingSplitNumberFor($assignee) }}</span>
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

                    @if ($isDone && auth()->user()->hasAnyRole(['Data Entry', 'Admin']) && ! $rfq->hasDataEntryCompletedPart($assignee))
                        {{-- Only this assignee's split — see
                             RfqController::completeDataEntry(), which never touches any
                             other assignee on the same RFQ. --}}
                        <form action="{{ route('admin.rfqs.complete-data-entry', $rfq) }}" method="POST" class="mt-2"
                              data-confirm="Mark {{ $assignee->name }}'s part complete?">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="assignee_id" value="{{ $assignee->id }}">
                            <button type="submit" class="btn btn-sm btn-success">
                                <i class="bi bi-check2-circle"></i> Mark Complete
                            </button>
                        </form>

                        <button type="button" class="btn btn-sm btn-outline-danger mt-2" data-bs-toggle="collapse" data-bs-target="#{{ $modalId }}-return">
                            <i class="bi bi-arrow-counterclockwise"></i> Return to Sourcing
                        </button>

                        <div class="collapse {{ $isFailedReturn ? 'show' : '' }} mt-2" id="{{ $modalId }}-return">
                            <form action="{{ route('admin.rfqs.return-sourcing', $rfq) }}" method="POST" class="rfq-return-form"
                                  data-confirm="Send {{ $assignee->name }}'s part back to Sourcing for rework?">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="assignee_id" value="{{ $assignee->id }}">
                                <input type="hidden" name="modal_rfq_id" value="{{ $rfq->id }}">
                                <input type="hidden" name="modal_assignee_id" value="{{ $assignee->id }}">
                                <textarea name="reason" rows="2" class="form-control form-control-sm @error('reason', 'return') is-invalid @enderror">{{ $isFailedReturn ? old('reason') : '' }}</textarea>
                                @if ($isFailedReturn)
                                    @error('reason', 'return')
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                @endif
                                <button type="submit" class="btn btn-sm btn-danger mt-2">
                                    Send back to {{ $assignee->name }}
                                </button>
                            </form>
                        </div>
                    @endif
                </div>

                <hr>

                <h6 class="fw-bold small text-uppercase text-muted-soft mb-2">Comments</h6>

                @forelse ($visibleComments as $comment)
                    @include('admin.rfqs._modal_comment', ['comment' => $comment, 'rfq' => $rfq, 'excludedAuthorIds' => $otherAssigneeIds])
                @empty
                    <p class="text-muted-soft small mb-3">No comments yet.</p>
                @endforelse
            </div>
            <div class="modal-footer flex-column align-items-stretch gap-2">
                <form action="{{ route('admin.rfqs.comments.store', $rfq) }}" method="POST" class="w-100">
                    @csrf
                    <input type="hidden" name="modal_rfq_id" value="{{ $rfq->id }}">
                    <input type="hidden" name="modal_assignee_id" value="{{ $assignee->id }}">
                    <div class="mb-2">
                        <textarea name="body" rows="2" class="form-control @error('body', 'comment') is-invalid @enderror"
                                  placeholder="Write a comment...">{{ $isFailedComment ? old('body') : '' }}</textarea>
                        @if ($isFailedComment)
                            @error('body', 'comment')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        @endif
                    </div>
                    <div class="d-flex {{ $canOpenFullRfq ? 'justify-content-between' : 'justify-content-end' }} align-items-center">
                        @if ($canOpenFullRfq)
                            <a href="{{ route('admin.rfqs.show', $rfq) }}?status=Pending" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-arrows-fullscreen"></i> Open full RFQ
                            </a>
                        @endif
                        <button type="submit" class="btn btn-sm btn-primary">Post comment</button>
                    </div>
                </form>

                @if ($isMe && ! $isDone)
                    {{-- Bottom-right corner, last thing in the modal — kept out
                         of the way of the comment thread above it. --}}
                    <form action="{{ route('admin.rfqs.complete-sourcing', $rfq) }}" method="POST" class="d-flex justify-content-end w-100"
                          data-confirm="{{ $rfq->isSplit() ? 'Mark your part of this split RFQ complete? It only hands off to Data Entry once every assignee has completed theirs.' : 'Mark your Sourcing work done and hand this RFQ off to Data Entry?' }}">
                        @csrf
                        @method('PATCH')
                        {{-- This modal only ever appears on the Pending-filtered list —
                             without this, the redirect back loses that filter (see
                             RfqController::redirectToIndex()) and the row/modal the
                             user just used would vanish from the page they land on. --}}
                        <input type="hidden" name="redirect_status" value="Pending">
                        <button type="submit" class="btn btn-sm btn-success">
                            <i class="bi bi-check2-circle"></i> Mark Complete
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
