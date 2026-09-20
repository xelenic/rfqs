{{--
    The hover card behind every name marked .js-user-card (see
    admin/rfqs/_comment_author.blade.php): a person's details and a private
    message button that opens a little box to write to them right there. One
    card for the whole page, filled in and positioned by admin.js when a name is
    hovered, focused or tapped — and moved into the modal it was opened from, so
    Bootstrap's focus trap doesn't pull the cursor out of the message box.
    Messages go to MessageController::store() as JSON.
--}}
<div class="user-card" id="userCard" role="dialog" aria-label="Person details">
    <div class="user-card-head">
        <span class="assignee-avatar user-card-avatar" id="userCardAvatar"></span>
        <div class="user-card-who">
            <div class="user-card-name" id="userCardName"></div>
            <div class="user-card-roles" id="userCardRoles"></div>
        </div>
    </div>

    <ul class="user-card-details">
        <li><i class="bi bi-envelope"></i> <span id="userCardEmail"></span></li>
        <li><i class="bi bi-calendar3"></i> <span id="userCardSince"></span></li>
    </ul>

    <div class="user-card-actions" id="userCardActions">
        <button type="button" class="btn btn-sm btn-primary" id="userCardMessage">
            <i class="bi bi-chat-dots"></i> Private message
        </button>
        <a href="#" class="user-card-link" id="userCardConversation">Open conversation</a>
    </div>
    <div class="user-card-you d-none" id="userCardYou"><i class="bi bi-person-check"></i> This is you.</div>

    <form method="POST" action="{{ route('admin.messages.store') }}" class="user-card-composer d-none" id="userCardComposer" novalidate>
        @csrf
        <input type="hidden" name="recipient_id" value="">
        <textarea name="body" rows="3" maxlength="{{ \App\Models\PrivateMessage::MAX_LENGTH }}" class="form-control form-control-sm"
                  placeholder="Write a private message…" aria-label="Private message"></textarea>
        <div class="user-card-note"><i class="bi bi-lock"></i> Only you and <span id="userCardRecipient"></span> can read this.</div>
        <div class="d-flex justify-content-end gap-2 mt-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="userCardCancel">Cancel</button>
            <button type="submit" class="btn btn-sm btn-primary" id="userCardSend"><i class="bi bi-send"></i> Send</button>
        </div>
    </form>

    <div class="user-card-status" id="userCardStatus" role="status" aria-live="polite"></div>
</div>
