---
paths:
  - 'resources/views/admin/rfqs/**'
---

# Rfqs

## Sourcing has no Return to Senior Operations
Since 2026-10-12, only Admin can send a Sourcing part back to Senior Operations (the 'sourcing_to_ops' kind of _complete_button, RfqController::returnSeniorOps()), recorded as the part's member. Every spot that shows it, and the controller, gate on User::canSendSourcingPartsBackToSeniorOps(). Don't show it to Sourcing again without the business asking.
