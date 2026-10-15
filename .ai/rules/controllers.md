---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## A GET page must not write to a live table on every visit
Live updates (App\LiveVersion, public/js/live.js) re-fetch the current page whenever the data version moves, and any write to rfqs, rfq_user, rfq_comments, private_messages or job_categories moves it. A GET that writes each time it renders (e.g. marking messages read) makes a live page refetch itself forever. Only write when there is something to change — see MessageController::show(), which checks exists() before the update.

## Who can edit an RFQ is Rfq::canBeEditedBy()
RfqController::update() aborts 403 unless Rfq::canBeEditedBy($user). Admin can edit any RFQ. Senior Operations and Business Development can edit any RFQ that isn't closed (status Completed) and that they can see (Rfq::isVisibleTo()); since 2026-10-11 that includes Business Development on RFQs that weren't returned to them, and since 2026-10-15 only their own (see app.md, "Business Development sees only the RFQs they created"). The Edit buttons (_rfq_row, show page, BD Returns) use the same method, so never gate Edit on permissions or roles directly. Only an Admin changes status through Edit: for anyone else update() keeps the current status (Pending for a BD return). The form hides the status picker for non-Admins.
