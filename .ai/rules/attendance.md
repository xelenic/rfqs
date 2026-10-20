---
paths:
  - 'resources/views/admin/attendance/**'
---

# Attendance

## Attendance sheet popups are shared by the list and each sheet's page
Since 2026-10-21 each sheet has its own page (admin.attendance.show). The Edit popup (_sheet_modal) and HR Executive's Return popup (_return_modal) are partials included on both index and show. On the show page, forms send return_to=show, and AttendanceController::backFrom() redirects back there instead of to the list. The list's Actions cell stacks its buttons (flex-column) and the Submitted-by and approval cells may wrap, so the actions stay in view at 1280–1600px. Don't add text-nowrap back to those cells.

## HR reads leave by type, with hours and duration; no Present count on the list
Since 2026-10-21, at HR Executive's request, a leave is shown by type everywhere HR reviews it: Full-Day Leave, Half-Day Leave – First Half (morning), – Second Half (afternoon), or – Custom Time. The type comes from Attendance::leaveTypeLabel(), the hours off from hoursOff($date), and the working time from workingSecondsOff($date) (lunch excluded; a full day is their own working day, from weekOn()). Show them with _leave_type.blade.php and don't hand-write "On leave" or "Half day · Morning off". The list has no Present column (HR found "9 present" unclear and asked for it to be removed), and each row ([data-row-href], admin.js) opens the sheet's page. The sheet's page leads with a "Leave to review" table: person, type, date, time off, duration, note, with full days first.
