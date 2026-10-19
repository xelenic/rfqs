---
paths:
  - routes/console.php
---

# Routes

## Scheduled tasks need the scheduler running
Scheduled commands (rfq:alert-idle-data-entry and rfq:alert-idle-gm-assistant, every minute, which send Senior Operations' Data Entry and GM Assistant idle alerts; and backup:scheduled, every minute, which makes the daily and weekly backups and prunes old ones) only run when the scheduler does. `composer run dev` does not start it, so run `php artisan schedule:work` locally, and in production add a cron entry for `php artisan schedule:run` every minute. Without it, alerts silently never fire.
