{{--
    The Critical monitor's window: most of the screen, its content fetched
    from DashboardController::critical() when it opens and again every half
    minute while it's open, and on Refresh (public/js/critical-monitor.js).
    Outside the page body, so a live update never takes it away mid-look.
--}}
<div class="modal fade critical-modal" id="criticalMonitorModal" tabindex="-1" aria-labelledby="criticalMonitorTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable critical-modal-dialog">
        <div class="modal-content">
            <div class="modal-header critical-modal-header">
                <span class="critical-pulse" aria-hidden="true"></span>
                <div class="me-auto">
                    <h5 class="modal-title" id="criticalMonitorTitle">Critical monitor</h5>
                    <div class="critical-updated" id="criticalUpdated" aria-live="polite">Loading…</div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="criticalRefresh" title="Refresh now">
                    <i class="bi bi-arrow-clockwise"></i><span class="d-none d-sm-inline"> Refresh</span>
                </button>
                <button type="button" class="btn-close ms-2" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="criticalMonitorBody">
                <div class="critical-loading">
                    <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
                    Gathering what needs attention…
                </div>
            </div>
        </div>
    </div>
</div>
