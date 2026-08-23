{{--
    Read-only rendering of one comment (and its replies) for the "By
    Sourcing" quick-detail modal — no reply/delete controls here, that
    still lives on the full RFQ page ("Open full RFQ" below the thread).

    Expects: $comment, $rfq (for mention highlighting against its Sourcing
    team), $excludedAuthorIds (the *other* Sourcing assignees' ids — their
    replies are filtered out here too, same as top-level comments).
--}}
@php
    $visibleReplies = $comment->replies->whereNotIn('user_id', $excludedAuthorIds ?? []);
@endphp

<div class="rfq-comment">
    <span class="assignee-avatar">{{ strtoupper(substr($comment->author->name ?? '?', 0, 1)) }}</span>
    <div class="rfq-comment-body">
        <div class="rfq-comment-meta">
            <span class="fw-semibold">{{ $comment->author->name ?? 'Deleted user' }}</span>
            <span class="text-muted-soft">{{ $comment->created_at->diffForHumans() }}</span>
        </div>
        <p class="mb-0" style="white-space: pre-line;">{!! $comment->bodyWithMentions($rfq->assignees) !!}</p>

        @if ($visibleReplies->isNotEmpty())
            <div class="rfq-comment-replies">
                @foreach ($visibleReplies as $reply)
                    <div class="rfq-comment rfq-comment-reply">
                        <span class="assignee-avatar">{{ strtoupper(substr($reply->author->name ?? '?', 0, 1)) }}</span>
                        <div class="rfq-comment-body">
                            <div class="rfq-comment-meta">
                                <span class="fw-semibold">{{ $reply->author->name ?? 'Deleted user' }}</span>
                                <span class="text-muted-soft">{{ $reply->created_at->diffForHumans() }}</span>
                            </div>
                            <p class="mb-0" style="white-space: pre-line;">{!! $reply->bodyWithMentions($rfq->assignees) !!}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
