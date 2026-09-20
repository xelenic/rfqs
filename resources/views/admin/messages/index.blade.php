@extends('layouts.app')

@section('title', 'Messages')

@section('content')
    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span>Private messages</span>
            <span class="text-muted-soft small fw-normal">Only you and the other person can read these</span>
        </div>

        @forelse ($conversations as $last)
            @php
                $other = $last->sender_id === auth()->id() ? $last->recipient : $last->sender;
                $unreadCount = $unread[$other->id] ?? 0;
                $roleNames = $other->roles->pluck('name')->implode(', ');
            @endphp
            <a href="{{ route('admin.messages.show', $other) }}" class="conversation-row {{ $unreadCount > 0 ? 'is-unread' : '' }}">
                <span class="assignee-avatar conversation-avatar">{{ strtoupper(substr($other->name, 0, 1)) }}</span>
                <span class="conversation-body">
                    <span class="conversation-top">
                        <span class="conversation-name">{{ $other->name }}</span>
                        @if ($roleNames !== '')
                            <span class="badge badge-soft-secondary rfq-comment-role">{{ $roleNames }}</span>
                        @endif
                    </span>
                    <span class="conversation-preview">
                        @if ($last->sender_id === auth()->id())
                            <span class="text-muted-soft">You:</span>
                        @endif
                        {{ \Illuminate\Support\Str::limit($last->body, 110) }}
                    </span>
                </span>
                <span class="conversation-meta">
                    <span class="text-muted-soft small">{{ $last->created_at->diffForHumans() }}</span>
                    @if ($unreadCount > 0)
                        <span class="nav-link-count" title="{{ $unreadCount }} unread">{{ $unreadCount }}</span>
                    @endif
                </span>
            </a>
        @empty
            <div class="card-body text-center py-5">
                <i class="bi bi-chat-dots conversation-empty-icon"></i>
                <p class="fw-semibold mb-1">No messages yet</p>
                <p class="text-muted-soft small mb-0">Hover a person's name on a comment and choose Private message to start one.</p>
            </div>
        @endforelse
    </div>
@endsection
