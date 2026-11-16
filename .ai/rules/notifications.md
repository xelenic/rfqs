---
paths:
  - 'app/Notifications/**'
---

# Notifications

## RFQ alerts show on the RFQ's timeline (Rfq::sentAlerts())
Since 2026-10-20, any database notification whose data has rfq_id appears in that RFQ's Activity Timeline as a 'notification' entry, read from the notifications table. That includes LineItemsDiscrepancy, DataEntryPaused, DataEntryAssigned and past ones. One send to several people is one entry, grouped by type, second and part, naming the recipients. A new RFQ notification should put rfq_id (and part) in toArray() and define TIMELINE_TITLE and TIMELINE_TONE. If its message reads "to you", it also needs timelineDetail(). The entries are blurred for Sourcing, who never learns who in Data Entry it was. Notifications not about an RFQ (DataEntryIdle) stay off the timeline.

## Closing an RFQ emails its creator (RfqReadyToShare)
Since 2026-11-16, at the user's request, Rfq::closeOut() emails the RFQ's creator (created_by, normally Business Development) once the whole RFQ is closed. That covers Close, and closePart() on its last part, and goes to the creator even when someone else (Admin) closed it. The business gave the wording, so don't reword it: subject "Your <RFQ number> is ready to share with customer (<WC number>)"; "Dear <name>,"; then the subject line followed by ". Please do the needful to share the final file with them at earliest possible." RfqReadyToShare::readyToShare() builds that line, and leaves the brackets out when there's no WC number. It's mail only, so it doesn't appear on the timeline. A deactivated or deleted creator gets nothing. The send is wrapped in try/catch with report(), so a mail failure never undoes or blocks the close. An Admin setting the status to Completed through Edit doesn't go through closeOut(), so it doesn't email.
