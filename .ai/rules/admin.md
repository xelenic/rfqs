---
paths:
  - app/Http/Controllers/Admin/UserController.php
  - app/Http/Controllers/Admin/SettingsController.php
  - app/Http/Controllers/Admin/DashboardController.php
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
