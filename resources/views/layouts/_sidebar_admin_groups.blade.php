{{--
    Admin's sidebar: every step of the RFQ workflow, grouped by the role that
    owns it, each group listing that role's own pages (the ones its members
    see in their sidebar) with the size of its queue. Each page opens with
    ?role=<slug> so Admin sees it as that role does — see
    RfqController::index() ($lensRole).

    Laid out as a numbered timeline under one parent, "Role Stages": every
    role is a step, numbered in workflow order, with a line running down from
    one step's number to the next and its pages beside it (see
    .sidebar-step-number in admin.css). A step's number is highlighted while
    its queues have something waiting, and filled in while one of its pages is
    open. Each step collapses and expands; the ones left collapsed — the
    parent included — are remembered in this browser, and a collapsed
    heading carries its queue's count (the parent's, all of them added up) so
    nothing waiting is hidden.

    A role's pages that aren't RFQ lists — Senior Operations' Attendance and
    Time Spent — sit in its group too, as plain links to their own route. And
    every group ends with Closed RFQs, as every role's own sidebar does.
--}}
@php
    // Besides the queues: the attendance sheets to make (working days still
    // without one), correct (returned by HR Manager) or approve.
    $counts = \App\Models\Rfq::queueCounts() + ['attendance' => count(\App\Models\AttendanceSheet::missingDays())
        + \App\Models\AttendanceSheet::returnedCount()
        + \App\Models\AttendanceSheet::awaitingApprovalCount()];
    $onRfqList = request()->routeIs('admin.rfqs.index');
    $currentRole = $onRfqList ? \App\Models\Rfq::workflowRoleForSlug(request('role')) : null;
    $currentView = in_array(request('view'), \App\Models\Rfq::QUEUE_VIEWS, true) ? request('view') : null;

    // Per group: the pages under it and which of the counts its heading shows
    // while collapsed — a role's queues add up, each item in one only. (Sourcing's
    // pending parts leave out the returns, which have a count of their own.) A
    // page's "view" is the role's second queue; its first has none. A page
    // that isn't an RFQ list has a "route" (and the routes it's active on)
    // instead.
    $groups = [
        [
            'role' => 'Business Development', 'badge' => ['closing', 'bd_returns'],
            'links' => [
                ['label' => 'Pending RFQs', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => null, 'hint' => ''],
                ['label' => 'Returns', 'icon' => 'bi-arrow-counterclockwise', 'status' => 'Pending', 'view' => 'returns', 'count' => 'bd_returns', 'hint' => 'sent back by a reviewer'],
                ['label' => 'Ready to Close', 'icon' => 'bi-flag', 'status' => 'Pending', 'view' => 'closing', 'count' => 'closing', 'hint' => 'approved by the General Manager, waiting to be closed'],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
        [
            'role' => 'Senior Operations', 'badge' => ['unassigned', 'ops_review'],
            'links' => [
                ['label' => 'Unassigned RFQs', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'unassigned', 'hint' => 'not fully assigned to Sourcing'],
                ['label' => 'Review', 'icon' => 'bi-clipboard2-check', 'status' => 'Pending', 'view' => 'review', 'count' => 'ops_review', 'hint' => 'awaiting second review'],
                // Not in the heading's badge: every one of these is already
                // counted in Unassigned or Review above.
                ['label' => 'Returns', 'icon' => 'bi-arrow-counterclockwise', 'status' => 'Pending', 'view' => 'returns', 'count' => 'ops_returns', 'hint' => 'sent back by a reviewer'],
                ['label' => 'On Hold', 'icon' => 'bi-pause-circle', 'status' => \App\Models\Rfq::ON_HOLD, 'view' => null, 'count' => 'on_hold', 'hint' => 'on hold'],
                ['label' => 'Cancelled', 'icon' => 'bi-x-circle', 'status' => \App\Models\Rfq::CANCELLED, 'view' => null, 'count' => 'cancelled', 'hint' => 'cancelled'],
                ['label' => 'Attendance', 'icon' => 'bi-person-check', 'route' => 'admin.attendance.index', 'activeOn' => 'admin.attendance.*', 'count' => 'attendance', 'hint' => 'attendance sheets to make, correct or approve'],
                ['label' => 'Time Spent', 'icon' => 'bi-stopwatch', 'route' => 'admin.reports.time-spent', 'activeOn' => 'admin.reports.time-spent', 'count' => null, 'hint' => ''],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
        [
            'role' => 'Sourcing', 'badge' => ['sourcing_pending', 'sourcing_returns'],
            'links' => [
                ['label' => 'Pending RFQs', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'sourcing_pending', 'hint' => 'parts to complete or finalize'],
                ['label' => 'Returns', 'icon' => 'bi-arrow-counterclockwise', 'status' => 'Pending', 'view' => 'returns', 'count' => 'sourcing_returns', 'hint' => 'parts sent back by Data Entry'],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
        [
            'role' => 'Data Entry', 'badge' => ['data_entry'],
            'links' => [
                ['label' => 'Ready for Data Entry', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'data_entry', 'hint' => 'parts not marked complete'],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
        [
            'role' => 'Head of Business Development', 'badge' => ['head_of_bd', 'head_of_bd_returns'],
            'links' => [
                ['label' => 'Review', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'head_of_bd', 'hint' => 'awaiting approval'],
                ['label' => 'Returns', 'icon' => 'bi-arrow-counterclockwise', 'status' => 'Pending', 'view' => 'returns', 'count' => 'head_of_bd_returns', 'hint' => 'sent back by the General Manager'],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
        [
            'role' => 'GM Assistant', 'badge' => ['gm_assistant', 'gm_assistant_returns'],
            'links' => [
                ['label' => 'Review', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'gm_assistant', 'hint' => 'awaiting client details'],
                ['label' => 'Returns', 'icon' => 'bi-arrow-counterclockwise', 'status' => 'Pending', 'view' => 'returns', 'count' => 'gm_assistant_returns', 'hint' => 'sent back by the General Manager'],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
        [
            'role' => 'General Manager', 'badge' => ['gm_review'],
            'links' => [
                ['label' => 'Review', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'gm_review', 'hint' => 'awaiting final approval'],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
    ];

    // The parent's heading, while it's collapsed, holds every group's count
    // added up.
    $allGroupsCount = collect($groups)->sum(fn (array $group) => collect($group['badge'])->sum(fn (string $key) => $counts[$key]));

    // Whether a page is the one being viewed. Every group has Closed RFQs —
    // the company-wide list, the same whichever group it's opened from, but
    // with the group's role on its link, so only that group shows it open.
    $isLinkActive = fn (array $group, array $link) => isset($link['route'])
        ? request()->routeIs($link['activeOn'])
        : ($onRfqList
            && request('status') === $link['status']
            && $currentView === $link['view']
            && ($currentRole === $group['role'] || ($currentRole === null && $group['role'] === 'Business Development')));
@endphp

{{-- The root: one node, "Role Stages", with each role's group under it as a
     numbered step of the timeline. --}}
<div class="sidebar-group sidebar-group-root" data-sidebar-group="role-stages">
    <button type="button" class="sidebar-section-title sidebar-group-title"
            data-bs-toggle="collapse" data-bs-target="#sidebar-group-role-stages"
            aria-expanded="true" aria-controls="sidebar-group-role-stages">
        <i class="bi bi-chevron-right sidebar-group-chevron"></i>
        <i class="bi bi-layers"></i>
        <span class="sidebar-group-name">Role Stages</span>
        @if ($allGroupsCount > 0)
            <span class="nav-link-count sidebar-group-count" title="{{ $allGroupsCount }} waiting">{{ $allGroupsCount }}</span>
        @endif
    </button>
    <div class="collapse show sidebar-group-children" id="sidebar-group-role-stages">
        @foreach ($groups as $group)
            @php
                $groupSlug = \Illuminate\Support\Str::slug($group['role']);
                $groupCount = collect($group['badge'])->sum(fn (string $key) => $counts[$key]);
                $isCurrentStep = collect($group['links'])->contains(fn (array $link) => $isLinkActive($group, $link));
                $stepState = match (true) {
                    $isCurrentStep => 'is-current',
                    $groupCount > 0 => 'is-waiting',
                    default => '',
                };
            @endphp
            <div class="sidebar-group" data-sidebar-group="{{ $groupSlug }}">
                <button type="button" class="sidebar-section-title sidebar-group-title sidebar-step-title"
                        data-bs-toggle="collapse" data-bs-target="#sidebar-group-{{ $groupSlug }}"
                        aria-expanded="true" aria-controls="sidebar-group-{{ $groupSlug }}">
                    <span class="sidebar-step-number {{ $stepState }}" aria-hidden="true">{{ $loop->iteration }}</span>
                    <span class="sidebar-group-name"><span class="visually-hidden">Stage {{ $loop->iteration }}: </span>{{ $group['role'] }}</span>
                    @if ($groupCount > 0)
                        <span class="nav-link-count sidebar-group-count" title="{{ $groupCount }} waiting">{{ $groupCount }}</span>
                    @endif
                    <i class="bi bi-chevron-right sidebar-group-chevron"></i>
                </button>
                <div class="collapse show sidebar-group-links" id="sidebar-group-{{ $groupSlug }}">
                    @foreach ($group['links'] as $link)
                        @php
                            $isActive = $isLinkActive($group, $link);
                            $count = $link['count'] ? $counts[$link['count']] : 0;
                            $linkUrl = isset($link['route'])
                                ? route($link['route'])
                                : route('admin.rfqs.index', array_filter(['status' => $link['status'], 'view' => $link['view'], 'role' => $groupSlug]));
                        @endphp
                        <a href="{{ $linkUrl }}"
                           class="nav-link {{ $isActive ? 'active' : '' }}">
                            <i class="bi {{ $link['icon'] }}"></i>
                            <span class="nav-link-label">{{ $link['label'] }}</span>
                            @if ($count > 0)
                                <span class="nav-link-count" title="{{ $count }} {{ $link['hint'] }}">{{ $count }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>

{{-- Inline, straight after the groups, so the ones left collapsed last time
     are already collapsed on first paint rather than snapping shut once
     admin.js loads. A group holding the page being viewed always opens. --}}
<script>
    (function () {
        var key = 'rfqms.sidebar.collapsedGroups';
        var read = function () {
            try { return JSON.parse(localStorage.getItem(key)) || []; } catch (e) { return []; }
        };
        var write = function (slugs) {
            try { localStorage.setItem(key, JSON.stringify(slugs)); } catch (e) {}
        };

        var collapsed = read();
        document.querySelectorAll('[data-sidebar-group]').forEach(function (group) {
            if (collapsed.indexOf(group.dataset.sidebarGroup) === -1 || group.querySelector('.nav-link.active')) {
                return;
            }
            var toggle = group.querySelector('[data-bs-toggle="collapse"]');
            group.querySelector('.collapse').classList.remove('show');
            toggle.classList.add('collapsed');
            toggle.setAttribute('aria-expanded', 'false');
        });

        // Other parts of the page use collapses too — only the groups' matter here.
        ['hide', 'show'].forEach(function (action) {
            document.addEventListener(action + '.bs.collapse', function (event) {
                var group = event.target.closest('[data-sidebar-group]');
                if (!group) { return; }
                var slugs = read().filter(function (slug) { return slug !== group.dataset.sidebarGroup; });
                if (action === 'hide') { slugs.push(group.dataset.sidebarGroup); }
                write(slugs);
            });
        });
    })();
</script>
