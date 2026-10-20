---
paths:
  - 'resources/views/layouts/**'
---

# Layouts

## "All RFQs" is for User::ALL_RFQS_ROLES (Admin, General Manager)
Since 2026-10-21 the General Manager also has the "All RFQs" sidebar link. It opens the RFQ list with no status or role filter, which shows every RFQ. The link is gated on User::canListAllRfqs(), which checks ALL_RFQS_ROLES plus rfqs.view, not on hasRole('Admin'). To give another role the page, add it to ALL_RFQS_ROLES. Their RFQ visibility still comes from Rfq::scopeVisibleTo(), so a role limited to its own RFQs would only see those there.
