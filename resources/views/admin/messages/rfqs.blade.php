{{--
    Messages → RFQ comments (RfqDiscussionController::index()): each RFQ
    discussed with the signed-in user, the latest comment first — its number,
    subject and status, who said the latest and what, when, how many comments
    it has and how many are unread for them — leading to its thread.

    Expects: $rfqs (paginated, with last_comment_id, comment_total),
    $lastComments (id => RfqComment, with author), $unread (rfq id => count).
--}}
@extends('layouts.app')

@section('title', 'RFQ comments')

@section('content')
    @include('admin.messages._tabs', ['tab' => 'rfqs'])

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>RFQ comments</span>
            <span class="text-muted-soft small fw-normal">The discussion on the RFQs you're part of — one thread for each</span>
        </div>

        @forelse ($rfqs as $rfq)
            @php
                $last = $lastComments->get($rfq->last_comment_id);
                $unreadCount = $unread[$rfq->id] ?? 0;
            @endphp
            <a href="{{ route('admin.messages.rfq', $rfq) }}" class="conversation-row {{ $unreadCount > 0 ? 'is-unread' : '' }}" data-rfq-thread="{{ $rfq->id }}">
                <span class="assignee-avatar conversation-avatar rfq-thread-icon" aria-hidden="true"><i class="bi bi-chat-square-text"></i></span>
                <span class="conversation-body">
                    <span class="conversation-top">
                        <span class="conversation-name">{{ $rfq->rfq_number }}</span>
                        <span class="text-muted-soft small text-truncate">{{ $rfq->subject }}</span>
                        <span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->statusLabel() }}</span>
                    </span>
                    @if ($last)
                        <span class="conversation-preview">
                            <span class="text-muted-soft">{{ $last->user_id === auth()->id() ? 'You' : ($last->author?->name ?? 'Someone') }}:</span>
                            {{ \Illuminate\Support\Str::limit($last->body, 110) }}
                        </span>
                    @endif
                </span>
                <span class="conversation-meta">
                    @if ($last)
                        <span class="text-muted-soft small">{{ $last->created_at->toBusinessTimezone()->format('M d, Y g:i A') }}</span>
                    @endif
                    <span class="d-flex align-items-center gap-2">
                        <span class="text-muted-soft small"><i class="bi bi-chat"></i> {{ $rfq->comment_total }}</span>
                        @if ($unreadCount > 0)
                            <span class="nav-link-count" title="{{ $unreadCount }} unread">{{ $unreadCount }}</span>
                        @endif
                    </span>
                </span>
            </a>
        @empty
            <div class="card-body text-center py-5">
                <i class="bi bi-chat-square-text conversation-empty-icon"></i>
                <p class="fw-semibold mb-1">No RFQ comments yet</p>
                <p class="text-muted-soft small mb-0">When someone comments on an RFQ you're part of, its thread shows here.</p>
            </div>
        @endforelse

        @if ($rfqs->hasPages())
            <div class="card-footer bg-white">{{ $rfqs->links() }}</div>
        @endif
    </div>
@endsection
