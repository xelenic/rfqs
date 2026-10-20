---
paths:
  - public/js/admin-dashboard.js
  - public/js/live.js
---

# Js

## Dashboard charts: no red/green pairs, one measure per axis
Don't pair the status colours --rfq-success with --rfq-danger in a chart (e.g. on-time vs late). The validator fails that pair for colour-blind readers (deutan ΔE 4.5 in dark, 6.2 in light). Show a share as a single-series percentage instead (the "share" type), with the counts as direct labels. Never put two measures of different scale on one axis, such as parts and entries. Use a switch instead (the "metric" type with .js-chart-metric) or two charts. Role hues come from the --rfq-role-* tokens: one entity, one colour.

## Live updates keep running in background tabs and announce new bell items
Since 2026-10-18, live.js keeps polling while the tab is hidden, every 15s (HIDDEN_INTERVAL; previously it stopped). This is so messages reach people who have the app in another tab. patchBell() compares unread bell items before and after a refresh. Each new one is announced with an in-page toast (#liveToasts), the unread count is put in document.title as "(n) …", and a desktop Notification is shown when the tab is hidden and the person allowed it through the bell's "Desktop alerts" button (.js-desktop-alerts, shown only while permission is 'default'). Bell items need a stable href and .notification-item-text for this to work.

## New bell items pop up once, on arrival or on the next page load
Since 2026-10-21, live.js pops up a toast (top right, under the bell) for each new unread bell item. It does this when the item arrives while the page is open (patchBell), and when a page opens with an unread item this browser hasn't shown yet (announceUnseen). That covers items that arrived between pages. Items already shown are kept in localStorage under rfqms.announced.{user id} (data-user on #notificationBell), keyed by href|text and capped at 200, so nothing pops up twice. Without storage, only live arrivals pop up. On load it shows at most three toasts plus an "And N more unread" toast.
