<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out an account an Admin has deactivated (User::isDeactivated()) on
 * its next request, whatever session or "remember me" it still had — and
 * says why on the sign-in page.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isDeactivated()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Your account has been deactivated.'], 401)
                : redirect()->route('login')->withErrors(['email' => 'Your account has been deactivated — ask an Admin if you need it back.']);
        }

        return $next($request);
    }
}
