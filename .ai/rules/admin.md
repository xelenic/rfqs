---
paths:
  - app/Http/Controllers/Admin/UserController.php
  - app/Http/Controllers/Admin/SettingsController.php
  - app/Http/Controllers/Admin/DashboardController.php
  - app/Http/Controllers/Admin/RfqController.php
  - app/Http/Controllers/Admin/PerformanceReportController.php
---

# Admin

## Only an Admin can give the Admin role, or set a new user's password
HR Executive holds users.view and users.create (migration give_hr_manager_user_permissions, BusinessRoleSeeder). UserController::assignableRoles() leaves Admin out for anyone who isn't Admin, in both the form and the validation of store and update. Keep that guard on any new role-assigning path. By default a new account gets a random password and an AccountCreated email with a "new_users" broker link (7 days, single use; config/auth.php) to Auth\SetPasswordController. Only an Admin may instead choose sign_in=password and type one, in which case no email is sent; the validation refuses that choice for anyone else. Locally MAIL_MAILER=log, so mails land in storage/logs.

## Only Admin and HR Executive change people's details
User::USER_DETAILS_ROLES (Admin, HR Executive) are the only roles that change a name or email: their own on Settings → Profile (SettingsController::updateProfile() aborts 403 for anyone else, and the tab shows read-only fields), and others' on the Users page (HR Executive holds users.edit, migration give_hr_manager_users_edit_permission). UserController::abortUnlessCanManage() keeps an Admin's account to Admins, because a non-Admin changing an Admin's email and sending a password link would take it over. Only an Admin sets a password on edit. Everyone still changes their own password.

## Role and personal hours: Admin and HR Executive; company hours: Admin only
Since 2026-10-15, a role's own working hours (Settings → Role Hours) and a person's own hours (their Users page) can be set by anyone in User::WORKING_HOURS_ROLES (Admin, HR Executive), checked with canManageWorkingHours(). This is also true for an Admin's own hours. Everything else stays Admin-only: the company Working Hours, Half Day, Extra Hours and the other Settings tabs. HR Executive sees Role Hours as the only tab in their own section of the Settings sidebar.

## Approver dashboards (General Manager, Head of BD) share one overview
Since 2026-10-18, the General Manager, the Head of Business Development and GM Assistant each get their own dashboard (views general-manager / head-of-business-development / gm-assistant, all @include admin/dashboard/_approver). Both are built by approverOverview() / approverDecisions() / approverNotifications() from approverStage($role). That stage config holds: the waiting scope and part check; the by/at columns; the 'since' column approval time is measured from (senior_ops_reviewed_at for the Head, gm_assistant_completed_at for the GM); who sends it on; returns (Rfq::returnedToHeadOfBd() / returnedToGmAssistant()) plus their Returns URL for the roles the General Manager sends back to; countdown/countdownStep for a step with a target (GM Assistant's); and words for how the step reads (approve vs submit). A new approver role should add a stage here, not a copy of the methods. Admin keeps the Admin dashboard even when holding these roles.

## HR Executive has a dashboard of their own (hrOverview)
Since 2026-10-19, HR Executive (and not Admin) gets admin/dashboard/hr-executive instead of the generic one. The branch comes just before the fallback, so a workflow role's dashboard still wins for someone who holds both. hrOverview() covers today's sheet from AttendanceSheet::people(), using marks whether approved or not. Its attendance over the period counts each person's own working days (Setting::weekOn()) from attendanceSince, by the approved sheets only (Attendance::book()); a day not yet approved is "Not counted yet", and a half day counts as half towards the rate. hrNotifications() raises sheets awaiting approval, today's sheet not in, earlier days without a sheet, returned sheets and who's on leave. It shows nothing about RFQs, since HR Executive has no rfqs.view.

## RFQ lists show 20 a page (RfqController::PER_PAGE)
Since 2026-10-20, at the user's request, every RFQ list paginates by RfqController::PER_PAGE (20): All RFQs, every role's queue, and the tabs that page on their own (sourcing_page, completed_page, with_data_entry_page, finalized_page, assigned_page, review_page). Use the constant for any new RFQ list; never a literal number. Tests that cross onto page 2 should create PER_PAGE + 1 RFQs.

## Tracking sheet's "Finalized by Sourcing" works per part, cancelled parts aside
Since 2026-10-21 the RFQ tracking sheet filters on ?finalized= (TRACKING_FINALIZED: all, not_finalized, finalized). Finalization is judged on the parts (rfq_user.finalized_at), not on rfqs.finalized_at:
- not_finalized: some part that isn't cancelled is still to finalize, or no part is assigned yet.
- finalized: at least one part is finalized and none that isn't cancelled is left.
- With a Sourcing member chosen (?person=), only that member's own parts are considered.
The column follows the same rule: "Not finalized yet", or the finalizers' names with an "N of M parts" badge while a split is only partly finalized. The footer counts "not finalized" the same way. A part's finalizer is finalized_by, falling back to its holder.
