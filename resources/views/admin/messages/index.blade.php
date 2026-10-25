{{--
    Messages → Private messages (MessageController::index()): everyone the
    signed-in user has talked to, the latest first, and New message
    (#newMessageModal) to start a conversation with anyone else active —
    picked from $people, searched by name or role — or carry one on. A
    refused send reopens the popup as it was (new_conversation).

    Expects: $conversations, $unread (sender id => count), $people (those
    they may write to — User::canMessage(), Settings → Messaging),
    $messagingLimit (who that is, when their role is limited, else null).
--}}
@extends('layouts.app')

@section('title', 'Messages')

@section('content')
    @php $failedNew = old('new_conversation') && ($errors->has('recipient_id') || $errors->has('body')); @endphp

    @include('admin.messages._tabs', ['tab' => 'private'])

    <div class="card">
        <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span>Private messages</span>
            <span class="d-flex align-items-center gap-3">
                <span class="text-muted-soft small fw-normal">Only you and the other person can read these</span>
                <button type="button" class="btn btn-sm btn-primary text-nowrap" data-bs-toggle="modal" data-bs-target="#newMessageModal">
                    <i class="bi bi-pencil-square"></i> New message
                </button>
            </span>
        </div>

        @forelse ($conversations as $last)
            @php
                $other = $last->sender_id === auth()->id() ? $last->recipient : $last->sender;
                $unreadCount = $unread[$other->id] ?? 0;
                $roleNames = $other->roles->pluck('name')->implode(', ');
            @endphp
            <a href="{{ route('admin.messages.show', $other) }}" class="conversation-row {{ $unreadCount > 0 ? 'is-unread' : '' }}">
                <x-avatar :user="$other" class="assignee-avatar conversation-avatar" />
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
                    <span class="text-muted-soft small">{{ $last->created_at->toBusinessTimezone()->format('M d, Y g:i A') }}</span>
                    @if ($unreadCount > 0)
                        <span class="nav-link-count" title="{{ $unreadCount }} unread">{{ $unreadCount }}</span>
                    @endif
                </span>
            </a>
        @empty
            <div class="card-body text-center py-5">
                <i class="bi bi-chat-dots conversation-empty-icon"></i>
                <p class="fw-semibold mb-1">No messages yet</p>
                <p class="text-muted-soft small mb-0">Start one with New message — or hover a person's name on a comment and choose Private message.</p>
            </div>
        @endforelse
    </div>

    {{-- Start a conversation — or carry one on — with anyone active. --}}
    <div class="modal fade" id="newMessageModal" tabindex="-1" aria-labelledby="newMessageModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <form method="POST" action="{{ route('admin.messages.store') }}" class="modal-content" novalidate>
                @csrf
                <input type="hidden" name="new_conversation" value="1">
                <div class="modal-header">
                    <h5 class="modal-title" id="newMessageModalLabel">New message</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    @if ($messagingLimit)
                        <p class="small text-muted-soft mb-2" data-messaging-limit><i class="bi bi-info-circle"></i> {{ $messagingLimit }}</p>
                    @endif
                    <label class="form-label" for="new-message-search">To</label>
                    <input type="search" id="new-message-search" class="form-control form-control-sm mb-2 js-people-filter" placeholder="Search by name or role…" autocomplete="off">
                    <div class="new-message-people @error('recipient_id') is-invalid @enderror" role="radiogroup" aria-label="Who to send it to">
                        @foreach ($people as $person)
                            @php $personRoles = $person->roles->pluck('name')->sort()->implode(', '); @endphp
                            <label class="new-message-person" data-person="{{ $person->id }}" data-search="{{ mb_strtolower($person->name.' '.$personRoles) }}">
                                <input type="radio" name="recipient_id" value="{{ $person->id }}" class="form-check-input" @checked($failedNew && (string) old('recipient_id') === (string) $person->id)>
                                <x-avatar :user="$person" class="assignee-avatar" />
                                <span class="new-message-person-name">{{ $person->name }}</span>
                                @if ($personRoles !== '')
                                    <span class="badge badge-soft-secondary rfq-comment-role">{{ $personRoles }}</span>
                                @endif
                            </label>
                        @endforeach
                        <p class="text-muted-soft small text-center my-3 {{ $people->isEmpty() ? '' : 'd-none' }} js-people-none">{{ $people->isEmpty() ? 'There\'s no one else to write to yet.' : 'No one matches that.' }}</p>
                    </div>
                    @error('recipient_id')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror

                    <label class="form-label mt-3" for="new-message-body">Message</label>
                    <textarea name="body" id="new-message-body" rows="3" maxlength="{{ \App\Models\PrivateMessage::MAX_LENGTH }}"
                              class="form-control @if ($failedNew && $errors->has('body')) is-invalid @endif"
                              placeholder="Write your message…">{{ $failedNew ? old('body') : '' }}</textarea>
                    @if ($failedNew && $errors->has('body'))
                        <div class="invalid-feedback">{{ $errors->first('body') }}</div>
                    @endif
                    <div class="form-text"><i class="bi bi-lock"></i> Only you and the person you pick can read it.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Send</button>
                </div>
            </form>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        @if ($failedNew)
            // A refused send: back in the popup, as it was sent.
            document.addEventListener('DOMContentLoaded', function () {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('newMessageModal')).show();
            });
        @endif
    </script>
@endpush
