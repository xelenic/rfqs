---
paths:
  - public/js/admin-dashboard.js
---

# Js

## Dashboard charts: no red/green pairs, one measure per axis
Don't pair the status colours --rfq-success with --rfq-danger in a chart (e.g. on-time vs late). The validator fails that pair for colour-blind readers (deutan ΔE 4.5 in dark, 6.2 in light). Show a share as a single-series percentage instead (the "share" type), with the counts as direct labels. Never put two measures of different scale on one axis, such as parts and entries. Use a switch instead (the "metric" type with .js-chart-metric) or two charts. Role hues come from the --rfq-role-* tokens: one entity, one colour.
