{{--
    The top bar's bell, for everyone: their alerts — database notifications,
    such as App\Notifications\DataEntryIdle — and the private messages sent
    to them, one line per person, newest first, with how many of both are
    unread (User::bellItems()). An alert opens on what it's about
    (Admin\NotificationController); a message, on the conversation, which
    reads it. live.js keeps the count and the list up to date.
--}}
@php
    ['items' => $bellItems, 'unread' => $unreadCount] = auth()->user()->bellItems();
@endphp
<div class="dropdown notification-bell" id="notificationBell">
    <button type="button" class="notification-bell-btn" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" title="Alerts">
        <i class="bi {{ $unreadCount ? 'bi-bell-fill' : 'bi-bell' }}" aria-hidden="true"></i>
        <span class="visually-hidden">Alerts</span>
        @if ($unreadCount)
            <span class="notification-bell-count" title="{{ $unreadCount }} unread">{{ $unreadCount }}</span>
        @endif
    </button>
    <div class="dropdown-menu dropdown-menu-end notification-menu">
        <div class="notification-menu-head">
            <span>Alerts</span>
            @if ($unreadCount)
                <form method="POST" action="{{ route('admin.notifications.read-all') }}">
                    @csrf
                    <button type="submit" class="btn btn-link btn-sm p-0">Mark all as read</button>
                </form>
            @endif
        </div>
        <div class="notification-menu-items">
            @forelse ($bellItems as $item)
                <a href="{{ $item['url'] }}" @class(['notification-item', 'is-unread' => $item['unread'], 'is-message' => $item['kind'] === 'message'])>
                    <i class="bi {{ $item['icon'] }} notification-item-icon" aria-hidden="true"></i>
                    <span class="notification-item-body">
                        <span class="notification-item-text">{{ $item['text'] }}</span>
                        <span class="notification-item-time" title="{{ $item['at']->copy()->setTimezone(\App\Models\Setting::timezone())->format('M d, Y g:i A') }}">
                            {{ $item['at']->diffForHumans() }} &middot; {{ $item['note'] }}
                        </span>
                    </span>
                </a>
            @empty
                <div class="notification-empty">No alerts or messages yet.</div>
            @endforelse
        </div>
    </div>
</div>
