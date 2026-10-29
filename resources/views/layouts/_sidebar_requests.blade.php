{{--
    The sidebar's request links for every role but Admin (whose own, grouped by
    role, is _sidebar_admin_groups.blade.php): the one actionable queue for the
    signed-in role, with its backlog count, plus that role's second queue —
    and, for every role a reviewer can reject work back to, its Rejected page
    (?view=rejected, RfqController::index() $onRejectedPage), apart from its
    Returns (what a co-worker sent back).
--}}
@php
    // Each role's own actionable backlog on their "Pending
    // RFQs" link, as a red count so it doesn't get lost in
    // the list — Operations: not yet assigned to Sourcing (those sent back
    // to them are on their Returns link instead);
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
        auth()->user()->hasRole('Senior Operations') => \App\Models\Rfq::awaitingAssignment()->count(),
        auth()->user()->hasRole('Sourcing') => \Illuminate\Support\Facades\DB::table('rfq_user')
            ->join('rfqs', 'rfqs.id', '=', 'rfq_user.rfq_id')
            ->where('rfqs.status', 'Pending')
            ->where('rfq_user.user_id', auth()->id())
            ->whereNull('rfq_user.status')
            ->whereNull('rfq_user.completed_at')
            ->whereNull('rfq_user.returned_at')
            ->count() + \App\Models\Rfq::awaitingFinalizeCount(auth()->id()),
        auth()->user()->hasRole('Data Entry') => \Illuminate\Support\Facades\DB::table('rfq_user')
            ->whereNull('status')
            ->whereNotNull('completed_at')
            ->whereNull('data_entry_completed_at')
            ->tap(fn ($parts) => \App\Models\RfqAssignment::whereForDataEntryUser($parts, auth()->id()))
            ->tap(fn ($parts) => \App\Models\RfqAssignment::whereNotSentBackTo($parts, 'data_entry'))
            ->count(),
        auth()->user()->hasRole('Head of Business Development') => \App\Models\Rfq::headOfBdReviewCount(),
        auth()->user()->hasRole('GM Assistant') => \App\Models\Rfq::gmAssistantReviewCount(),
        auth()->user()->hasRole('General Manager') => \App\Models\Rfq::gmReviewCount(),
        default => 0,
    };
    $pendingBadgeTitle = match (true) {
        auth()->user()->hasRole('Senior Operations') => "{$pendingBadgeCount} not yet assigned to Sourcing",
        auth()->user()->hasRole('Sourcing') => "{$pendingBadgeCount} to complete or finalize",
        auth()->user()->hasRole('Data Entry') => "{$pendingBadgeCount} not marked complete",
        auth()->user()->hasRole('Head of Business Development') => "{$pendingBadgeCount} awaiting your review",
        auth()->user()->hasRole('GM Assistant') => "{$pendingBadgeCount} awaiting your submit",
        auth()->user()->hasRole('General Manager') => "{$pendingBadgeCount} awaiting your approval",
        default => '',
    };
@endphp
<div class="sidebar-section-title">Requests</div>
<a href="{{ route('admin.rfqs.index', ['status' => 'Pending']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('status') === 'Pending' && ! in_array(request('view'), ['returns', 'rejected', 'review', 'closing'], true) ? 'active' : '' }}">
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
            ->whereNull('rfq_user.status')
            ->whereNull('rfq_user.completed_at')
            ->whereNotNull('rfq_user.returned_at')
            ->tap(fn ($parts) => \App\Models\RfqAssignment::whereNotRejectedTo($parts, 'sourcing'))
            ->count();
        $sourcingRejectedCount = \App\Models\Rfq::sourcingRejectedCount(auth()->id());
    @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span class="nav-link-label">Returns</span>
        @if ($returnedCount > 0)
            <span class="nav-link-count" title="{{ $returnedCount }} sent back by Data Entry or returned by a reviewer">{{ $returnedCount }}</span>
        @endif
    </a>
    {{-- Their parts a reviewer rejected back — on their Rejected page, and only there. --}}
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'rejected' ? 'active' : '' }}">
        <i class="bi bi-x-octagon"></i>
        <span class="nav-link-label">Rejected</span>
        @if ($sourcingRejectedCount > 0)
            <span class="nav-link-count" title="{{ $sourcingRejectedCount }} rejected back by a reviewer">{{ $sourcingRejectedCount }}</span>
        @endif
    </a>
