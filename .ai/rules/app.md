---
paths:
  - 'app/**'
  - app/PerformanceReports.php
---

# App

## Qualify rfqs.status in queries joining rfq_user
rfq_user has its own `status` column (a part stopped on its own, Rfq::changePartStatus()). Any query that joins rfqs to rfq_user must say `rfqs.status` or `rfq_user.status`, never a bare `status`. Otherwise SQLite and MySQL fail with "ambiguous column name". That covers the assignees and assignedRfqs relations, whereHas('assignees') and DB::table('rfq_user')->join('rfqs'). Example: DashboardController's `$user->assignedRfqs()->where('rfqs.status', 'Pending')`.

## Count "a day" in Setting::timezone(), query in UTC
Times are stored in the app timezone (UTC), but a business day is the working hours' timezone (Setting::timezone(), Asia/Colombo by default). Build day/period boundaries with CarbonImmutable::now(Setting::timezone())->startOfDay(), then ->setTimezone(config('app.timezone')) before passing them to a query: query bindings format a Carbon as-is without converting zones. A bare now()->startOfDay() is UTC midnight (5:30 AM in Colombo). See DashboardController::summaryPeriod(). Count returns from rfq_comments with action 'rejected' (meta.stage = target label), not rfqs.rejected_at, which is cleared once Business Development fixes the RFQ.

## Look people up by role name, not Spatie's role()
Spatie's User::role('X') throws RoleDoesNotExist when a role hasn't been created, and tests (and a fresh install) create only the roles they need. Any page-level lookup of who holds a role goes through User::scopeHoldingRole() or whereHas('roles', fn ($r) => $r->whereIn('name', [...])). AttendanceSheet::people() does this since 2026-10-12, after Admin's dashboard 500'd on it.

## Admin "log in as" goes through App\Impersonation
Since 2026-10-15 an Admin can sign in as another user (ImpersonationController). The Admin's own id is kept in the session under Impersonation::SESSION_KEY. Gate every entry point with Impersonation::canStart(): Admin only, never as another Admin or as themselves, and not while already signed in as someone else. Don't call Auth::login() for this anywhere else. start() and stop() log who signed in as whom (laravel.log) and handle the session. If the impersonating Admin has since lost the role, "Back to your account" signs out altogether. While it's on, auth()->user() is the other person, so permission checks are theirs.

## Business Development sees only the RFQs they created
Since 2026-10-15, a user whose only workflow role is Business Development (User::seesOnlyOwnRfqs()) sees just the RFQs they created. That covers every list (Closed included), sidebar badges, their dashboard and the WC search. For a query, use Rfq::scopeOwnedBy($user->ownRfqsOnlyId()); null leaves it unscoped for everyone else. For one RFQ, use Rfq::isVisibleTo(). Routes with an {rfq} or {attachment} parameter are guarded by the EnsureRfqIsVisible middleware, which returns 403. RFQs whose creator is null (for example a deleted account; created_by is nullOnDelete) stay visible to all of Business Development so nothing is left unreachable. A new BD-facing query must go through ownedBy(). Admin and anyone holding another workflow role still see every RFQ.

## Data Entry's target: Rfq::dataEntryTargetSeconds($part)
Since 2026-10-16, a part given **line items to add** (rfq_user.line_items_to_add, set by Sourcing on Assign to Data Entry) always gets line items × Setting::dataEntryPerItem()['seconds'] as its target, whether the per-item switch is on or off. A part without line items (handed over before that) uses the RFQ's number_of_items split across parts (Rfq::dataEntryItemsFor()) × time per item when the switch is on, and otherwise the priority target. dataEntryTargetBreakdown($part) returns the formula with source 'line_items' or 'items'. Always pass the part. Never read Setting::dataEntryTargets()[$priority] directly for a part's target.

## A part can be given to one Data Entry person (rfq_user.data_entry_assigned_to)
Since 2026-10-15, Sourcing's "Assign to Data Entry" can name a Data Entry person (Rfq::completeSourcingPart(..., $dataEntry)). That person is notified (DataEntryAssigned) and is the only one who sees and starts the part; Admin still sees and can act on all. Null means anyone in Data Entry, the old shared-queue behaviour. Anything listing, counting or starting Data Entry's waiting parts per person must go through RfqAssignment::whereForDataEntryUser() or Rfq::partIsForDataEntryUser(). That covers the Ready list and its count, the sidebar badge, the Data Entry dashboard, Start, and idleness via Rfq::dataEntryWaitingSince($userId). Each re-assignment after a return to Sourcing sets it again.

## Sourcing member's default Data Entry person (users.default_data_entry_user_id)
Since 2026-10-15, an Admin can set each Sourcing member's usual Data Entry person on that member's user page (UserController::updateDefaultDataEntry(), User::defaultDataEntryUser()). It only pre-selects the "Assign to Data Entry" picker; the member can still choose someone else or "anyone". The part's previous Data Entry person, if any, takes precedence (_complete_button sets data-de-assignee). Never assign automatically from the default without the member's submit. The column is nullOnDelete, so deleting that Data Entry account clears it.

