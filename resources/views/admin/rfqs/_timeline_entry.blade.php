{{--
    One entry in the RFQ activity timeline — either a read-only lifecycle
    event (created, assigned, completed, returned, handed off, closed) or a
    comment/reply, which keeps its full reply/delete/@mention interactivity.

    Expects: $entry (one element of Rfq::activityTimeline()), $rfq,
    $restrictSourcingView, $restrictAssignment, $sourcingUsers,
    $failedReplyParentId.
--}}
@php
    $isOtherSourcingPartner = $restrictSourcingView
        && $entry['related']
        && $entry['related']->id !== auth()->id();

    [$icon, $variant, $title] = match ($entry['type']) {
        'created' => ['bi-file-earmark-plus', 'primary', 'RFQ Created'],
        'operations_assigned' => ['bi-diagram-2', 'primary', 'Assigned by Operations'],
        'sourcing_assigned' => ['bi-person-check', 'secondary', 'Assigned to Sourcing'],
        'sourcing_completed' => ['bi-check-circle-fill', 'success', 'Marked Complete by Sourcing'],
        'sourcing_returned' => ['bi-arrow-counterclockwise', 'danger', 'Returned to Sourcing'],
        'sourcing_handoff' => ['bi-clipboard-check', 'info', 'Handed off to Data Entry'],
        'data_entry_part_completed' => ['bi-check2-circle', 'success', 'Marked Complete by Data Entry'],
        'data_entry_all_completed' => ['bi-clipboard-check', 'success', 'All Splits Completed by Data Entry'],
        'senior_ops_part_reviewed' => ['bi-clipboard2-check', 'success', 'Part approved by Senior Operations'],
        'head_of_bd_part_approved' => ['bi-check-circle-fill', 'success', 'Part approved by Head of Business Development'],
        'gm_assistant_part_completed' => ['bi-file-earmark-text', 'info', 'Part details added by GM Assistant'],
        'gm_part_approved' => ['bi-award', 'success', 'Part approved by General Manager'],
        'bd_part_closed' => ['bi-flag-fill', 'success', 'Part closed by Business Development'],
        'category_set' => ['bi-tag', 'secondary', 'Category Set'],
        'senior_ops_reviewed' => ['bi-clipboard2-check', 'success', 'Approved by Senior Operations (2nd review)'],
        'head_of_bd_approved' => ['bi-check-circle-fill', 'success', 'Approved by Head of Business Development'],
        'head_of_bd_rejected' => ['bi-arrow-counterclockwise', 'danger', 'Rejected by Head of Business Development'],
        'gm_assistant_completed' => ['bi-file-earmark-text', 'info', 'Client Details Added'],
        'gm_approved' => ['bi-award', 'success', 'Approved by General Manager'],
        'bd_closed' => ['bi-flag-fill', 'success', 'RFQ Closed'],
        'comment' => [$entry['comment']->actionIcon(), $entry['comment']->action ? $entry['comment']->actionTone() : 'comment', $entry['comment']->parent_id ? 'Reply' : 'Comment'],
        default => ['bi-dot', 'comment', ''],
    };

    // head_of_bd_rejected's detail already reads "Returned to X: reason" —
    // give it the same visual weight as sourcing_returned's reason.
    $detailClass = $entry['type'] === 'head_of_bd_rejected' ? 'text-danger' : 'text-muted-soft';

    // Same blur rules as the Progress card above: Sourcing never sees who
    // created or routed the RFQ (unconditional — that's never "you"), and
    // never sees another Sourcing partner's identity (only their own).
    $shouldBlur = match ($entry['type']) {
        'created', 'operations_assigned' => $restrictSourcingView,
        'sourcing_assigned', 'sourcing_completed', 'sourcing_handoff' => $isOtherSourcingPartner,
        default => false,
    };

    // Sourcing never learns exactly who in Data Entry touched a split —
    // same as the return-reason banner elsewhere, which names the role,
    // not the person.
    $actorName = match (true) {
        in_array($entry['type'], ['sourcing_returned', 'data_entry_part_completed'], true) && $restrictSourcingView => 'Data Entry',
        $entry['actor'] !== null => $entry['actor']->name,
        default => 'Unknown',
    };
@endphp

