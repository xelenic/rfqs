---
paths:
  - 'resources/views/admin/settings/**'
---

# Settings

## Target forms share the targets[] field name
The Sourcing Targets, Data Entry Targets and Finalize Targets tabs all post targets[Low|Medium|High|Urgent]. Only refill a form with old() when its own error bag (sourcing_targets / data_entry_targets / finalize_targets) has errors, otherwise a failed save on one tab leaks its typed values into the other. Any new per-priority target tab must follow the same pattern and reuse SettingsController::validatedTargets().

## Data Entry's per-priority targets are hidden
Since 2026-10-16, the Data Entry Targets card (per-priority hours) isn't on the Settings page. Its tab is now "Data Entry Time" (id data-entry-targets) and shows only the Time per item card, because Data Entry's time is line items × time per item. The saved or default priority targets still apply to parts with no line items while per item is off, and SettingsController::updateDataEntryTargets() and its route stay. Don't bring the card back without asking.
