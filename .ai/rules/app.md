---
paths:
  - 'app/**'
---

# App

## Qualify rfqs.status in queries joining rfq_user
rfq_user has its own `status` column (a part stopped on its own, Rfq::changePartStatus()). Any query that joins rfqs to rfq_user must say `rfqs.status` or `rfq_user.status`, never a bare `status`. Otherwise SQLite and MySQL fail with "ambiguous column name". That covers the assignees and assignedRfqs relations, whereHas('assignees') and DB::table('rfq_user')->join('rfqs'). Example: DashboardController's `$user->assignedRfqs()->where('rfqs.status', 'Pending')`.

## Count "a day" in Setting::timezone(), query in UTC
Times are stored in the app timezone (UTC), but a business day is the working hours' timezone (Setting::timezone(), Asia/Colombo by default). Build day/period boundaries with CarbonImmutable::now(Setting::timezone())->startOfDay(), then ->setTimezone(config('app.timezone')) before passing them to a query: query bindings format a Carbon as-is without converting zones. A bare now()->startOfDay() is UTC midnight (5:30 AM in Colombo). See DashboardController::summaryPeriod(). Count returns from rfq_comments with action 'rejected' (meta.stage = target label), not rfqs.rejected_at, which is cleared once Business Development fixes the RFQ.
