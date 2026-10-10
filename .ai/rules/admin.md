---
paths:
  - app/Http/Controllers/Admin/UserController.php
---

# Admin

## Only an Admin can give the Admin role, or set a new user's password
HR Manager holds users.view and users.create (migration give_hr_manager_user_permissions, BusinessRoleSeeder). UserController::assignableRoles() leaves Admin out for anyone who isn't Admin, in both the form and the validation of store and update. Keep that guard on any new role-assigning path. By default a new account gets a random password and an AccountCreated email with a "new_users" broker link (7 days, single use; config/auth.php) to Auth\SetPasswordController. Only an Admin may instead choose sign_in=password and type one, in which case no email is sent; the validation refuses that choice for anyone else. Locally MAIL_MAILER=log, so mails land in storage/logs.

## Only Admin and HR Manager change people's details
User::USER_DETAILS_ROLES (Admin, HR Manager) are the only roles that change a name or email: their own on Settings → Profile (SettingsController::updateProfile() aborts 403 for anyone else, and the tab shows read-only fields), and others' on the Users page (HR Manager holds users.edit, migration give_hr_manager_users_edit_permission). UserController::abortUnlessCanManage() keeps an Admin's account to Admins, because a non-Admin changing an Admin's email and sending a password link would take it over. Only an Admin sets a password on edit. Everyone still changes their own password.
