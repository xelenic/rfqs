{{--
    Read-only rendering of one comment (and its replies) for the quick-detail
    modal's thread — no reply/delete controls here, that still lives on the
    full RFQ page. Each comment is a stop on a vertical timeline: a marker
    (a speech bubble, or the icon of what was done — marked complete, sent
    back) on the line, and beside it the author's name, their role, the action
    it went with and when, then what they wrote. The thread animates in when
    the modal opens (see .rfq-thread in admin.css); $index is its place in it,
    which sets when.

    Each name shows the person's role and, on hover, a card of their details
    with a private-message button (_comment_author.blade.php).

    Expects: $comment, $rfq (for mention highlighting against its Sourcing
    team), $index, $excludedAuthorIds (the *other* Sourcing assignees' ids —
    their replies are filtered out here too, same as top-level comments).
--}}
@php
    $visibleReplies = $comment->replies->whereNotIn('user_id', $excludedAuthorIds ?? []);
@endphp

<div class="rfq-timeline-item rfq-timeline-item-{{ $comment->actionTone() }} rfq-thread-item" style="--i: {{ $index ?? 0 }}">
    <div class="rfq-timeline-marker">
        <i class="bi {{ $comment->actionIcon() }}"></i>
    </div>

    <div class="rfq-timeline-content rfq-thread-content">
        <div class="rfq-comment-meta">
            @include('admin.rfqs._comment_author', ['author' => $comment->author])
            @include('admin.rfqs._comment_action', ['comment' => $comment])
            <span class="text-muted-soft rfq-comment-time" title="{{ $comment->created_at->format('M d, Y g:i A') }}">{{ $comment->created_at->diffForHumans() }}</span>
        </div>
        <div class="rfq-thread-bubble">
            <p class="mb-0" style="white-space: pre-line;">{!! $comment->bodyWithMentions($rfq->assignees) !!}</p>
        </div>

        @if ($visibleReplies->isNotEmpty())
            <div class="rfq-thread-replies">
                @foreach ($visibleReplies as $reply)
                    <div class="rfq-thread-reply">
                        <div class="rfq-comment-meta">
                            @include('admin.rfqs._comment_author', ['author' => $reply->author])
                            <span class="text-muted-soft rfq-comment-time" title="{{ $reply->created_at->format('M d, Y g:i A') }}">{{ $reply->created_at->diffForHumans() }}</span>
                        </div>
                        <div class="rfq-thread-bubble">
                            <p class="mb-0" style="white-space: pre-line;">{!! $reply->bodyWithMentions($rfq->assignees) !!}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
