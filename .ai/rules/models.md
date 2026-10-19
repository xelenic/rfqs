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

## Attendance counts only once HR Executive approves the sheet
Senior Operations (and Admin) submit the daily AttendanceSheet, and HR Executive (and Admin) approve it or return it. Only approved sheets count: Attendance::book() filters on attendance_sheets.approved_at, so an unapproved or returned day reads as UNMARKED ("awaiting"). Read attendance through book() and statusIn(), never by querying attendances directly. Any edit to a sheet goes through AttendanceSheet::submitted(), which clears the approval and sends it back to HR.

## Data Entry's time runs from their Start
A part with Data Entry is only timed once Data Entry starts it: rfq_user.data_entry_started_at, set by Rfq::startDataEntryPart(). Since 2026-10-16, Start is allowed at any hour; only the one-part-at-a-time rule disables it. Time started outside working hours doesn't count until working hours resume, because every countdown and the Time Spent report go through Setting::workingSecondsBetween(). Never re-add an off-hours block on Start, and never count elapsed clock time as time spent. Rfq::timedStepFor() returns no step until then. The data_entry stretch's worked_by is whoever started it. Every path that sends a part back to Data Entry must clear the start (Rfq::DATA_ENTRY_START_COLUMNS): returnSourcingPart, returnToDataEntry, and rejects that target 'data_entry'. Send to Finalize is refused until the part is started.

## GM Assistant only submits; client details and payment terms are legacy
Since 2026-10-09 GM Assistant's step is a confirmed Submit that forwards the part or RFQ to the General Manager (Rfq::recordGmAssistantPart() / recordGmAssistantDetails(), no details arguments). rfqs.client_details and payment_terms are kept only so older RFQs still show what was given; nothing writes them now. Don't reintroduce a details form for GM Assistant without the business asking.

## Rejects save a snapshot so the part can be sent straight back
Rfq::rejectToStage()/rejectPartToStage() call recordReturn() first, saving each part's rfq_user row and the RFQ's workflow columns (RfqReturn). The receiving role's "Send back to …" button (Rfq::forwardBack()) restores that snapshot, skipping the steps between. Any new reject path must call recordReturn() before it clears anything. Availability is Rfq::openReturns(): each part's latest unforwarded return, still positionally where it was sent. Pitfall: on an Eloquent collection, only()/except() filter by model id, not collection key, so use filter() for part numbers.

## Working time is per person: pass the worker's id
Setting::workingPeriodsBetween(), workingSecondsBetween(), workingSecondsWithHalfDaysOff(), isWorkingTime(), offHoursWindows() and offHoursReason() take an optional $userId. With it, that person's ExtraWorkingHour ranges are merged into the company hours. Pass the worker wherever someone's time is measured: RfqStep::workerId(), the Data Entry starter, the countdown's data-worker. ExtraWorkingHour rows are never deleted: removing one sets ended_at, and each range counts only from starts_on, because time is recomputed on every view and past figures must not change.

## Comment attachments are photos only
Since 2026-10-13 the business allows only images on comments and action reasons: RfqCommentAttachment::EXTENSIONS is jpg/jpeg/png/gif/webp (no SVG, it can carry script), and the shared picker (_attachments_input) accepts the same. Every upload path goes through Controller::attachmentsFrom() and those rules, so don't add a separate upload path that skips them. Documents attached before then are kept and still served as downloads (isImage() false).

