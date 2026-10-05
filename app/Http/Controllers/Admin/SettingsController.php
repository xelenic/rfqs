<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Illuminate\View\View;

/**
 * The Settings page. Everyone signed in has their own part of it — profile,
 * password, and the preferences for how the panel behaves for them — and the
 * Admin has the application-wide settings on top, the working week among
 * them. Each part is its own tab, form and action, with its own error bag, so
 * saving one never touches another — and lands back on its own tab.
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
            'workingHours' => Setting::workingHours(),
            'timezone' => Setting::timezone(),
            'sourcingTargets' => Setting::sourcingTargets(),
            'attendanceSince' => Setting::attendanceSince(),
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

        return $this->backToTab('profile', 'Profile updated.');
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

        return $this->backToTab('password', 'Password changed.');
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

        return $this->backToTab('preferences', 'Preferences saved.');
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

        return $this->backToTab('application', 'Application settings saved.');
    }

    /**
     * The working week: for each day, whether it's worked, its working hours
     * and its lunch — both ends of lunch or neither, inside the working hours.
     * A day switched off isn't checked and keeps the times it had, so turning
     * it back on brings them back. With them, the time zone they're in, and
     * the day from which a person's time only counts on a day the attendance
     * sheet has them present (empty: it counts straight away). See
     * Setting::workingHours(), timezone() and attendanceSince().
     */
    public function updateWorkingHours(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the working hours.');

        $rules = [
            'timezone' => ['required', 'timezone:all'],
            'attendance_since' => ['nullable', 'date_format:Y-m-d'],
        ];
        $attributes = ['timezone' => 'the time zone', 'attendance_since' => 'the day attendance starts'];

        foreach (Setting::WEEKDAYS as $day) {
            $dayName = ucfirst($day);
            $onlyIfWorked = "exclude_unless:days.{$day}.working,1";

            $rules["days.{$day}.working"] = ['required', 'boolean'];
            $rules["days.{$day}.start"] = [$onlyIfWorked, 'required', 'date_format:H:i'];
            $rules["days.{$day}.end"] = [$onlyIfWorked, 'required', 'date_format:H:i'];
            $rules["days.{$day}.lunch_start"] = [$onlyIfWorked, 'nullable', 'date_format:H:i'];
            $rules["days.{$day}.lunch_end"] = [$onlyIfWorked, 'nullable', 'date_format:H:i'];

            $attributes["days.{$day}.start"] = "{$dayName}'s start";
            $attributes["days.{$day}.end"] = "{$dayName}'s finish";
            $attributes["days.{$day}.lunch_start"] = "{$dayName}'s lunch start";
            $attributes["days.{$day}.lunch_end"] = "{$dayName}'s lunch end";
        }

        $validator = Validator::make($request->all(), $rules, [
            'required' => 'Give :attribute.',
            'date_format' => ':attribute has to be a time, like 08:30.',
            'timezone' => 'Choose a time zone from the list.',
        ], $attributes)->after(fn (ValidatorInstance $validator) => $this->checkWorkingDays($validator));

        $validated = $validator->validateWithBag('working_hours');

        $week = Setting::workingHours();

        foreach (Setting::WEEKDAYS as $day) {
            $given = $validated['days'][$day];
            $week[$day]['working'] = (bool) $given['working'];

            if ($week[$day]['working']) {
                $week[$day]['start'] = $given['start'];
                $week[$day]['end'] = $given['end'];
                $week[$day]['lunch_start'] = $given['lunch_start'] ?? null;
                $week[$day]['lunch_end'] = $given['lunch_end'] ?? null;
            }
        }

        Setting::putWorkingHours($week);
        Setting::put('timezone', $validated['timezone']);
        Setting::put('attendance_since', $validated['attendance_since'] ?? null);

        return $this->backToTab('working-hours', 'Working hours saved.');
    }

    /**
     * How long Sourcing has to complete a part of each priority, in working
     * hours — given in hours (quarters allowed), kept in minutes. What the
     * countdown on their Pending list runs from. See
     * Setting::sourcingTargets().
     */
    public function updateSourcingTargets(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the Sourcing targets.');

        [$fewest, $most] = array_map(fn (int $minutes) => $minutes / 60, Setting::SOURCING_TARGET_RANGE);
        $rules = [];
        $attributes = [];

        foreach (array_keys(Setting::DEFAULT_SOURCING_TARGETS) as $priority) {
            $rules["targets.{$priority}"] = ['required', 'numeric', "between:{$fewest},{$most}", 'multiple_of:0.25'];
            $attributes["targets.{$priority}"] = "the {$priority} target";
        }

        $validated = $request->validateWithBag('sourcing_targets', $rules, [
            'required' => 'Give :attribute.',
            'numeric' => 'Give :attribute in hours, like 4 or 1.5.',
            'between' => "Keep :attribute between {$fewest} and {$most} hours.",
            'multiple_of' => 'Give :attribute to the quarter hour, like 1.25.',
        ], $attributes);

        Setting::put('sourcing_targets', json_encode(collect($validated['targets'])
            ->map(fn ($hours) => (int) round($hours * 60))
            ->all()));

        return $this->backToTab('sourcing-targets', 'Sourcing targets saved.');
    }

    /**
     * What a working day's times have to make sense together as: finishing
     * after it starts, and a lunch given whole (or not at all) that ends after
     * it starts and sits inside the working hours. Only checked where each
     * time is well-formed — a malformed one already has its own error.
     */
    private function checkWorkingDays(ValidatorInstance $validator): void
    {
        foreach (Setting::WEEKDAYS as $day) {
            $times = $validator->getData()['days'][$day] ?? [];
            $dayName = ucfirst($day);
            $key = fn (string $field) => "days.{$day}.{$field}";

            if (! filter_var($times['working'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                continue;
            }

            if (collect(['start', 'end', 'lunch_start', 'lunch_end'])->contains(fn (string $field) => $validator->errors()->has($key($field)))) {
                continue;
            }

            [$start, $end] = [$times['start'], $times['end']];
            [$lunchStart, $lunchEnd] = [$times['lunch_start'] ?? null, $times['lunch_end'] ?? null];

            // Zero-padded "HH:MM" (date_format:H:i) compares in time order as a string.
            if ($end <= $start) {
                $validator->errors()->add($key('end'), "{$dayName} has to finish after it starts.");

                continue;
            }

            if (filled($lunchStart) !== filled($lunchEnd)) {
                $validator->errors()->add($key(filled($lunchStart) ? 'lunch_end' : 'lunch_start'), "Give both ends of {$dayName}'s lunch, or neither.");
            } elseif (filled($lunchStart) && $lunchEnd <= $lunchStart) {
                $validator->errors()->add($key('lunch_end'), "{$dayName}'s lunch has to end after it starts.");
            } elseif (filled($lunchStart) && ($lunchStart < $start || $lunchEnd > $end)) {
                $validator->errors()->add($key('lunch_start'), "{$dayName}'s lunch has to fall within its working hours.");
            }
        }
    }

    /**
     * Back to the Settings page, on the tab the form was saved from, with a
     * word that it was.
     */
    private function backToTab(string $tab, string $status): RedirectResponse
    {
        return redirect()->route('admin.settings.edit')->with([
            'status' => $status,
            'settings_tab' => $tab,
        ]);
    }
}
