---
paths:
  - 'app/Console/Commands/**'
---

# Commands

## GM Assistant has its own idle alert (AlertIdleGmAssistant)
Since 2026-10-20, GM Assistant is alerted on like Data Entry. rfq:alert-idle-gm-assistant (every minute, routes/console.php) sends GmAssistantIdle to Senior Operations and Admin (DataEntryIdle::RECIPIENT_ROLES) once per free stretch, when someone in GM Assistant has parts waiting (Rfq::gmAssistantWaitingSince(), anyone's to take), nothing running (RfqStep::runningGmAssistantOf()), and Setting::gmAssistantIdleAlert() minutes of working time have passed. The default is on, 10 minutes, set on Settings → Idle Alert in its own card (route settings.gm-assistant-idle-alert, bag gm_assistant_idle_alert). Data Entry's and GM Assistant's idleness share User::idlenessAt(): leave, half-day time off and lunch are handled once there. GM Assistant's Review page shows the same countdown banner (_data_entry_idle_banner with myGmAssistantIdle). Like the Data Entry alert, it needs the scheduler running.
