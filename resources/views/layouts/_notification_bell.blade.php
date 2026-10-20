{{--
    The top bar's bell, for everyone: their alerts — database notifications,
    such as App\Notifications\DataEntryIdle — and the private messages sent
    to them, one line per person, newest first, with how many of both are
    unread (User::bellItems()). An alert opens on what it's about
    (Admin\NotificationController); a message, on the conversation, which
    reads it. live.js keeps the count and the list up to date — popping up
    what's new as a toast, the count in the tab's title, and (once allowed,
    "Desktop alerts") a desktop notification while the tab's in the background.
    Whatever's unread that they haven't been shown yet pops up as the page
    opens too, once (data-user keys what this browser has shown them).
--}}
@php
    ['items' => $bellItems, 'unread' => $unreadCount] = auth()->user()->bellItems();
@endphp
<div class="dropdown notification-bell" id="notificationBell" data-user="{{ auth()->id() }}">
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
            {{-- Shown by live.js while the browser hasn't been asked yet: lets the
                 page notify them of new messages and alerts while it's in the
                 background. --}}
            <button type="button" class="btn btn-link btn-sm p-0 js-desktop-alerts d-none" title="Get a desktop notification for new messages and alerts while this tab is in the background">
                <i class="bi bi-display"></i> Desktop alerts
            </button>
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
                            {{ $item['at']->toBusinessTimezone()->format('M d, Y g:i A') }} &middot; {{ $item['note'] }}
                        </span>
                    </span>
                </a>
            @empty
                <div class="notification-empty">No alerts or messages yet.</div>
            @endforelse
        </div>
    </div>
</div>
