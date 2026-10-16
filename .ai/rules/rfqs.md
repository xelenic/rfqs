---
paths:
  - 'resources/views/admin/rfqs/**'
---

# Rfqs

## Sourcing has no Return to Senior Operations
Since 2026-10-12, only Admin can send a Sourcing part back to Senior Operations (the 'sourcing_to_ops' kind of _complete_button, RfqController::returnSeniorOps()), recorded as the part's member. Every spot that shows it, and the controller, gate on User::canSendSourcingPartsBackToSeniorOps(). Don't show it to Sourcing again without the business asking.

## RFQ progress chart: HTML cards and SVG lines, no ECharts
Since 2026-10-16, renderRfqProgressChart (public/js/admin.js) draws the RFQ page's Progress chart itself instead of using ECharts. Columns follow tree depth, there is one row per lane, and each parent is centred on its children. Every node is an HTML card (.pc-node) with a round picture overlapping its left edge, connected by SVG curves. Each node in #rfq-progress-data (built in show.blade.php) carries: name, role, step, rfq_number, meta[0..1], state (done/current/pending/returned), icon (from $stepTabs) and person. person is null for nobody, ['masked' => true] when the name must stay hidden (show a lock), or name/photo/initial. Masking stays server-side, so hidden names never reach the page. Build card text with textContent, never innerHTML.

## Sourcing's My Pending RFQs: three tabs
Since 2026-10-16, a Sourcing member's My Pending RFQs has three tabs, part by part. **Pending for Sourcing** ($rfqs, data-de-tab="todo", the default) lists parts still with them (completed_at and returned_at null) and parts Data Entry sent back to finalize (RfqAssignment::whereAwaitingFinalize()). **With Data Entry** (?tab=with-data-entry, $withDataEntryRfqs) lists parts with completed_at set and data_entry_completed_at null; it shows Not started / In progress / Paused and whom it was assigned to, but never who in Data Entry started it. **Finalized** (?tab=finalized, $finalizedRfqs) lists finalized parts on Pending RFQs, with RfqAssignment::stageAfterFinalize(). Data Entry returns stay on Returns. Each tab has its own paginator and count ($sourcingCounts). The open tab is kept in ?tab= via data-de-tab / data-tab-default and #deTabInput. Keep the controller queries, the row loops' @continue checks and the sidebar badge (Pending for Sourcing = open + Rfq::awaitingFinalizeCount()) in step. Admin's _sourcing_overview has a single list.