## Who sees which RFQs: Rfq::scopeVisibleTo($user) and isVisibleTo()
Since 2026-10-15, two roles see only part of the RFQs. Business Development (User::seesOnlyOwnRfqs()) sees the ones they created. Data Entry (User::seesOnlyAssignedRfqs(), when it's their only workflow role) sees RFQs with a part assigned to them by name; a part left with "anyone in Data Entry" while it's still with Data Entry (shared queue); or one they've worked on (data_entry_started_by/completed_by, or a data_entry rfq_steps row worked_by them, which survives returns). New RFQ lists and searches must go through Rfq::scopeVisibleTo($user). EnsureRfqIsVisible guards every {rfq}/{attachment} route with a 403. Admin, and anyone holding another workflow role, see everything (User::seesEveryRfq()).

## RFQ numbers start from Settings → RFQ Numbers (Setting::rfqNumberStart())
Since 2026-10-16, an Admin sets the number new RFQs start from (stored as rfq_number_start, an integer; 1670 means RFQ1670). Rfq::nextRfqNumber() returns max(lastRfqSequence() + 1, Setting::rfqNumberStart()), so a number that has already been given out is never reused. Always generate numbers through nextRfqNumber(), and always format them through Rfq::formatRfqNumber(). There's still no unique index on rfqs.rfq_number.

## Clearing RFQ data goes through App\RfqDataEraser
Since 2026-10-16, `php artisan rfq:clear` and Settings → Danger Zone (SettingsController::clearRfqData()) both call RfqDataEraser::clear(). It truncates rfqs and every table with an rfq_id column, deletes the comment files and the DataEntryAssigned alerts, and bumps LiveVersion (on MySQL a truncate isn't a write LiveVersion watches). The Settings route is Admin only and requires typing CLEAR_RFQ_DATA_PHRASE plus the Admin's current password; it's throttled and logged as a warning. If a new table holds RFQ data without an rfq_id column, it must be added to the eraser.

## Backups go through App\BackupManager (Admin → Backups)
Since 2026-10-16, backups are zips in storage/app/private/backups. Each holds manifest.json, database/<table>.jsonl for every table in this app's own database (BackupManager::databaseTables(), scoped to the current schema because the MySQL server hosts other databases) except SKIPPED_TABLES (cache, sessions, jobs), and optionally files/<disk>/<dir> for avatars and rfq-attachments. backup:scheduled runs every minute: it makes the daily and weekly backups once their time has passed (Setting::backupSchedule()) and prunes anything older than RETENTION_DAYS (7) by file mtime. A restore first makes a 'safety' backup, empties and refills the tables in one transaction with FK checks off (deferred on SQLite), then Cache::flush(). It refuses backups that contain migrations this app doesn't have. When adding a new upload folder, add it to fileDirectories(). Never list tables with an unscoped Schema::getTableListing().

## Deactivated users (users.deactivated_at): can't sign in, never offered for work
Since 2026-10-16, an Admin can deactivate or activate an account (UserController::deactivate()/activate(); never their own). A deactivated user is refused at login and signed out on their next request by the EnsureAccountIsActive middleware on the admin routes; their database sessions are also deleted. scopeHoldingRole() and roleMembers() skip them, so do AttendanceSheet::people() and the idle-alert recipients. Any picker or validation using Spatie's User::role() must add ->active(). Deactivating hands their unstarted, named Data Entry parts back to "anyone" and clears default_data_entry_user_id that points to them. Never use a global scope to hide deactivated users: relations like creator must still resolve so history keeps their name.

## Assign to Data Entry asks for line items to add (rfq_user.line_items_to_add)
Since 2026-10-16, RfqController::completeSourcing() requires line_items: a whole number from 1 to MAX_LINE_ITEMS. Rfq::completeSourcingPart($part, $comment, $dataEntry, $lineItems) saves it on the part and adds meta line_items to the sourcing_completed comment. The value is set again on every re-assignment; the popup pre-fills the previous value via data-line-items. It's shown on Data Entry's Ready list, in the hint of their Send to Finalize popup, and on Sourcing's With Data Entry tab. It drives Data Entry's target: line items × time per item (see "Data Entry's target"). HTTP tests that post complete-sourcing must include line_items.

## Line items discrepancy: RfqAssignment::lineItemsDiscrepancy()
Since 2026-10-18, once Data Entry sends a part to finalize, the discrepancy is data_entry_entries minus line_items_to_add (negative means fewer). It is null when they match, when either count is missing, or before Send to Finalize, because entries aren't cleared on a return. Read it through lineItemsDiscrepancy() and lineItemsDiscrepancyLabel() ("2 fewer than the 24 requested"); never subtract in a view. Since 2026-10-21 the alert reads "RFQ1686 — Life Jackets: Visal Fernando added 3 line items, but Sourcing asked for 1 (2 more than requested)." (LineItemsDiscrepancy::messageFor()), and the timeline rebuilds it from the stored counts (timelineDetail()), so older alerts read the same. On a mismatch, RfqController::completeDataEntry() sends LineItemsDiscrepancy (database only, opens the RFQ) to active Senior Operations and General Manager users (RECIPIENT_ROLES), once per Send to Finalize. The data_entry_completed comment gets meta to_add only on a mismatch. RfqDataEraser deletes these alerts too.

## Data Entry's Pause alerts Senior Operations and the GM (DataEntryPaused)
Since 2026-10-18, every successful Pause (RfqController::pauseDataEntry()) sends DataEntryPaused to active Senior Operations and General Manager users. The alert is database only; it names who paused (Admin when they pause on Data Entry's behalf), the reason (shortened to 120 characters in the message, full in data.reason) and the reference, and it opens the RFQ. A refused pause or a Resume sends nothing. To look up "everyone active in these roles", use User::scopeHoldingAnyRole($roles), as LineItemsDiscrepancy does. RfqDataEraser deletes these alerts too.

## @mentions on any RFQ go to Messages (private_messages.rfq_comment_id)
Since 2026-10-19, RfqCommentController::store() calls RfqComment::messageMentionedUsers() on every comment and reply. It creates a PrivateMessage from the author to each person mentioned: "Mentioned you [in a reply] on [closed ]RFQ… — subject:\n" followed by the comment body. Until 2026-10-21 this happened only on closed RFQs; at the user's request it now happens on every RFQ, whether open, on hold, cancelled or closed. It never goes to the author. Mentionable people are RfqComment::mentionableUsers() (since 2026-10-20 all active staff, as in the @mention dropdown). mentionedUsers() matches whole names, longest first, and everyone sharing a name gets it. rfq_comment_id links the message so the conversation can show "Open RFQ…", but only to someone who can open that RFQ (rfqs.view and Rfq::isVisibleTo()). It cascades on comment delete, and RfqDataEraser deletes these messages.

## Performance reports' definitions live in App\PerformanceReports
Since 2026-10-19, reports for Admin, HR Executive and (since 2026-10-20) Senior Operations (User::PERFORMANCE_REPORT_ROLES, canViewPerformanceReports(); PerformanceReportController: sourcing-daily, data-entry-daily, sourcing-monthly, rfq-tracking) implement the business's reporting spec. Shared definitions:
- An RFQ counts once per person and once in a total; items are rfqs.number_of_items.
- Received is the person's first Sourcing stretch on the part (rfq_steps), else rfq_user.created_at.
- Finalized and quotation completed are rfq_user.finalized_at / finalized_by. Data Entry completed is data_entry_completed_at / by.
- Pending is received and not yet finalized at the end of the day, excluding cancelled parts and those waiting for customer clarification, which are counted apart.
- Hold state on a past day is rebuilt from put_on_hold / cancelled / resumed / reopened comments (meta.part, meta.customer_clarification), never from the current status.
Change a definition here, not in a view, so all four reports stay consistent.

## Roles are looked up by name: HR Manager is now HR Executive
Since 2026-10-19, the HR role is "HR Executive" (formerly HR Manager). The migration rename_hr_manager_role_to_hr_executive renames the role, or folds HR Manager into an existing HR Executive, and older migrations still say HR Manager as history. Access is checked by role name (hasRole, hasAnyRole, User::*_ROLES constants, Rfq::WORKFLOW_ROLES), so renaming a role on the Roles page silently removes its access everywhere. That happened here: the role was renamed in the UI before the code was. A role rename needs a code change and a migration, not only the Roles page.

## Work given to someone by name also goes to their Messages (App\TaskMessages)
Since 2026-10-21, at the client's request, every task that reaches a specific person is also sent as a PrivateMessage from whoever did it, with private_messages.rfq_id linking back to the RFQ. The message appears in their Messages, in their bell, and as a pop-up. The Rfq workflow methods send it themselves:
- assignSourcingParts($parts, $by): the controller must pass $by, or nobody is told.
- completeSourcingPart: to the named Data Entry person, or to all of Data Entry when it's left to anyone.
- completeDataEntryPart: to the Sourcing member, to finalize.
- returnSourcingPart and returnToDataEntry.
- rejectToStage and rejectPartToStage, through peopleAtStage(). A target of sourcing is told once, via returnSourcingPart.
- replaceSourcingPart: to both the new and the old member.
- forwardBack.
A new path that hands work to a person should call TaskMessages::send(). It is never sent to the actor or to deactivated users. Because private_messages now has an rfq_id, RfqDataEraser::KEPT_TABLES excludes it from truncation. Only RFQ-linked messages are deleted, so never drop that exclusion, or Clear RFQ data would wipe everyone's personal messages.
