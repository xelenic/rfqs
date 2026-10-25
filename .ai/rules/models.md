---
paths:
  - 'app/Models/**'
  - app/Models/Attendance.php
  - app/Models/RfqComment.php
  - app/Models/Rfq.php
  - app/Models/PrivateMessage.php
  - app/Models/Setting.php
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
Since 2026-10-18, while Setting::gmAssistantPerItem() is on (it's off by default), a part's GM Assistant target is line items × seconds per line item. The count comes from Rfq::gmAssistantLineItemsFor(). First comes the Head of Business Development's Finalized Line Items ('finalized'; with these it always goes per line item, even with the switch off). Then the line items Data Entry added (rfq_user.data_entry_entries). Then Sourcing's line_items_to_add. Senior Operations' Actual Line Items are never used for this. On 2026-10-24 the user asked for Actual, then reversed it the same day: "don't calculate actual". A count of 0 falls through to the next rather than giving no time. With none of them, or with the switch off and nothing finalized, the priority target applies. The General Manager enters no count; one they send back to GM Assistant keeps the finalized count. Always pass the part. gmAssistantTargetBreakdown() gives the formula for hover text.

## A half day can be off a custom time (Attendance::CUSTOM_HALF)
Since 2026-10-19, a half day on the attendance sheet is off the morning, the afternoon (Setting::halfDayOff()), or a custom time: half_off 'custom' with half_off_start and half_off_end ("HH:MM", business time zone). A custom range must hold some of the person's working time and at most MAX_CUSTOM_OFF_SECONDS (4h) of it; lunch doesn't count (AttendanceController::ensureCustomTimeOff(), checked against the sheet's own date). book() gives it as customOffStatus() ("custom_off:10:00-14:00"). Read any half day's range with Attendance::timeOffOf($status) or ->timeOff(), and its label with timeOffLabelOf() or timeOffLabel(). Never match only MORNING_OFF or AFTERNOON_OFF. Subtract it with Setting::workingSecondsInTimeOff(), and test "now" with Setting::isInTimeOff(). dataEntryIdleness() now returns time_off (a range) instead of half_off.

## A hold can be "waiting for customer clarification"
Since 2026-10-19, the Put on hold popup (_status_modal) has a "Waiting for customer clarification" checkbox (customer_clarification). It reaches Rfq::changeStatus() and changePartStatus() as $awaitingCustomer and is kept only as meta customer_clarification: true on the put_on_hold comment (customerClarificationMeta()). There is no column for it. The performance reports read it to count those RFQs apart from pending ones, and RfqComment::actionContext() shows it on the thread. Any new path that puts an RFQ or part on hold must pass it through, or its RFQs will count as pending.

## GM Assistant starts and pauses a part, as Data Entry does
Since 2026-10-20, a part with GM Assistant is timed only from their Start (Rfq::startGmAssistantPart(): rfq_user.gm_assistant_started_at and gm_assistant_started_by). timedStepFor() returns 'gm_assistant' only while isGmAssistantRunning(), and the stretch's worked_by is the starter. Pause (pauseGmAssistantPart()) needs a reason, takes an optional reference, posts a 'gm_assistant_paused' comment and closes the stretch; pausedGmAssistantCountdown() then holds the countdown. Resume (resumeGmAssistantPart()) uses syncSteps(resuming: true). One part at a time: RfqStep::runningGmAssistantOf(). RfqController::submitGmAssistantDetails() refuses a part that isn't started, or is paused, including every part for a whole-RFQ Submit (partsAwaitingGmAssistant()). Being sent back to GM Assistant's step or earlier clears the start (GM_ASSISTANT_START_COLUMNS via startColumnsClearedBy(), and returnSourcingPart()), so it's started afresh. The views use _gm_assistant_actions and _start_gm_assistant_button, and reuse Data Entry's pause popup.

## Count leaves and half days with Attendance::timeOffTally()
Since 2026-10-21, a person's time off (leaves, half days, days off where a half day counts as half) is counted by Attendance::timeOffTally(): per month, year and in all, from approved sheets only. Lines on unapproved or returned sheets are kept apart as 'pending' and never counted. The user page (UserController::timeOffOf()) and the hover card on attendance names (attendance/_time_off_card, via [data-hover-card] in admin.js) both read it, so don't count attendances anywhere else. The hover card's counts are a <table>, so the shared popover in admin.js extends Bootstrap's sanitizer allowList with table tags. Keep that if you change it.

## Workflow actions must be safe to submit twice
On 2026-10-21 production showed "Assigned in Data Entry — they were told" twice on an RFQ's timeline. A repeated Assign to Data Entry request (double click or retry) found the part already completed, but the controller still sent DataEntryAssigned again.
- Rfq::completeSourcingPart() now completes only while rfq_user.completed_at is null (wherePivotNull) and returns whether it did. RfqController::completeSourcing() sends the alert only when it did.
- returnSourcing() ignores a part already back with Sourcing.
- Any action that sends an alert or a task message must send it only when this request actually changed something, not after a no-op.
- Rfq::sentAlerts() also merges the same alert (same type, part and message) sent again within ALERT_REPEAT_SECONDS (120) into one timeline entry, so duplicates already stored show once.

## Return to Sourcing records the line items Data Entry had added (rfq_user.return_line_items)
Since 2026-10-21, Data Entry's Return to Sourcing popup (_complete_modal, kind "return") requires "Line items added", sent as `entries` (0 to MAX_DATA_ENTRIES). RfqController::returnSourcing() validates it the same way as Send to Finalize. Rfq::returnSourcingPart($part, $reason, $by, $lineItemsAdded) does three things with it:
- stores it in rfq_user.return_line_items next to return_reason;
- adds it to the returned_to_sourcing comment as meta entries, so the thread shows "N line items added";
- includes it in Sourcing's task message.
completeSourcingPart() clears it along with the return. A reviewer's send-back to Sourcing passes none, so it stays null. Show it with RfqAssignment::returnLineItemsLabel(). It is not data_entry_entries (the Send to Finalize count), so never write one into the other: the discrepancy check and the reports read data_entry_entries.

## Seven priority levels, P1 Critical to P7 Trivial — read them through Rfq's helpers
Since 2026-10-21 Rfq::PRIORITIES holds seven levels, least pressing first: Trivial, Minimal, Low, Medium, High, Urgent, Critical. rfqs.priority_level stores the name, so older RFQs kept Urgent, High, Medium and Low. Never hard-code the level names or their order:
- Show a level with priorityLabel() or priorityLabelOf(), which gives "P2 – Urgent" from PRIORITY_CODES.
- Colour it with priorityBadgeClass(), which returns `badge-priority priority-chip-{level}`. The colours are the business's 🟣🔴🟠🔵🟢🟡⚪ (Rfq::PRIORITY_SYMBOLS), set once per level in admin.css as --dot, --dot-soft, --dot-text and, for white, --dot-fill; chips, badges and meters all read those variables, so never colour a level anywhere else. The Add/Edit RFQ picker shows the emoji, because an <option> can't be coloured.
- Sort with orderByRaw(Rfq::priorityOrderSql($column)).
- "Urgent" on the dashboards means URGENT_PRIORITIES (Critical + Urgent), via isUrgent(). The Critical monitor lists Critical, Urgent and High.
- Each Setting::DEFAULT_*_TARGETS has all seven keys. Setting::targets() fills levels missing from older saved settings with their defaults, and the Settings target forms post all seven.

## Rfq::lineItemsHistory() is built from action comments and step stretches
Since 2026-10-21, Senior Operations' Verify line items popup and the Head of BD's Finalize popup show each part's line items history. The data is Rfq::lineItemsHistory($part), passed in the approve buttons' data-parts as `history`, drawn by _line_items_history and fillLineItemsHistory() in admin.js. It is assembled, not stored, from:
- action comments: sourcing_completed (meta line_items), data_entry_completed and returned_to_sourcing (meta entries), returned_to_data_entry;
- sourcing, data_entry and finalize rfq_steps stretches, with working time;
- the part's verified_line_items.
A part is matched by meta part, or 1 on a whole RFQ. When times tie, actions sort before the stretch they start. Each row carries a change against what was requested and the previous count, and tone (off/ok). A new action that sets or changes a line-item count must put it in its comment's meta, or the history won't show it. Tests compare the counts with popupParts(), which drops the history.

## Every RFQ comment goes to everyone involved, in Messages
Since 2026-10-24, at the client's request, RfqCommentController::store() sends each comment and reply as a PrivateMessage (rfq_comment_id) from its author. First it sends @mentions (messageMentionedUsers()), then RfqComment::messagePeopleInvolved($mentioned) sends it to Rfq::peopleInvolved(): the PEOPLE_COLUMNS on rfqs and rfq_user, everyone who has commented or acted on it (any rfq_comments.user_id), and whoever it's waiting with (a review stage's peopleAtStage(), or Senior Operations while it has no parts). A mentioned person gets only the mention. It never goes to the author, a deactivated account, or anyone who can't open the RFQ (rfqs.view + isVisibleTo()). Bodies are "Commented on [closed ]RFQ… — subject:", "Replied to your comment on…" or "Replied to a comment on…", plus "(N photos attached)". Workflow action comments (postActionComment) are not sent this way; TaskMessages covers those. Someone newly involved in an RFQ should be added through PEOPLE_COLUMNS or a comment, not by a separate send.

## Returns to Senior Operations are on Returns only, not Unassigned
Since 2026-10-24, at the user's request, an RFQ sent back to Senior Operations' assignment step (reject_target_stage 'operations') is listed only on their Returns page (scopeReturnedToSeniorOperations()), never on Unassigned. This covers returns from a reviewer, or a Sourcing part an Admin sends back. Senior Operations' Unassigned queue is Rfq::scopeAwaitingAssignment(): Pending + needingSourcing(), minus target 'operations'. The list (RfqController $scopedToUnassigned), their sidebar badge and queueCounts()['unassigned'] (Admin's sidebar and pipeline) all use it; don't count "unassigned" with a bare needingSourcing(). Once every part is assigned again it leaves Returns by itself. Returns to Business Development still show on Unassigned with their marker, as before.

## RFQ comment copies show once, under RFQ comments, never in Private messages
Since 2026-10-25, at the user's request (the same comment showed once per recipient in the author's Private messages), the PrivateMessage copies of RFQ comments and mentions (rfq_comment_id set) are read only in Messages → RFQ comments, one thread per RFQ. In the bell they are one line per RFQ (User::bellItems(), icon bi-chat-square-text, url messages.rfq). The Private messages list, conversations, per-sender unread counts, marking read on a conversation, the bell's per-person lines and the tab counts all go through PrivateMessage::personalFor($user). The RFQ-comment side uses onReadableRfqsFor($user) and unreadCommentsByRfq(). One exception: a copy on an RFQ the user can't open (no rfqs.view, or not visibleTo, e.g. HR Executive or another BD person who was @mentioned) stays in Private messages, the only place they can read it. Task messages (rfq_id only) stay in Private messages. Rows are still created per recipient; this is a reading filter, so never add a second copy for the author.

## Time per item can be set person by person (Data Entry, GM Assistant)
Since 2026-10-25, Settings → Data Entry Time and GM Assistant Time each have a "person by person" card (settings/_per_item_people). It holds Setting::perItemPeople('data_entry'|'gm_assistant'): user id => seconds, within DATA_ENTRY_PER_ITEM_RANGE, saved by SettingsController::savePerItemPeople() for people in that role only. An empty field means the company's time. Targets go through Setting::dataEntryPerItemFor($userId) / gmAssistantPerItemFor($userId), which keep the company's on/off switch and use the person's seconds when they have their own ('own'). Whose time applies: Rfq::dataEntryPersonFor($part) (started_by ?? completed_by ?? assigned_to, so a part left to anyone uses the company's time until someone starts it) and Rfq::gmAssistantPersonFor($part) (started_by ?? completed_by). The hovers say whose own time it is (_data_entry_target "(Name's own)", the GM time card's perItemOwnOf). Never read Setting::dataEntryPerItem()['seconds'] directly for a part's target.
