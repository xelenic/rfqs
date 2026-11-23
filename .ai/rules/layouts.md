---
paths:
  - 'resources/views/layouts/**'
---

# Layouts

## "All RFQs" is for rfqs.list-all (Admin, General Manager, Senior Operations)
Since 2026-10-21 the General Manager also has the "All RFQs" sidebar link. It opens the RFQ list with no status or role filter, which shows every RFQ. The link is gated on User::canListAllRfqs(), which checks rfqs.list-all plus rfqs.view, not on hasRole('Admin'). To give another role the page, switch rfqs.list-all on for it on the Roles page (since 2026-11-24 it's a permission, App\Permissions). A sidebar link to a feature's page must check that feature's permission: _sidebar_admin_groups drops each link whose 'can' is false, and the Reports section shows for anyone with attendance, Time Spent or the performance reports. Their RFQ visibility still comes from Rfq::scopeVisibleTo(), so a role limited to its own RFQs would only see those there.
