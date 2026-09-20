---
paths:
  - 'app/Http/Controllers/**'
---

# Controllers

## A GET page must not write to a live table on every visit
Live updates (App\LiveVersion, public/js/live.js) re-fetch the current page whenever the data version moves, and any write to rfqs, rfq_user, rfq_comments, private_messages or job_categories moves it. A GET that writes each time it renders (e.g. marking messages read) makes a live page refetch itself forever. Only write when there is something to change — see MessageController::show(), which checks exists() before the update.
