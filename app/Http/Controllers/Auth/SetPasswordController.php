<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Where a new account's welcome email leads (App\Notifications\AccountCreated):
 * its person picks their own password, then they're signed in. The link is
 * the "new_users" password broker's — a week, and once (config/auth.php).
 */
class SetPasswordController extends Controller
{
    /**
     * The set-password form, for the link's token and address.
     */
    public function create(Request $request, string $token): View
    {
        return view('auth.set-password', [
            'token' => $token,
            'email' => (string) $request->query('email'),
        ]);
    }

    /**
     * Sets it, if the link's still good — their email counts as verified,
     * since the link reached them — and signs them in.
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $signedIn = null;

        $status = Password::broker('new_users')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$signedIn) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new PasswordReset($user));

                $signedIn = $user;
            }
        );

        if ($status !== Password::PasswordReset || $signedIn === null) {
            return back()
                ->withErrors(['email' => 'This link has expired or has already been used — ask HR for a new one.'])
                ->onlyInput('email');
        }

        Auth::login($signedIn);
        $request->session()->regenerate();

        return redirect()->route('admin.dashboard')->with('status', 'Your password is set — welcome aboard.');
    }
}
