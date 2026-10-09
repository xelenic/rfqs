{{--
    The top bar's bell: the signed-in user's alerts — their database
    notifications, such as App\Notifications\DataEntryIdle — latest first,
    and how many are unread. Opening one reads it and goes on to what it's
    about (Admin\NotificationController). live.js keeps the count and the
    list up to date. Only for those who get alerts
    (DataEntryIdle::RECIPIENT_ROLES).
--}}
@php
    $alerts = auth()->user()->notifications()->latest()->limit(8)->get();
    $unreadAlerts = auth()->user()->unreadNotifications()->count();
@endphp
<div class="dropdown notification-bell" id="notificationBell">
    <button type="button" class="notification-bell-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Alerts">
        <i class="bi {{ $unreadAlerts ? 'bi-bell-fill' : 'bi-bell' }}" aria-hidden="true"></i>
        <span class="visually-hidden">Alerts</span>
        @if ($unreadAlerts)
            <span class="notification-bell-count" title="{{ $unreadAlerts }} unread">{{ $unreadAlerts }}</span>
        @endif
    </button>
    <div class="dropdown-menu dropdown-menu-end notification-menu">
        <div class="notification-menu-head">
            <span>Alerts</span>
            @if ($unreadAlerts)
                <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0">Mark all as read</button>
                </form>
            @endif
        </div>
        <div class="notification-menu-items">
            @forelse ($alerts as $alert)
                <a href="{{ route('admin.notifications.open', $alert->id) }}" @class(['notification-item', 'is-unread' => $alert->read_at === null])>
                    <i class="bi bi-hourglass-bottom notification-item-icon" aria-hidden="true"></i>
                    <span class="notification-item-body">
                        <span class="notification-item-text">{{ $alert->data['message'] ?? 'Alert' }}</span>
                        <span class="notification-item-time" title="{{ $alert->created_at->setTimezone(\App\Models\Setting::timezone())->format('M d, Y g:i A') }}">
                            {{ $alert->created_at->diffForHumans() }} &middot; message them
                        </span>
                    </span>
                </a>
            @empty
                <div class="notification-empty">No alerts yet.</div>
            @endforelse
        </div>
    </div>
</div>