@endif
@if (auth()->user()->hasRole('Data Entry'))
    @php
        $dataEntryReturnsCount = \App\Models\Rfq::dataEntryReturnsCount(auth()->id());
        $dataEntryRejectedCount = \App\Models\Rfq::dataEntryRejectedCount(auth()->id());
    @endphp
    {{-- Parts the Head of Business Development or the General Manager Returned to Data Entry — off Ready for Data Entry, on here. --}}
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span class="nav-link-label">Returns</span>
        @if ($dataEntryReturnsCount > 0)
            <span class="nav-link-count" title="{{ $dataEntryReturnsCount }} returned by a reviewer">{{ $dataEntryReturnsCount }}</span>
        @endif
    </a>
    {{-- Parts a reviewer rejected back to Data Entry — off Ready for Data Entry, on here. --}}
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'rejected' ? 'active' : '' }}">
        <i class="bi bi-x-octagon"></i>
        <span class="nav-link-label">Rejected</span>
        @if ($dataEntryRejectedCount > 0)
            <span class="nav-link-count" title="{{ $dataEntryRejectedCount }} rejected back by a reviewer">{{ $dataEntryRejectedCount }}</span>
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
    {{-- RFQs sent back to them — a Sourcing part, or a reviewer's Return — still waiting on them, one
         per row on the Returns page, as a red count, like the queues above. --}}
    @php
        $opsReturnsCount = \App\Models\Rfq::seniorOpsReturnsCount();
        $opsRejectedCount = \App\Models\Rfq::seniorOpsReturnsCount(rejected: true);
    @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span class="nav-link-label">Returns</span>
        @if ($opsReturnsCount > 0)
            <span class="nav-link-count" title="{{ $opsReturnsCount }} sent back to Senior Operations">{{ $opsReturnsCount }}</span>
        @endif
    </a>
    {{-- What a reviewer rejected back to them, still waiting on them. --}}
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'rejected' ? 'active' : '' }}">
        <i class="bi bi-x-octagon"></i>
        <span class="nav-link-label">Rejected</span>
        @if ($opsRejectedCount > 0)
            <span class="nav-link-count" title="{{ $opsRejectedCount }} rejected back by a reviewer">{{ $opsRejectedCount }}</span>
        @endif
    </a>
    {{-- The RFQs they've stopped — on hold, or cancelled, whole or a part of
         one — each with how many, in grey: they're waiting on nobody. --}}
    @foreach ([\App\Models\Rfq::ON_HOLD => 'bi-pause-circle', \App\Models\Rfq::CANCELLED => 'bi-x-circle'] as $stoppedStatus => $stoppedIcon)
        @php $stoppedCount = \App\Models\Rfq::stoppedAs($stoppedStatus)->count(); @endphp
        <a href="{{ route('admin.rfqs.index', ['status' => $stoppedStatus]) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('status') === $stoppedStatus ? 'active' : '' }}">
            <i class="bi {{ $stoppedIcon }}"></i>
            <span class="nav-link-label">{{ $stoppedStatus }}</span>
            @if ($stoppedCount > 0)
                <span class="badge badge-soft-secondary ms-auto" title="{{ $stoppedCount }} {{ strtolower($stoppedStatus) }}">{{ $stoppedCount }}</span>
            @endif
        </a>
    @endforeach
@endif
@if (auth()->user()->hasRole('Head of Business Development'))
    {{-- RFQs the General Manager Returned, or Rejected, to their review, still
         waiting on it — one per row on each page — as red counts, like the
         queue above. --}}
    @php
        $headOfBdReturnsCount = \App\Models\Rfq::headOfBdReturnsCount();
        $headOfBdRejectedCount = \App\Models\Rfq::headOfBdReturnsCount(rejected: true);
    @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span class="nav-link-label">Returns</span>
        @if ($headOfBdReturnsCount > 0)
            <span class="nav-link-count" title="{{ $headOfBdReturnsCount }} returned to you">{{ $headOfBdReturnsCount }}</span>
        @endif
    </a>
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'rejected' ? 'active' : '' }}">
        <i class="bi bi-x-octagon"></i>
        <span class="nav-link-label">Rejected</span>
        @if ($headOfBdRejectedCount > 0)
            <span class="nav-link-count" title="{{ $headOfBdRejectedCount }} rejected back to you">{{ $headOfBdRejectedCount }}</span>
        @endif
    </a>
