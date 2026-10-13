{{--
    Photos to go with a comment or a reason (attachments[]) — kept with it on
    the RFQ's thread (RfqCommentAttachment). Photos only: JPG, PNG, GIF or
    WebP (RfqCommentAttachment::EXTENSIONS) — the picker offers nothing else,
    and anything else is turned down. The form it's in has to send
    multipart/form-data. On a phone, picking a photo can take one there and
    then.

    Expects: $attachmentsId (the input's id, unique on the page).
--}}
<div class="mt-2 comment-attach">
    <label class="form-label small mb-1" for="{{ $attachmentsId }}">
        <i class="bi bi-image"></i> Photos <span class="text-muted-soft fw-normal">(optional)</span>
    </label>
    <input type="file" name="attachments[]" id="{{ $attachmentsId }}" class="form-control form-control-sm" multiple
           accept="image/jpeg,image/png,image/gif,image/webp">
    <div class="form-text">Up to {{ \App\Models\RfqCommentAttachment::MAX_FILES }} photos, {{ \App\Models\RfqCommentAttachment::MAX_KILOBYTES / 1024 }} MB each — JPG, PNG, GIF or WebP.</div>
</div>
