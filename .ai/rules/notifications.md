---
paths:
  - 'app/Notifications/**'
---

# Notifications

## RFQ alerts show on the RFQ's timeline (Rfq::sentAlerts())
Since 2026-10-20, any database notification whose data has rfq_id appears in that RFQ's Activity Timeline as a 'notification' entry, read from the notifications table. That includes LineItemsDiscrepancy, DataEntryPaused, DataEntryAssigned and past ones. One send to several people is one entry, grouped by type, second and part, naming the recipients. A new RFQ notification should put rfq_id (and part) in toArray() and define TIMELINE_TITLE and TIMELINE_TONE. If its message reads "to you", it also needs timelineDetail(). The entries are blurred for Sourcing, who never learns who in Data Entry it was. Notifications not about an RFQ (DataEntryIdle) stay off the timeline.
