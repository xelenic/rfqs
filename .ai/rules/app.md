---
paths:
  - 'app/**'
---

# App

## Qualify rfqs.status in queries joining rfq_user
rfq_user has its own `status` column (a part stopped on its own, Rfq::changePartStatus()). Any query that joins rfqs to rfq_user must say `rfqs.status` or `rfq_user.status`, never a bare `status`. Otherwise SQLite and MySQL fail with "ambiguous column name". That covers the assignees and assignedRfqs relations, whereHas('assignees') and DB::table('rfq_user')->join('rfqs'). Example: DashboardController's `$user->assignedRfqs()->where('rfqs.status', 'Pending')`.
