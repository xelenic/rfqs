<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The Settings page. Everyone signed in has their own part of it — profile,
 * password, and the preferences for how the panel behaves for them — and the
 * Admin has the application-wide settings on top. Each part is its own form
 * and its own action, with its own error bag, so saving one never touches
 * another.
 */
class SettingsController extends Controller
{
    /**
     * The roles that close RFQs — the ones a celebration preference means
     * anything to.
     */
    private const CLOSING_ROLES = ['Business Development', 'Admin'];

    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('admin.settings.edit', [
            'user' => $user->load('roles'),
            'closesRfqs' => $user->hasAnyRole(self::CLOSING_ROLES),
            'isAdmin' => $user->hasRole('Admin'),
            'companyName' => Setting::get('company_name'),
            'liveInterval' => Setting::liveIntervalSeconds(),
            'liveIntervalRange' => Setting::LIVE_INTERVAL_RANGE,
        ]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validateWithBag('profile', [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($validated);

        return redirect()->route('admin.settings.edit')->with('status', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ], [
            'current_password.current_password' => 'That isn\'t your current password.',
            'password.different' => 'Choose a password you haven\'t been using.',
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return redirect()->route('admin.settings.edit')->with('status', 'Password changed.');
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $user = $request->user();

        $preferences = $user->preferences ?? [];
        $preferences['live_updates'] = $request->boolean('live_updates');

        // Only offered to the people who close RFQs — leave everyone else's be.
        if ($user->hasAnyRole(self::CLOSING_ROLES)) {
            $preferences['celebrations'] = $request->boolean('celebrations');
        }

        $user->preferences = $preferences;
        $user->save();

        return redirect()->route('admin.settings.edit')->with('status', 'Preferences saved.');
    }

    public function updateApplication(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the application settings.');

        [$fewest, $most] = Setting::LIVE_INTERVAL_RANGE;

        $validated = $request->validateWithBag('application', [
            'company_name' => ['nullable', 'string', 'max:80'],
            'live_interval' => ['required', 'integer', "between:{$fewest},{$most}"],
        ], [
            'live_interval.between' => "Choose between {$fewest} and {$most} seconds.",
        ]);

        Setting::put('company_name', $validated['company_name'] ?? null);
        Setting::put('live_interval', (string) $validated['live_interval']);

        return redirect()->route('admin.settings.edit')->with('status', 'Application settings saved.');
    }
}
