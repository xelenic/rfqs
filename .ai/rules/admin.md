---
paths:
  - app/Http/Controllers/Admin/UserController.php
---

# Admin

## Only an Admin can give the Admin role, or set a new user's password
HR Manager holds users.view and users.create (migration give_hr_manager_user_permissions, BusinessRoleSeeder). UserController::assignableRoles() leaves Admin out for anyone who isn't Admin, in both the form and the validation of store and update. Keep that guard on any new role-assigning path. By default a new account gets a random password and an AccountCreated email with a "new_users" broker link (7 days, single use; config/auth.php) to Auth\SetPasswordController. Only an Admin may instead choose sign_in=password and type one, in which case no email is sent; the validation refuses that choice for anyone else. Locally MAIL_MAILER=log, so mails land in storage/logs.
