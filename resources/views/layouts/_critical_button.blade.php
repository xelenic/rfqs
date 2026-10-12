{{--
    The Critical monitor's button in the top bar — for those who run the
    operation (User::canSeeCriticalMonitor()). Its count is what's critical
    now — tasks overdue and people in Data Entry idle with parts waiting —
    kept a minute (DashboardController::criticalCount()); while there's
    anything, it pulses. live.js keeps it up to date. Opens
    layouts/_critical_modal.
--}}
@php $criticalCount = \App\Http\Controllers\Admin\DashboardController::criticalCount(); @endphp
<button type="button" @class(['critical-btn', 'is-alert' => $criticalCount > 0]) id="criticalMonitorBtn"
        data-bs-toggle="modal" data-bs-target="#criticalMonitorModal" data-url="{{ route('admin.critical-monitor') }}"
        title="{{ $criticalCount > 0 ? trans_choice('1 critical thing needs attention|:count critical things need attention', $criticalCount, ['count' => $criticalCount]) : 'Critical monitor — nothing critical now' }}">
    <i class="bi bi-activity" aria-hidden="true"></i>
    <span class="critical-btn-label">Critical</span>
    @if ($criticalCount > 0)
        <span class="critical-btn-count">{{ $criticalCount }}</span>
    @endif
</button>
