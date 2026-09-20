{{--
    What a comment posted along with an action records, as small chips beside
    the author's name: what was done — "Marked complete", "Returned to
    Sourcing" — and what it was about — whose part, which one, where it went.
    Nothing for an ordinary comment. See RfqComment::ACTIONS.

    Expects: $comment.
--}}
@if ($comment->actionLabel())
    <span class="comment-action comment-action-{{ $comment->actionTone() }}">
        <i class="bi {{ $comment->actionIcon() }}"></i> {{ $comment->actionLabel() }}
    </span>
    @if ($comment->actionContext())
        <span class="comment-action-context">{{ $comment->actionContext() }}</span>
    @endif
@endif