## A person's week: their own, else their role's, else the company's
Since 2026-10-15, a role or a person can have their own working week (WorkingSchedule, set on Settings → Role Hours and on a person's page). Setting::weekOn($day, $userId) resolves it in this order: the person's own week, then the first of their roles with one (WorkingSchedule::roleOrder(), which is workflow order), then the company's. ExtraWorkingHour still adds on top. Never read Setting::workingHours() for one person's time; use weekOn() or the $userId-taking methods.

Versions are kept, never deleted. putFor() starts one from today, or changes in place a version that already started today. endFor() sets ended_on to today. Each version counts from starts_on, so past days keep the hours they had. Compare starts_on with whereDate(), because SQLite stores it with a time.

The cache is cleared on any write to working_schedules, roles or model_has_roles (WorkingSchedule::watch()). A new path that assigns roles needs no extra step.

## Data Entry can pause a started part (rfq_user.data_entry_paused_at)
Since 2026-10-16, Rfq::pauseDataEntryPart($part, $by, $reason, $reference) sets data_entry_paused_at along with data_entry_pause_reason (required) and data_entry_pause_reference (optional), and posts a 'data_entry_paused' comment (reference in meta). Resume clears all three; the thread keeps the history. Form fields are pause_reason and pause_reference, shared by one #pauseDataEntryModal per page. timedStepFor() then returns null, so syncSteps() closes the open data_entry stretch: the countdown freezes and RfqStep::runningDataEntryOf() frees the person to Start another part. Rfq::resumeDataEntryPart() clears the flag and calls syncSteps(resuming: true), so the new stretch is resumed and roundsOf() keeps it in the same round. The countdown continues and the paused gap isn't counted. Resume is refused while the person has another part running; Send to Finalize is refused while paused. Only the starter (or Admin) can pause or resume. Any path that clears the Data Entry start must clear data_entry_paused_at too (DATA_ENTRY_START_COLUMNS). Use RfqAssignment::isDataEntryRunning() or isDataEntryPaused() rather than hasDataEntryStarted() when the difference matters.

## Finalize has its own target: Rfq::finalizeCountdown()
Since 2026-10-16, the finalize step (Sourcing finalizing a part that Data Entry sent back) counts down against Setting::finalizeTargets(). These are working minutes per priority, defaulting to Urgent 15 / High 30 / Medium 60 / Low 120, set on Settings → Finalize Targets. Sourcing's Pending list and the Critical monitor (ongoingTasks) both use finalizeCountdown(). Since 2026-10-17, GM Assistant's step has one too: Setting::gmAssistantTargets(), with defaults Urgent 30 / High 60 / Medium 120 / Low 240 (Settings → GM Assistant Targets). Rfq::gmAssistantCountdown() is shown on GM Assistant's Review and Returns lists, which eager-load gm_assistant steps and print #countdown-schedule, and is used by the Critical monitor and teamPerformance. A new timed step needs both a target and a countdown wherever time left is shown.

## Sourcing time carries on across rounds (Rfq::sourcingTimeOf())
Since 2026-10-17, at the user's request, a part sent back to Sourcing keeps counting from where it was rather than starting a fresh round. Its countdown, sourcingDeadline() (late_parts / late_by) and the dashboards' on-time judgement all sum every Sourcing stretch of that part by the same member (assignee_id). A part reassigned to someone else starts afresh with them. Lists that show Sourcing countdowns must eager-load all of the part's sourcing steps, not just the open ones. Data Entry and Finalize countdowns still run per round.

## GM Assistant's target: Rfq::gmAssistantTargetSeconds($part)
Since 2026-10-18, while Setting::gmAssistantPerItem() is on (it's off by default), a part's GM Assistant target is line items × seconds per line item. The count comes from Rfq::gmAssistantLineItemsFor(): the line items Data Entry added (rfq_user.data_entry_entries) if more than 0, otherwise Sourcing's line_items_to_add. With neither, or with the switch off, the priority target applies. Always pass the part. gmAssistantTargetBreakdown() gives the formula for hover text.

## A half day can be off a custom time (Attendance::CUSTOM_HALF)
Since 2026-10-19, a half day on the attendance sheet is off the morning, the afternoon (Setting::halfDayOff()), or a custom time: half_off 'custom' with half_off_start and half_off_end ("HH:MM", business time zone). A custom range must hold some of the person's working time and at most MAX_CUSTOM_OFF_SECONDS (4h) of it; lunch doesn't count (AttendanceController::ensureCustomTimeOff(), checked against the sheet's own date). book() gives it as customOffStatus() ("custom_off:10:00-14:00"). Read any half day's range with Attendance::timeOffOf($status) or ->timeOff(), and its label with timeOffLabelOf() or timeOffLabel(). Never match only MORNING_OFF or AFTERNOON_OFF. Subtract it with Setting::workingSecondsInTimeOff(), and test "now" with Setting::isInTimeOff(). dataEntryIdleness() now returns time_off (a range) instead of half_off.

## A hold can be "waiting for customer clarification"
Since 2026-10-19, the Put on hold popup (_status_modal) has a "Waiting for customer clarification" checkbox (customer_clarification). It reaches Rfq::changeStatus() and changePartStatus() as $awaitingCustomer and is kept only as meta customer_clarification: true on the put_on_hold comment (customerClarificationMeta()). There is no column for it. The performance reports read it to count those RFQs apart from pending ones, and RfqComment::actionContext() shows it on the thread. Any new path that puts an RFQ or part on hold must pass it through, or its RFQs will count as pending.
