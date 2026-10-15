---
paths:
  - 'app/**'
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

## Data Entry's target: Rfq::dataEntryTargetSeconds($part), per item or by priority
Since 2026-10-15 Data Entry's target can be set per item (Setting::dataEntryPerItem(): on/off plus seconds per item). When it's on, a part's target is its items × seconds per item. Items come from Rfq::dataEntryItemsFor($part): the RFQ's number_of_items, split evenly across a split's parts and rounded up. When per-item is off, or the RFQ has no number_of_items, the priority target applies. Always pass the part to dataEntryTargetSeconds($part) and use dataEntryTargetBreakdown($part) to show the formula. Never read Setting::dataEntryTargets()[$priority] directly for a part's target.

## A part can be given to one Data Entry person (rfq_user.data_entry_assigned_to)
Since 2026-10-15, Sourcing's "Assign to Data Entry" can name a Data Entry person (Rfq::completeSourcingPart(..., $dataEntry)). That person is notified (DataEntryAssigned) and is the only one who sees and starts the part; Admin still sees and can act on all. Null means anyone in Data Entry, the old shared-queue behaviour. Anything listing, counting or starting Data Entry's waiting parts per person must go through RfqAssignment::whereForDataEntryUser() or Rfq::partIsForDataEntryUser(). That covers the Ready list and its count, the sidebar badge, the Data Entry dashboard, Start, and idleness via Rfq::dataEntryWaitingSince($userId). Each re-assignment after a return to Sourcing sets it again.

## Sourcing member's default Data Entry person (users.default_data_entry_user_id)
Since 2026-10-15, an Admin can set each Sourcing member's usual Data Entry person on that member's user page (UserController::updateDefaultDataEntry(), User::defaultDataEntryUser()). It only pre-selects the "Assign to Data Entry" picker; the member can still choose someone else or "anyone". The part's previous Data Entry person, if any, takes precedence (_complete_button sets data-de-assignee). Never assign automatically from the default without the member's submit. The column is nullOnDelete, so deleting that Data Entry account clears it.

## Who sees which RFQs: Rfq::scopeVisibleTo($user) and isVisibleTo()
Since 2026-10-15, two roles see only part of the RFQs. Business Development (User::seesOnlyOwnRfqs()) sees the ones they created. Data Entry (User::seesOnlyAssignedRfqs(), when it's their only workflow role) sees RFQs with a part assigned to them by name; a part left with "anyone in Data Entry" while it's still with Data Entry (shared queue); or one they've worked on (data_entry_started_by/completed_by, or a data_entry rfq_steps row worked_by them, which survives returns). New RFQ lists and searches must go through Rfq::scopeVisibleTo($user). EnsureRfqIsVisible guards every {rfq}/{attachment} route with a 403. Admin, and anyone holding another workflow role, see everything (User::seesEveryRfq()).

## RFQ numbers start from Settings → RFQ Numbers (Setting::rfqNumberStart())
Since 2026-10-16, an Admin sets the number new RFQs start from (stored as rfq_number_start, an integer; 1670 means RFQ1670). Rfq::nextRfqNumber() returns max(lastRfqSequence() + 1, Setting::rfqNumberStart()), so a number that has already been given out is never reused. Always generate numbers through nextRfqNumber(), and always format them through Rfq::formatRfqNumber(). There's still no unique index on rfqs.rfq_number.
