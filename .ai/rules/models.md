---
paths:
  - 'app/Models/**'
---

# Models

## A resumed rfq_steps stretch is not a new round
Putting an RFQ on hold or cancelling it ends its open stretches. Resuming it (Rfq::changeStatus → syncSteps(resuming: true)) starts new ones flagged `resumed`, which carry on the round before them. Anything that counts rounds or times a round (countdowns, deadlines, rounds/reworks) must group stretches with Rfq::roundsOf() or skip `resumed` ones. Otherwise a hold restarts the Sourcing target and shows up as a rework.

## Positional vs actionable part checks
RfqAssignment::isAwaiting…() and whereAwaiting…() say where a part stands, ignoring whether it's stopped (on hold or cancelled). Anything a role acts on or counts must also check ! isStopped() / RfqAssignment::whereActive(): partAwaits…(), queue rows, sidebar and queue counts. Rfq::whereAwaitingAt() keeps the whole-RFQ fallback row positional. "Every part is through" gates go through Rfq::everyLivePart(): cancelled parts don't count, held ones still do. Whole-RFQ actions are refused while a part is on hold (RfqController::abortIfPartOnHold()).

## Who can send back to whom lives in Rfq::RETURN_TARGETS
The business decided which role can return an RFQ to which earlier step (2026-10-06). Rfq::RETURN_TARGETS is the single source: rejectTargetStages(), the Reject modal's options and validation all read it. Never derive targets from REJECT_STAGE_ORDER ("everything earlier"); that rule was replaced. Returns go only to earlier steps, never to the role's own step or a later one. A new send-back must be added to RETURN_TARGETS, not hard-coded in a controller or view.

## Attendance counts only once HR Manager approves the sheet
Senior Operations (and Admin) submit the daily AttendanceSheet, and HR Manager (and Admin) approve it or return it. Only approved sheets count: Attendance::book() filters on attendance_sheets.approved_at, so an unapproved or returned day reads as UNMARKED ("awaiting"). Read attendance through book() and statusIn(), never by querying attendances directly. Any edit to a sheet goes through AttendanceSheet::submitted(), which clears the approval and sends it back to HR.
