---
paths:
  - 'resources/views/admin/rfqs/**'
---

# Rfqs

## Sourcing has no Return to Senior Operations
Since 2026-10-12, only Admin can send a Sourcing part back to Senior Operations (the 'sourcing_to_ops' kind of _complete_button, RfqController::returnSeniorOps()), recorded as the part's member. Every spot that shows it, and the controller, gate on User::canSendSourcingPartsBackToSeniorOps(). Don't show it to Sourcing again without the business asking.

## RFQ progress chart: HTML cards and SVG lines, no ECharts
Since 2026-10-16, renderRfqProgressChart (public/js/admin.js) draws the RFQ page's Progress chart itself instead of using ECharts. Columns follow tree depth, there is one row per lane, and each parent is centred on its children. Every node is an HTML card (.pc-node) with a round picture overlapping its left edge, connected by SVG curves. Each node in #rfq-progress-data (built in show.blade.php) carries: name, role, step, rfq_number, meta[0..1], state (done/current/pending/returned), icon (from $stepTabs) and person. person is null for nobody, ['masked' => true] when the name must stay hidden (show a lock), or name/photo/initial. Masking stays server-side, so hidden names never reach the page. Build card text with textContent, never innerHTML.
