<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The alerts in the top bar's bell (layouts/_notification_bell.blade.php) —
 * the signed-in user's own database notifications, such as
 * App\Notifications\DataEntryIdle. Nobody opens anyone else's.
 */
class NotificationController extends Controller
{
    /**
     * Opens an alert: read now, and on to what it's about — the idle Data
     * Entry person's conversation in Messages, to nudge them.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $alert = $request->user()->notifications()->findOrFail($notification);

        if ($alert->read_at === null) {
            $alert->markAsRead();
        }

        $about = $alert->data['data_entry_user_id'] ?? null;

        return $about !== null
            ? redirect()->route('admin.messages.show', $about)
            : back();
    }

    /**
     * Marks every alert of theirs read.
     */
    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
