{{--
    A comment's photos and files, under its text: each photo as a thumbnail
    that opens full size, each other file as a chip that downloads it. See
    RfqCommentAttachment, RfqCommentController::attachment().

    Expects: $comment (an RfqComment — attachments loaded, or loaded here).
--}}
@if ($comment->attachments->isNotEmpty())
    <div class="comment-attachments">
        @foreach ($comment->attachments as $attachment)
            @if ($attachment->isImage())
                <a href="{{ $attachment->url() }}" target="_blank" rel="noopener" class="comment-attachment-photo" title="{{ $attachment->original_name }}">
                    <img src="{{ $attachment->url() }}" alt="{{ $attachment->original_name }}" loading="lazy">
                </a>
            @else
                <a href="{{ $attachment->url(download: true) }}" class="comment-attachment-file" title="Download {{ $attachment->original_name }}">
                    <i class="bi {{ $attachment->icon() }}"></i>
                    <span class="comment-attachment-name">{{ $attachment->original_name }}</span>
                    <span class="text-muted-soft">{{ $attachment->sizeLabel() }}</span>
                </a>
            @endif
        @endforeach
    </div>
@endif
