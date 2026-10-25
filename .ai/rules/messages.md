---
paths:
  - 'resources/views/admin/messages/**'
---

# Messages

## Messages: New message starts a conversation with anyone active
Since 2026-10-25, the Private messages tab has New message (#newMessageModal in messages/index). It lists everyone active except the viewer (MessageController::index() $people, User::active()), with roles, filtered by a name/role search. It posts to messages.store like the hover card does, so an existing conversation just carries on, and a successful send redirects into the conversation. The form sends new_conversation=1, so a refused send (no one picked, empty body, deactivated account) reopens that popup with the typed text kept. Errors use the default bag, which the conversation page shares, so keep the new_conversation guard. Anyone can write to anyone active, HR Executive included; never filter the list by RFQ access.
