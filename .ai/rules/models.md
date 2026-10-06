---
paths:
  - 'app/Models/**'
---

# Models

## A resumed rfq_steps stretch is not a new round
Putting an RFQ on hold or cancelling it ends its open stretches. Resuming it (Rfq::changeStatus → syncSteps(resuming: true)) starts new ones flagged `resumed`, which carry on the round before them. Anything that counts rounds or times a round (countdowns, deadlines, rounds/reworks) must group stretches with Rfq::roundsOf() or skip `resumed` ones. Otherwise a hold restarts the Sourcing target and shows up as a rework.

## Positional vs actionable part checks
RfqAssignment::isAwaiting…() and whereAwaiting…() say where a part stands, ignoring whether it's stopped (on hold or cancelled). Anything a role acts on or counts must also check ! isStopped() / RfqAssignment::whereActive(): partAwaits…(), queue rows, sidebar and queue counts. Rfq::whereAwaitingAt() keeps the whole-RFQ fallback row positional. "Every part is through" gates go through Rfq::everyLivePart(): cancelled parts don't count, held ones still do. Whole-RFQ actions are refused while a part is on hold (RfqController::abortIfPartOnHold()).
