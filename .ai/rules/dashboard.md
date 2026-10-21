---
paths:
  - 'resources/views/admin/dashboard/**'
---

# Dashboard

## The General Manager's dashboard cards open All RFQs narrowed (?show=)
Since 2026-10-21 each of the General Manager's headline cards (dashboard/_approver, when $stage role is General Manager and they canListAllRfqs()), and each "Open RFQs by priority" row, links to All RFQs. The link carries ?show= with one of RfqController::ALL_RFQS_VIEWS: awaiting_gm, gm_approved, gm_returned, open or closed. The dated views (DATED_ALL_RFQS_VIEWS) also carry the dashboard period as ?show_from and ?show_to. RfqController::applyAllRfqsView() must count each view the same way DashboardController::approverOverview() counts its card. "By you" means the viewer when they are a GM, and anyone holding the role for Admin. When a card's count changes, change both. The Head of BD and GM Assistant share _approver but have no All RFQs, so their cards keep the in-page anchors.

## "Time vs target" bars: DashboardController::timeAgainstTarget() + _time_vs_target
Since 2026-10-21, Admin's ongoing tasks and the Sourcing dashboard's Recently assigned both draw time against target with dashboard/_time_vs_target. It takes $timing from DashboardController::timeAgainstTarget(): seconds, target, remaining, and state, which is overdue below 0, due_soon under a quarter of the target left, or on_track. Pass $for to show "Sourcing target · Overdue" under the bar. A Sourcing part counts against sourcingCountdown() while it's in progress or returned, and against finalizeCountdown() once Data Entry has sent it to finalize. A part with Data Entry or already finalized has no bar. sourcingOverview() must eager-load the sourcing and finalize steps, or the countdowns come out null. The Pending card counts the overdue parts. Keep the thresholds in timeAgainstTarget(), never in a view.
