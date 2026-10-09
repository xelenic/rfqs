---
paths:
  - 'resources/views/admin/settings/**'
---

# Settings

## Target forms share the targets[] field name
The Sourcing Targets and Data Entry Targets tabs both post targets[Low|Medium|High|Urgent]. Only refill a form with old() when its own error bag (sourcing_targets / data_entry_targets) has errors, otherwise a failed save on one tab leaks its typed values into the other. Any new per-priority target tab must follow the same pattern and reuse SettingsController::validatedTargets().
