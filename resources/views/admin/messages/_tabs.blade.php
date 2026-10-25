{{--
    Messages' two lists: Private messages — conversations person to person
    (MessageController) — and RFQ comments — one thread per RFQ discussed
    with the signed-in user (RfqDiscussionController). Each with its unread
    count: messages written in Messages, and comments sent to them.

    Expects: $tab ('private' or 'rfqs').
--}}
@php
    $unreadPrivate = auth()->user()->receivedMessages()->unread()->whereNull('rfq_comment_id')->count();
    $unreadComments = auth()->user()->receivedMessages()->unread()->whereNotNull('rfq_comment_id')->count();
@endphp
<ul class="nav nav-pills rfq-view-toggle mb-3">
    <li class="nav-item">
        <a href="{{ route('admin.messages.index') }}" class="nav-link {{ $tab === 'private' ? 'active' : '' }}" @if ($tab === 'private') aria-current="page" @endif>
            <i class="bi bi-chat-dots"></i> Private messages
            @if ($unreadPrivate > 0)
                <span class="rfq-tab-count" title="{{ $unreadPrivate }} unread">{{ $unreadPrivate }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item">
        <a href="{{ route('admin.messages.rfqs') }}" class="nav-link {{ $tab === 'rfqs' ? 'active' : '' }}" @if ($tab === 'rfqs') aria-current="page" @endif>
            <i class="bi bi-chat-square-text"></i> RFQ comments
            @if ($unreadComments > 0)
                <span class="rfq-tab-count" title="{{ $unreadComments }} unread">{{ $unreadComments }}</span>
            @endif
        </a>
    </li>
</ul>
