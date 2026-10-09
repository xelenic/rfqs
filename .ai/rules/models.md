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

## Data Entry's time runs from their Start
A part with Data Entry is only timed once Data Entry starts it: rfq_user.data_entry_started_at, set by Rfq::startDataEntryPart() and allowed only in working hours by the controller. Rfq::timedStepFor() returns no step until then. The data_entry stretch's worked_by is whoever started it. Every path that sends a part back to Data Entry must clear the start (Rfq::DATA_ENTRY_START_COLUMNS): returnSourcingPart, returnToDataEntry, and rejects that target 'data_entry'. Send to Finalize is refused until the part is started.

## GM Assistant only submits; client details and payment terms are legacy
Since 2026-10-09 GM Assistant's step is a confirmed Submit that forwards the part or RFQ to the General Manager (Rfq::recordGmAssistantPart() / recordGmAssistantDetails(), no details arguments). rfqs.client_details and payment_terms are kept only so older RFQs still show what was given; nothing writes them now. Don't reintroduce a details form for GM Assistant without the business asking.

## Rejects save a snapshot so the part can be sent straight back
Rfq::rejectToStage()/rejectPartToStage() call recordReturn() first, saving each part's rfq_user row and the RFQ's workflow columns (RfqReturn). The receiving role's "Send back to …" button (Rfq::forwardBack()) restores that snapshot, skipping the steps between. Any new reject path must call recordReturn() before it clears anything. Availability is Rfq::openReturns(): each part's latest unforwarded return, still positionally where it was sent. Pitfall: on an Eloquent collection, only()/except() filter by model id, not collection key, so use filter() for part numbers.
