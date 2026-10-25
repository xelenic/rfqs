{{--
    One RFQ's comments in Messages (RfqDiscussionController::show()): its
    comments and replies, oldest first, as a conversation — whose, with
    their role and a hover card (_comment_author), what they replied to,
    @mentions picked out, photos, and when — with the way to the RFQ. The
    box below posts a comment on the RFQ (RfqCommentController::store()),
    which everyone on it gets in Messages; Reply on one makes it a reply to
    that comment's thread (parent_id). @ mentions anyone, from the list in
    #rfq-mention-users, as on the RFQ's page.

    Expects: $rfq, $comments (with author.roles, attachments, parent.author),
    $canPost (false for Business Development, who reads only what they
    wrote or were sent).
--}}
@extends('layouts.app')

@section('title', $rfq->rfq_number.' comments')

@section('content')
    @php
        $mentionable = \App\Models\RfqComment::mentionableUsers();
        $replyingTo = old('parent_id') ? $comments->firstWhere('id', (int) old('parent_id')) : null;
    @endphp

    <div class="mb-3">
        <a href="{{ route('admin.messages.rfqs') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> All RFQ comments
        </a>
    </div>

    <div class="card message-thread">
        <div class="card-header d-flex align-items-center gap-3 flex-wrap">
            <span class="assignee-avatar conversation-avatar rfq-thread-icon" aria-hidden="true"><i class="bi bi-chat-square-text"></i></span>
            <div style="min-width: 0;">
                <div class="fw-bold d-flex align-items-center gap-2 flex-wrap">
                    {{ $rfq->rfq_number }}
                    <span class="badge {{ $rfq->priorityBadgeClass() }}">{{ $rfq->priorityLabel() }}</span>
                    <span class="badge {{ $rfq->statusBadgeClass() }}">{{ $rfq->statusLabel() }}</span>
                </div>
                <div class="text-muted-soft small fw-normal">{{ $rfq->subject }}{{ $rfq->wc_number ? ' · '.$rfq->wc_number : '' }}</div>
            </div>
            <a href="{{ route('admin.rfqs.show', $rfq) }}" class="btn btn-sm btn-outline-primary ms-auto">
                <i class="bi bi-box-arrow-up-right"></i> Open RFQ
            </a>
        </div>

        <div class="card-body message-thread-body" id="messageThread" data-live-scroll="rfq-thread">
            @forelse ($comments as $comment)
                @php $mine = $comment->user_id === auth()->id(); @endphp
                <div class="message-bubble-row {{ $mine ? 'is-mine' : '' }}" id="comment-{{ $comment->id }}" data-thread-comment="{{ $comment->id }}">
                    <div class="message-bubble">
                        <div class="rfq-thread-author">
                            @if ($mine)
                                <span class="fw-semibold">You</span>
                            @else
                                @include('admin.rfqs._comment_author', ['author' => $comment->author])
                            @endif
                        </div>
                        @if ($comment->parent)
                            <div class="rfq-thread-replied">
                                <i class="bi bi-reply-fill"></i> To {{ $comment->parent->user_id === auth()->id() ? 'you' : ($comment->parent->author?->name ?? 'a comment') }}: “{{ \Illuminate\Support\Str::limit($comment->parent->body, 70) }}”
                            </div>
                        @endif
                        <p class="mb-1" style="white-space: pre-line;">{!! $comment->bodyWithMentions($mentionable) !!}</p>
                        @include('admin.rfqs._comment_attachments', ['comment' => $comment])
                        <div class="message-time">
                            {{ $comment->created_at->toBusinessTimezone()->format('M d, g:i A') }}
                            @if ($canPost)
                                · <button type="button" class="btn btn-link p-0 align-baseline rfq-thread-reply js-thread-reply"
                                          data-parent="{{ $comment->parent_id ?? $comment->id }}"
                                          data-name="{{ $mine ? 'your comment' : ($comment->author?->name ?? 'this comment') }}">Reply</button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted-soft text-center my-4">No comments on {{ $rfq->rfq_number }} yet{{ $canPost ? ' — start the discussion below.' : '.' }}</p>
            @endforelse
        </div>

        @if ($canPost)
            <div class="card-footer bg-white">
                <form method="POST" action="{{ route('admin.rfqs.comments.store', $rfq) }}" enctype="multipart/form-data" id="rfqThreadForm" novalidate>
                    @csrf
                    <input type="hidden" name="parent_id" value="{{ $replyingTo?->id }}" id="rfqThreadParent">
                    <div class="small text-muted-soft mb-1 {{ $replyingTo ? '' : 'd-none' }}" id="rfqThreadReplying">
                        <i class="bi bi-reply-fill"></i> Replying to <span class="fw-semibold" id="rfqThreadReplyingTo">{{ $replyingTo?->author?->name }}</span>
                        · <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="rfqThreadReplyCancel">Cancel</button>
                    </div>
                    <textarea name="body" rows="2" maxlength="2000"
                              class="form-control js-mention-input @if ($errors->comment->has('body') || $errors->reply->has('body')) is-invalid @endif"
                              placeholder="Write a comment on {{ $rfq->rfq_number }}… (everyone on this RFQ gets it in Messages — @ to mention someone else)">{{ old('body') }}</textarea>
                    @if ($errors->comment->has('body') || $errors->reply->has('body'))
                        <div class="invalid-feedback d-block">{{ $errors->comment->first('body') ?: $errors->reply->first('body') }}</div>
                    @endif
                    @include('admin.rfqs._attachments_input', ['attachmentsId' => 'rfq-thread-attachments'])
                    <div class="d-flex justify-content-between align-items-center gap-2 mt-2 flex-wrap">
                        <span class="text-muted-soft small"><i class="bi bi-people"></i> Posted on the RFQ — everyone on it sees it.</span>
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-send"></i> Post</button>
                    </div>
                </form>
            </div>
        @else
            <div class="card-footer bg-white small text-muted-soft">
                <i class="bi bi-eye"></i> These are the comments you wrote or were sent. Answer someone with a private message from their name.
            </div>
        @endif
    </div>

    {{-- Who can be @mentioned, for the dropdown in admin.js (RfqComment::mentionDirectory()). --}}
    <script type="application/json" id="rfq-mention-users">{!! json_encode(\App\Models\RfqComment::mentionDirectory(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endsection

@push('scripts')
    <script>
        // Start at the latest comment.
        var thread = document.getElementById('messageThread');
        if (thread) thread.scrollTop = thread.scrollHeight;

        // Reply on a comment: the box below answers its thread (parent_id) until cancelled.
        if (!window.__rfqmsThreadReply) document.addEventListener('click', function (event) {
            var form = document.getElementById('rfqThreadForm');
            if (!form) return;

            var reply = event.target.closest('.js-thread-reply');
            if (reply) {
                document.getElementById('rfqThreadParent').value = reply.dataset.parent;
                document.getElementById('rfqThreadReplyingTo').textContent = reply.dataset.name;
                document.getElementById('rfqThreadReplying').classList.remove('d-none');
                form.querySelector('textarea[name="body"]').focus();
                return;
            }

            if (event.target.closest('#rfqThreadReplyCancel')) {
                document.getElementById('rfqThreadParent').value = '';
                document.getElementById('rfqThreadReplying').classList.add('d-none');
            }
        });
        window.__rfqmsThreadReply = true;
    </script>
@endpush
