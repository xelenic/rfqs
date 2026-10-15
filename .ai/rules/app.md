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
