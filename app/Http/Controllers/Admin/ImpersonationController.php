<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Impersonation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An Admin signing in as someone else from the Users pages, and back — see
 * App\Impersonation.
 */
class ImpersonationController extends Controller
{
    public function store(Request $request, User $user): RedirectResponse
    {
        abort_unless(Impersonation::canStart($request->user(), $user), 403, 'Only an Admin can sign in as someone else — and not as another Admin.');

        Impersonation::start($request->user(), $user);

        return redirect()->route('admin.dashboard')
            ->with('status', "You're now signed in as {$user->name}. Use \"Back to your account\" at the top when you're done.");
    }

    public function destroy(): RedirectResponse
    {
        abort_unless(Impersonation::isActive(), 403, 'You\'re not signed in as anyone else.');

        $impersonated = auth()->user();
        $admin = Impersonation::stop();

        if ($admin === null) {
            return redirect()->route('login');
        }

        return redirect()->route('admin.users.show', $impersonated)
            ->with('status', "Back in your own account — you were signed in as {$impersonated->name}.");
    }
}
