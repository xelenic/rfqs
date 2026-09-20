{{--
    Admin's sidebar: every step of the RFQ workflow, grouped by the role that
    owns it, each group listing that role's own pages (the ones its members
    see in their sidebar) with the size of its queue. Each page opens with
    ?role=<slug> so Admin sees it as that role does — see
    RfqController::index() ($lensRole).

    Laid out as a tree: each role is a node that collapses and expands, with
    its pages hanging off it on branch lines (see .sidebar-group-links in
    admin.css). The nodes left collapsed are remembered in this browser, and a
    collapsed node's heading carries its queue's count so nothing waiting is
    hidden.
--}}
@php
    $counts = \App\Models\Rfq::queueCounts();
    $onRfqList = request()->routeIs('admin.rfqs.index');
    $currentRole = $onRfqList ? \App\Models\Rfq::workflowRoleForSlug(request('role')) : null;
    $currentView = in_array(request('view'), \App\Models\Rfq::QUEUE_VIEWS, true) ? request('view') : null;

    // Per group: the pages under it and which of the counts its heading shows
    // while collapsed — a role's queues add up, each item in one only. (Sourcing's
    // pending parts leave out the returns, which have a count of their own.) A
    // page's "view" is the role's second queue; its first has none.
    $groups = [
        [
            'role' => 'Business Development', 'icon' => 'bi-briefcase', 'badge' => ['closing'],
            'links' => [
                ['label' => 'Pending RFQs', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => null, 'hint' => ''],
                ['label' => 'Ready to Close', 'icon' => 'bi-flag', 'status' => 'Pending', 'view' => 'closing', 'count' => 'closing', 'hint' => 'approved by the General Manager, waiting to be closed'],
                ['label' => 'Closed RFQs', 'icon' => 'bi-check2-circle', 'status' => 'Completed', 'view' => null, 'count' => null, 'hint' => ''],
            ],
        ],
        [
            'role' => 'Senior Operations', 'icon' => 'bi-diagram-3', 'badge' => ['unassigned', 'ops_review'],
            'links' => [
                ['label' => 'Unassigned RFQs', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'unassigned', 'hint' => 'not fully assigned to Sourcing'],
                ['label' => 'Review', 'icon' => 'bi-clipboard2-check', 'status' => 'Pending', 'view' => 'review', 'count' => 'ops_review', 'hint' => 'awaiting second review'],
            ],
        ],
        [
            'role' => 'Sourcing', 'icon' => 'bi-people', 'badge' => ['sourcing_pending', 'sourcing_returns'],
            'links' => [
                ['label' => 'Pending RFQs', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'sourcing_pending', 'hint' => 'parts not marked complete'],
                ['label' => 'Returns', 'icon' => 'bi-arrow-counterclockwise', 'status' => 'Pending', 'view' => 'returns', 'count' => 'sourcing_returns', 'hint' => 'parts sent back by Data Entry'],
            ],
        ],
        [
            'role' => 'Data Entry', 'icon' => 'bi-keyboard', 'badge' => ['data_entry'],
            'links' => [
                ['label' => 'Ready for Data Entry', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'data_entry', 'hint' => 'parts not marked complete'],
            ],
        ],
        [
            'role' => 'Head of Business Development', 'icon' => 'bi-person-check', 'badge' => ['head_of_bd'],
            'links' => [
                ['label' => 'Review', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'head_of_bd', 'hint' => 'awaiting approval'],
            ],
        ],
        [
            'role' => 'GM Assistant', 'icon' => 'bi-file-earmark-text', 'badge' => ['gm_assistant'],
            'links' => [
                ['label' => 'Review', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'gm_assistant', 'hint' => 'awaiting client details'],
            ],
        ],
        [
            'role' => 'General Manager', 'icon' => 'bi-award', 'badge' => ['gm_review'],
            'links' => [
                ['label' => 'Review', 'icon' => 'bi-hourglass-split', 'status' => 'Pending', 'view' => null, 'count' => 'gm_review', 'hint' => 'awaiting final approval'],
            ],
        ],
    ];
@endphp

@foreach ($groups as $group)
    @php
        $groupSlug = \Illuminate\Support\Str::slug($group['role']);
        $groupCount = collect($group['badge'])->sum(fn (string $key) => $counts[$key]);
    @endphp
    <div class="sidebar-group" data-sidebar-group="{{ $groupSlug }}">
        <button type="button" class="sidebar-section-title sidebar-group-title"
                data-bs-toggle="collapse" data-bs-target="#sidebar-group-{{ $groupSlug }}"
                aria-expanded="true" aria-controls="sidebar-group-{{ $groupSlug }}">
            <i class="bi bi-chevron-right sidebar-group-chevron"></i>
            <i class="bi {{ $group['icon'] }}"></i>
            <span class="sidebar-group-name">{{ $group['role'] }}</span>
            @if ($groupCount > 0)
                <span class="nav-link-count sidebar-group-count" title="{{ $groupCount }} waiting">{{ $groupCount }}</span>
            @endif
        </button>
        <div class="collapse show sidebar-group-links" id="sidebar-group-{{ $groupSlug }}">
            @foreach ($group['links'] as $link)
                @php
                    // Closed RFQs is company-wide, not a role's own queue — no
                    // role on its link, and it's active whichever group it
                    // sits under.
                    $isCompanyWide = $link['status'] === 'Completed';
                    $isActive = $onRfqList
                        && request('status') === $link['status']
                        && $currentView === $link['view']
                        && ($isCompanyWide || $currentRole === $group['role'] || ($currentRole === null && $group['role'] === 'Business Development'));
                    $count = $link['count'] ? $counts[$link['count']] : 0;
                @endphp
                <a href="{{ route('admin.rfqs.index', array_filter(['status' => $link['status'], 'view' => $link['view'], 'role' => $isCompanyWide ? null : $groupSlug])) }}"
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
