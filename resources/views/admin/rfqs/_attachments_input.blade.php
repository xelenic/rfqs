{{--
    Photos or files to go with a comment or a reason (attachments[]) — kept
    with it on the RFQ's thread (RfqCommentAttachment). The form it's in has to
    send multipart/form-data. On a phone, picking a photo can take one there
    and then.

    Expects: $attachmentsId (the input's id, unique on the page).
--}}
<div class="mt-2 comment-attach">
    <label class="form-label small mb-1" for="{{ $attachmentsId }}">
        <i class="bi bi-paperclip"></i> Photos or files <span class="text-muted-soft fw-normal">(optional)</span>
    </label>
    <input type="file" name="attachments[]" id="{{ $attachmentsId }}" class="form-control form-control-sm" multiple
           accept="image/jpeg,image/png,image/gif,image/webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.txt">
    <div class="form-text">Up to {{ \App\Models\RfqCommentAttachment::MAX_FILES }}, {{ \App\Models\RfqCommentAttachment::MAX_KILOBYTES / 1024 }} MB each — photos, PDF, Word, Excel, CSV or text.</div>
</div>
