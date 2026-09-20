{{--
    The sidebar's request links for every role but Admin (whose own, grouped by
    role, is _sidebar_admin_groups.blade.php): the one actionable queue for the
    signed-in role, with its backlog count, plus that role's second queue.
--}}
@php
    // Each role's own actionable backlog on their "Pending
    // RFQs" link, as a red count so it doesn't get lost in
    // the list — Operations: not yet assigned to Sourcing;
    // Sourcing: their own parts not yet marked complete —
    // one per part — and not counting the ones Data Entry sent
    // back, which are on their Returns link (with its own count)
    // rather than on My Pending RFQs;
    // Data Entry: Sourcing-completed splits they haven't
    // marked complete yet — one per assignee, matching the
    // "By Sourcing" rows (see RfqController::index()
    // $bySourcingRfqs), since a split RFQ can hand off
    // more than one assignee's part to Data Entry.
    $pendingBadgeCount = match (true) {
        auth()->user()->hasRole('Senior Operations') => \App\Models\Rfq::where('status', 'Pending')
            ->needingSourcing()
            ->count(),
        auth()->user()->hasRole('Sourcing') => \Illuminate\Support\Facades\DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where('rfq_user.user_id', auth()->id())
            ->whereNull('rfq_user.completed_at')
            ->whereNull('rfq_user.returned_at')
            ->count(),
        auth()->user()->hasRole('Data Entry') => \Illuminate\Support\Facades\DB::table('rfq_user')
            ->whereNotNull('completed_at')
            ->whereNull('data_entry_completed_at')
            ->count(),
        auth()->user()->hasRole('Head of Business Development') => \App\Models\Rfq::headOfBdReviewCount(),
        auth()->user()->hasRole('GM Assistant') => \App\Models\Rfq::gmAssistantReviewCount(),
        auth()->user()->hasRole('General Manager') => \App\Models\Rfq::gmReviewCount(),
        default => 0,
    };
    $pendingBadgeTitle = match (true) {
        auth()->user()->hasRole('Senior Operations') => "{$pendingBadgeCount} not yet assigned to Sourcing",
        auth()->user()->hasRole('Sourcing') => "{$pendingBadgeCount} not marked complete",
        auth()->user()->hasRole('Data Entry') => "{$pendingBadgeCount} not marked complete",
        auth()->user()->hasRole('Head of Business Development') => "{$pendingBadgeCount} awaiting your review",
        auth()->user()->hasRole('GM Assistant') => "{$pendingBadgeCount} awaiting client details",
        auth()->user()->hasRole('General Manager') => "{$pendingBadgeCount} awaiting your approval",
        default => '',
    };
@endphp
<div class="sidebar-section-title">Requests</div>
<a href="{{ route('admin.rfqs.index', ['status' => 'Pending']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('status') === 'Pending' && ! in_array(request('view'), ['returns', 'review', 'closing'], true) ? 'active' : '' }}">
    <i class="bi bi-hourglass-split"></i>
    <span class="nav-link-label">
        {{ match (true) {
            auth()->user()->hasRole('Sourcing') => 'My Pending RFQs',
            auth()->user()->hasRole('Data Entry') => 'Ready for Data Entry',
            auth()->user()->hasRole('Senior Operations') => 'Unassigned RFQs',
            auth()->user()->hasRole('Head of Business Development') => 'Review',
            auth()->user()->hasRole('GM Assistant') => 'Review',
            auth()->user()->hasRole('General Manager') => 'Review',
            default => 'Pending RFQs',
        } }}
    </span>
    @if ($pendingBadgeCount > 0)
        <span class="nav-link-count" title="{{ $pendingBadgeTitle }}">{{ $pendingBadgeCount }}</span>
    @endif
</a>
@if (auth()->user()->hasRole('Sourcing'))
    {{-- Their parts Data Entry sent back for rework — one per row on the
         Returns list — as a red count, like the queue above it. --}}
    @php
        $returnedCount = \Illuminate\Support\Facades\DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where('rfq_user.user_id', auth()->id())
            ->whereNull('rfq_user.completed_at')
            ->whereNotNull('rfq_user.returned_at')
            ->count();
    @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span class="nav-link-label">Returns</span>
        @if ($returnedCount > 0)
            <span class="nav-link-count" title="{{ $returnedCount }} sent back by Data Entry">{{ $returnedCount }}</span>
        @endif
    </a>
@endif
@if (auth()->user()->hasRole('Senior Operations'))
    {{-- The approvals waiting — one per row on the Review page — as a red count,
         like the queue above it. --}}
    @php $reviewCount = \App\Models\Rfq::seniorOpsReviewCount(); @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'review' ? 'active' : '' }}">
        <i class="bi bi-clipboard2-check"></i>
        <span class="nav-link-label">Review</span>
        @if ($reviewCount > 0)
            <span class="nav-link-count" title="{{ $reviewCount }} awaiting your approval">{{ $reviewCount }}</span>
        @endif
    </a>
@endif
@if (auth()->user()->hasRole('Business Development'))
    {{-- What the General Manager has approved and is waiting to be closed — one
         per row on the Ready to Close page — as a red count, like the queues above. --}}
    @php $closingCount = \App\Models\Rfq::bdClosingCount(); @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'closing' ? 'active' : '' }}">
        <i class="bi bi-flag"></i>
        <span class="nav-link-label">Ready to Close</span>
        @if ($closingCount > 0)
            <span class="nav-link-count" title="{{ $closingCount }} ready to close">{{ $closingCount }}</span>
        @endif
    </a>
@endif
<a href="{{ route('admin.rfqs.index', ['status' => 'Completed']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('status') === 'Completed' ? 'active' : '' }}">
    <i class="bi bi-check2-circle"></i> Closed RFQs
</a>
