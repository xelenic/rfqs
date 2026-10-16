<?php

namespace App;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * An Admin signed in as someone else — to see the panel as they do, or do a
 * step for them. The Admin's id is kept in the session while it lasts; the
 * layout says whose account it is and offers the way back (stop()). Only an
 * Admin starts one (canStart()), never as another Admin or as themselves,
 * nor while already in someone else's account. Each start and stop is
 * logged, with who and whom. Not into a deactivated account.
 */
final class Impersonation
{
    /**
     * Where the Admin's own id is kept in the session while they're in
     * someone else's account.
     */
    public const SESSION_KEY = 'impersonator_id';

    /**
     * Whether $admin can sign in as $user.
     */
    public static function canStart(User $admin, User $user): bool
    {
        return $admin->hasRole('Admin')
            && ! self::isActive()
            && ! $admin->is($user)
            && ! $user->hasRole('Admin')
            && ! $user->isDeactivated();
    }

    /**
     * Signs $admin in as $user, keeping $admin's id to come back to.
     */
    public static function start(User $admin, User $user): void
    {
        Log::info('Admin signed in as another user.', [
            'admin_id' => $admin->id,
            'admin' => $admin->email,
            'user_id' => $user->id,
            'user' => $user->email,
            'ip' => request()->ip(),
        ]);

        Auth::guard('web')->login($user);
        session()->regenerate();
        session()->put(self::SESSION_KEY, $admin->id);
    }

    /**
     * Back to the Admin's own account. Null — and signed out altogether — if
     * there's no Admin to go back to any more (their account deleted, or no
     * longer an Admin).
     */
    public static function stop(): ?User
    {
        $impersonated = Auth::user();
        $admin = self::impersonator();
        session()->forget(self::SESSION_KEY);

        Log::info('Admin returned to their own account.', [
            'admin_id' => $admin?->id,
            'user_id' => $impersonated?->id,
        ]);

        if ($admin === null || ! $admin->hasRole('Admin')) {
            Auth::guard('web')->logout();
            session()->invalidate();
            session()->regenerateToken();

            return null;
        }

        Auth::guard('web')->login($admin);
        session()->regenerate();

        return $admin;
    }

    /**
     * Whether whoever's signed in is an Admin in someone else's account.
     */
    public static function isActive(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /**
     * The Admin in someone else's account, if that's what this is.
     */
    public static function impersonator(): ?User
    {
        $id = session(self::SESSION_KEY);

        return $id === null ? null : User::query()->find($id);
    }
}