@endif
@if (auth()->user()->hasRole('GM Assistant'))
    {{-- RFQs the General Manager Returned, or Rejected, to them, still waiting
         on them — one per row on each page — as red counts, like the queue
         above. --}}
    @php
        $gmAssistantReturnsCount = \App\Models\Rfq::gmAssistantReturnsCount();
        $gmAssistantRejectedCount = \App\Models\Rfq::gmAssistantReturnsCount(rejected: true);
    @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span class="nav-link-label">Returns</span>
        @if ($gmAssistantReturnsCount > 0)
            <span class="nav-link-count" title="{{ $gmAssistantReturnsCount }} returned by the General Manager">{{ $gmAssistantReturnsCount }}</span>
        @endif
    </a>
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'rejected' ? 'active' : '' }}">
        <i class="bi bi-x-octagon"></i>
        <span class="nav-link-label">Rejected</span>
        @if ($gmAssistantRejectedCount > 0)
            <span class="nav-link-count" title="{{ $gmAssistantRejectedCount }} rejected back by the General Manager">{{ $gmAssistantRejectedCount }}</span>
        @endif
    </a>
@endif
@if (auth()->user()->hasRole('General Manager'))
    @php $gmRejectedCount = \App\Models\Rfq::gmRejectedCount(); @endphp
    {{-- Parts Business Development rejected back to them at closing — off their Review, on here. --}}
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'rejected' ? 'active' : '' }}">
        <i class="bi bi-x-octagon"></i>
        <span class="nav-link-label">Rejected</span>
        @if ($gmRejectedCount > 0)
            <span class="nav-link-count" title="{{ $gmRejectedCount }} rejected back by Business Development">{{ $gmRejectedCount }}</span>
        @endif
    </a>
@endif
@if (auth()->user()->hasRole('Business Development'))
    {{-- RFQs Senior Operations sent back for details — one per row on the
         Returns page — as a red count, like the queues above; what a reviewer
         rejected back to them is on their Rejected page. --}}
    @php
        $bdReturnsCount = \App\Models\Rfq::bdReturnsCount(auth()->user()->ownRfqsOnlyId());
        $bdRejectedCount = \App\Models\Rfq::bdReturnsCount(auth()->user()->ownRfqsOnlyId(), rejected: true);
    @endphp
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'returns' ? 'active' : '' }}">
        <i class="bi bi-arrow-counterclockwise"></i>
        <span class="nav-link-label">Returns</span>
        @if ($bdReturnsCount > 0)
            <span class="nav-link-count" title="{{ $bdReturnsCount }} sent back by Senior Operations or returned by a reviewer">{{ $bdReturnsCount }}</span>
        @endif
    </a>
    {{-- What a reviewer rejected all the way back to them. --}}
    <a href="{{ route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']) }}" class="nav-link {{ request()->routeIs('admin.rfqs.index') && request('view') === 'rejected' ? 'active' : '' }}">
        <i class="bi bi-x-octagon"></i>
        <span class="nav-link-label">Rejected</span>
        @if ($bdRejectedCount > 0)
            <span class="nav-link-count" title="{{ $bdRejectedCount }} rejected back by a reviewer">{{ $bdRejectedCount }}</span>
        @endif
    </a>
@endif
@if (auth()->user()->hasRole('Business Development'))
    {{-- What the General Manager has approved and is waiting to be closed — one
         per row on the Ready to Close page — as a red count, like the queues above. --}}
    @php $closingCount = \App\Models\Rfq::bdClosingCount(auth()->user()->ownRfqsOnlyId()); @endphp
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