<div class="rfq-timeline-item rfq-timeline-item-{{ $variant }}">
    <div class="rfq-timeline-marker">
        <i class="bi {{ $icon }}"></i>
    </div>

    <div class="rfq-timeline-content">
        @if ($entry['type'] === 'comment')
            @php $comment = $entry['comment']; @endphp
            <div class="rfq-comment">
                <span class="assignee-avatar">{{ strtoupper(substr($comment->author->name ?? '?', 0, 1)) }}</span>
                <div class="rfq-comment-body">
                    <div class="rfq-comment-meta">
                        @include('admin.rfqs._comment_author', ['author' => $comment->author])
                        @include('admin.rfqs._comment_action', ['comment' => $comment])
                        <span class="text-muted-soft">{{ $comment->created_at->diffForHumans() }}</span>
                    </div>
                    @if ($entry['detail'])
                        <div class="text-muted-soft small mb-1">
                            <i class="bi bi-reply-fill"></i> {{ $entry['detail'] }}
                        </div>
                    @endif
                    <p class="mb-1" style="white-space: pre-line;">{!! $comment->bodyWithMentions($sourcingUsers) !!}</p>

                    @unless ($restrictAssignment)
                        @if (! $comment->parent_id)
                            @php $isFailedReply = $failedReplyParentId && (string) $failedReplyParentId === (string) $comment->id; @endphp
                            <button type="button" class="btn btn-sm btn-link p-0 rfq-comment-reply-toggle"
                                    data-bs-toggle="collapse" data-bs-target="#reply-form-{{ $comment->id }}">
                                <i class="bi bi-reply"></i> Reply
                            </button>

                            <div class="collapse {{ $isFailedReply ? 'show' : '' }} mt-2" id="reply-form-{{ $comment->id }}">
                                <form action="{{ route('admin.rfqs.comments.store', $rfq) }}" method="POST" class="rfq-comment-reply-form">
                                    @csrf
                                    <input type="hidden" name="parent_id" value="{{ $comment->id }}">
                                    <textarea name="body" rows="1" class="form-control form-control-sm js-mention-input @error('body', 'reply') is-invalid @enderror"
                                              placeholder="Write a reply... (@ to mention Sourcing)">{{ $isFailedReply ? old('body') : '' }}</textarea>
                                    @if ($isFailedReply)
                                        @error('body', 'reply')
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    @endif
                                    <button type="submit" class="btn btn-sm btn-primary mt-1">Reply</button>
                                </form>
                            </div>
                        @endif
                    @endunless
                </div>
                @if ($comment->user_id === auth()->id() || auth()->user()->can('rfqs.delete'))
                    <form action="{{ route('admin.rfqs.comments.destroy', [$rfq, $comment]) }}" method="POST"
                          data-confirm="{{ $comment->parent_id ? 'Delete this reply?' : 'Delete this comment? Any replies go with it.' }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="{{ $comment->parent_id ? 'Delete reply' : 'Delete comment' }}">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                @endif
            </div>
        @elseif ($entry['type'] === 'sourcing_returned')
            <div class="rfq-timeline-title">{{ $title }}</div>
            <div class="rfq-timeline-meta">
                <span class="fw-semibold {{ $isOtherSourcingPartner ? 'rfq-blurred' : '' }}">{{ $entry['related']->name }}</span>'s part
                &middot; sent back by {{ $actorName }}
                &middot; {{ $entry['at']->format('M d, Y g:i A') }}
            </div>
            @if ($entry['detail'])
                <div class="rfq-timeline-detail text-danger">{{ $entry['detail'] }}</div>
            @endif
        @elseif ($entry['type'] === 'data_entry_part_completed')
            <div class="rfq-timeline-title">{{ $title }}</div>
            <div class="rfq-timeline-meta">
                <span class="fw-semibold {{ $isOtherSourcingPartner ? 'rfq-blurred' : '' }}">{{ $entry['related']->name }}</span>'s part
                &middot; completed by {{ $actorName }}
                &middot; {{ $entry['at']->format('M d, Y g:i A') }}
            </div>
            @if ($entry['detail'])
                <div class="rfq-timeline-detail text-muted-soft">{{ $entry['detail'] }}</div>
            @endif
        @else
            <div class="rfq-timeline-title">{{ $title }}</div>
            <div class="rfq-timeline-meta {{ $shouldBlur ? 'rfq-blurred' : '' }}">
                <span class="fw-semibold">{{ $actorName }}</span>
                &middot; {{ $entry['at']->format('M d, Y g:i A') }}
            </div>
            @if ($entry['detail'])
                <div class="rfq-timeline-detail {{ $detailClass }}">{{ $entry['detail'] }}</div>
            @endif
        @endif
    </div>
</div>
