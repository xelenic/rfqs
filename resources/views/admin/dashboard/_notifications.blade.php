{{-- The signed-in user's alerts. Expects: $notifications. --}}
<div class="card">
    <div class="card-header">Notifications</div>
    <div class="card-body">
        @forelse ($notifications as $notice)
            <a href="{{ $notice['url'] }}" class="dashboard-notice dashboard-notice-{{ $notice['type'] }}">
                <span class="dashboard-notice-icon"><i class="bi {{ $notice['icon'] }}"></i></span>
                <span>
                    <span class="dashboard-notice-title">{{ $notice['title'] }}</span>
                    <span class="dashboard-notice-desc">{{ $notice['description'] }}</span>
                </span>
            </a>
        @empty
            <div class="dashboard-notice-empty">
                <i class="bi bi-check2-circle"></i>
                <p class="fw-semibold mb-1">You're all caught up!</p>
                <p class="text-muted-soft small mb-0">No outstanding alerts right now.</p>
            </div>
        @endforelse
    </div>
</div>
