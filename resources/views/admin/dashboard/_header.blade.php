{{--
    The greeting and "New RFQ" button at the top of every dashboard.
    Optional: $subtitle.
--}}
<div class="dashboard-header mb-4">
    <div>
        <div class="dashboard-eyebrow">{{ now()->format('l, F j, Y') }}</div>
        <h2 class="dashboard-title">Welcome back, {{ explode(' ', auth()->user()->name)[0] }} 👋</h2>
        <p class="dashboard-subtitle mb-0">{{ $subtitle ?? "Here's what's happening across ".\App\Models\Setting::companyName().' today.' }}</p>
    </div>

    @can('rfqs.create')
        <a href="{{ route('admin.rfqs.index') }}" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> New RFQ
        </a>
    @endcan
</div>
