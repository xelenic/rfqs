---
paths:
  - routes/console.php
---

# Routes

## Scheduled tasks need the scheduler running
Scheduled commands (e.g. rfq:alert-idle-data-entry, every minute, sending Senior Operations' Data Entry idle alerts) only run when the scheduler does. `composer run dev` does not start it, so run `php artisan schedule:work` locally, and in production add a cron entry for `php artisan schedule:run` every minute. Without it, alerts silently never fire.
