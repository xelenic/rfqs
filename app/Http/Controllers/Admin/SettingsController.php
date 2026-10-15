<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceSheet;
use App\Models\ExtraWorkingHour;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkingSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;

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
            'dataEntryTargets' => Setting::dataEntryTargets(),
            'dataEntryPerItem' => Setting::dataEntryPerItem(),
            'dataEntryPerItemRange' => Setting::DATA_ENTRY_PER_ITEM_RANGE,
            'dataEntryIdleAlert' => Setting::dataEntryIdleAlert(),
            'wcFormatRequired' => Setting::wcFormatRequired(),
            'extraHours' => $user->hasRole('Admin')
                ? ExtraWorkingHour::query()->current()->with('user.roles')->get()->sortBy(fn (ExtraWorkingHour $range) => [$range->user?->name, $range->start])->values()
                : collect(),
            'extraHoursPeople' => $user->hasRole('Admin') ? AttendanceSheet::people() : collect(),
            'dataEntryIdleMinutesRange' => Setting::DATA_ENTRY_IDLE_MINUTES_RANGE,
            'attendanceSince' => Setting::attendanceSince(),
            'halfDayOff' => Setting::halfDayOff(),
            'managesWorkingHours' => $user->canManageWorkingHours(),
            'roleHours' => $user->canManageWorkingHours()
                ? WorkingSchedule::roleOrder()->map(fn (Role $role) => ['role' => $role, 'current' => WorkingSchedule::currentFor($role)])
                : collect(),
            'peopleHours' => $user->canManageWorkingHours()
                ? User::query()->whereHas('workingSchedules')->with('roles')->orderBy('name')->get()
                    ->map(fn (User $person) => ['user' => $person, 'current' => WorkingSchedule::currentFor($person)])
                    ->filter(fn (array $row) => $row['current'] !== null)
                    ->values()
                : collect(),
        ]);
    }

    /**
     * Your own name and email — only for those who keep people's details
     * (User::USER_DETAILS_ROLES); everyone else's are kept for them.
     */
    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user->canChangeUserDetails(), 403, 'Only an Admin or HR Manager can change your name or email.');

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

        $validated = $request->validate([
            'theme' => ['nullable', Rule::in(array_keys(User::THEMES))],
        ]);

        $preferences = $user->preferences ?? [];
        $preferences['live_updates'] = $request->boolean('live_updates');

        if (isset($validated['theme'])) {
            $preferences['theme'] = $validated['theme'];
        }

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

        $validated = $this->validatedWeek($request, 'working_hours', [
            'timezone' => ['required', 'timezone:all'],
            'attendance_since' => ['nullable', 'date_format:Y-m-d'],
        ], ['timezone' => 'the time zone', 'attendance_since' => 'the day attendance starts']);

        Setting::putWorkingHours($this->weekWith(Setting::workingHours(), $validated['days']));
        Setting::put('timezone', $validated['timezone']);
        Setting::put('attendance_since', $validated['attendance_since'] ?? null);

        return $this->backToTab('working-hours', 'Working hours saved.');
    }

    /**
     * A role's own working week, in place of the company's — the same days,
     * hours and lunches, checked the same way — from today on: its people's
     * time counts in it unless they have their own. Days before keep the
     * hours they had. Admin or HR Manager (User::WORKING_HOURS_ROLES). See
     * WorkingSchedule.
     */
    public function updateRoleHours(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->canManageWorkingHours(), 403, 'Only an Admin or HR Manager can change a role\'s working hours.');

        $validated = $this->validatedWeek($request, 'role_hours');
        $base = WorkingSchedule::currentFor($role)?->week ?? Setting::workingHours();

        WorkingSchedule::putFor($role, $this->weekWith(Setting::weekFrom($base), $validated['days']), $request->user());

        return $this->backToTab('role-hours', "{$role->name} works its own hours from today — days before keep the hours they had.");
    }

    /**
     * Puts a role back on the company's working hours from today. Days before
     * keep the hours they had.
     */
    public function destroyRoleHours(Request $request, Role $role): RedirectResponse
    {
        abort_unless($request->user()->canManageWorkingHours(), 403, 'Only an Admin or HR Manager can change a role\'s working hours.');
        abort_unless(WorkingSchedule::endFor($role), 404);

        return $this->backToTab('role-hours', "{$role->name} works the company's working hours again from today.");
    }

    /**
     * One person's own working week, in place of their role's or the
     * company's, from today on. Days before keep the hours they had. Set from
     * their page, and back to it, by an Admin or HR Manager
     * (User::WORKING_HOURS_ROLES). See WorkingSchedule.
     */
    public function updateUserHours(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageWorkingHours(), 403, 'Only an Admin or HR Manager can change someone\'s working hours.');

        $validated = $this->validatedWeek($request, 'user_hours');
        $base = Setting::weekOn(Setting::today(), $user->id);

        WorkingSchedule::putFor($user, $this->weekWith($base, $validated['days']), $request->user());

        return redirect()->route('admin.users.show', ['user' => $user, 'tab' => 'working-hours'])
            ->with('status', "{$user->name} works their own hours from today — days before keep the hours they had.");
    }

    /**
     * Puts someone back on their role's hours, or the company's, from today.
     * Days before keep the hours they had.
     */
    public function destroyUserHours(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->canManageWorkingHours(), 403, 'Only an Admin or HR Manager can change someone\'s working hours.');
        abort_unless(WorkingSchedule::endFor($user), 404);

        return redirect()->route('admin.users.show', ['user' => $user, 'tab' => 'working-hours'])
            ->with('status', "{$user->name}'s own hours are taken away from today — their time counts in their role's or the company's hours again.");
    }

    /**
     * A working week sent from one of its forms (admin.settings._week_table):
     * for each day, whether it's worked, its working hours and its lunch —
     * both ends of lunch or neither, inside the working hours. A day switched
     * off isn't checked. Validated into error bag $bag, with $rules on top.
     *
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    private function validatedWeek(Request $request, string $bag, array $rules = [], array $attributes = []): array
    {
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

        return Validator::make($request->all(), $rules, [
            'required' => 'Give :attribute.',
            'date_format' => ':attribute has to be a time, like 08:30.',
            'timezone' => 'Choose a time zone from the list.',
        ], $attributes)->after(fn (ValidatorInstance $validator) => $this->checkWorkingDays($validator))
            ->validateWithBag($bag);
    }

    /**
     * $week with the days sent (validatedWeek()) put in: whether each is
     * worked, and the times of a day that is. A day switched off keeps the
     * times it had, so switching it back on brings them back.
     *
     * @param  array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>  $week
     * @param  array<string, array<string, mixed>>  $days
     * @return array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>
     */
    private function weekWith(array $week, array $days): array
    {
        foreach (Setting::WEEKDAYS as $day) {
            $given = $days[$day];
            $week[$day]['working'] = (bool) $given['working'];

            if ($week[$day]['working']) {
                $week[$day]['start'] = $given['start'];
                $week[$day]['end'] = $given['end'];
                $week[$day]['lunch_start'] = $given['lunch_start'] ?? null;
                $week[$day]['lunch_end'] = $given['lunch_end'] ?? null;
            }
        }

        return $week;
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

        Setting::put('sourcing_targets', json_encode($this->validatedTargets($request, 'sourcing_targets', Setting::DEFAULT_SOURCING_TARGETS)));

        return $this->backToTab('sourcing-targets', 'Sourcing targets saved.');
    }

    /**
     * How long Data Entry has to send a part of each priority to finalize,
     * from their Start, in working hours — given in hours (quarters
     * allowed), kept in minutes. What the countdown on their list runs from.
     * See Setting::dataEntryTargets().
     */
    public function updateDataEntryTargets(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the Data Entry targets.');

        Setting::put('data_entry_targets', json_encode($this->validatedTargets($request, 'data_entry_targets', Setting::DEFAULT_DATA_ENTRY_TARGETS)));

        return $this->backToTab('data-entry-targets', 'Data Entry targets saved.');
    }

    /**
     * Whether Data Entry's target is worked out per item — the working time
     * an item takes, given in minutes (to the half minute), kept in seconds —
     * times a part's items, rather than by priority. See
     * Setting::dataEntryPerItem() and Rfq::dataEntryTargetSeconds().
     */
    public function updateDataEntryPerItem(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the Data Entry targets.');

        [$fewest, $most] = array_map(fn (int $seconds) => $seconds / 60, Setting::DATA_ENTRY_PER_ITEM_RANGE);

        $validated = $request->validateWithBag('data_entry_per_item', [
            'enabled' => ['boolean'],
            'minutes' => ['required', 'numeric', "between:{$fewest},{$most}", 'multiple_of:0.5'],
        ], [
            'minutes.required' => 'Give the minutes an item takes.',
            'minutes.numeric' => 'Give the minutes an item takes, like 2 or 1.5.',
            'minutes.between' => "Keep it between {$fewest} and {$most} minutes an item.",
            'minutes.multiple_of' => 'Give it to the half minute, like 2.5.',
        ]);

        Setting::putDataEntryPerItem([
            'enabled' => $request->boolean('enabled'),
            'seconds' => (int) round($validated['minutes'] * 60),
        ]);

        return $this->backToTab('data-entry-targets', $request->boolean('enabled')
            ? 'Data Entry\'s target is now worked out per item.'
            : 'Data Entry\'s target is back to its priority\'s.');
    }

    /**
     * Whether Senior Operations is alerted when someone in Data Entry hasn't
     * started anything for a while, and after how many working minutes. See
     * Setting::dataEntryIdleAlert() and App\Console\Commands\AlertIdleDataEntry.
     */
    public function updateIdleAlert(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the idle alert.');

        [$fewest, $most] = Setting::DATA_ENTRY_IDLE_MINUTES_RANGE;

        $validated = $request->validateWithBag('idle_alert', [
            'enabled' => ['boolean'],
            'minutes' => ['required', 'integer', "between:{$fewest},{$most}"],
        ], [
            'minutes.required' => 'Give how many minutes.',
            'minutes.integer' => 'Give the minutes as a whole number, like 10.',
            'minutes.between' => "Keep it between {$fewest} and {$most} minutes.",
        ]);

        Setting::putDataEntryIdleAlert([
            'enabled' => $request->boolean('enabled'),
            'minutes' => (int) $validated['minutes'],
        ]);

        return $this->backToTab('idle-alert', 'Idle alert saved.');
    }

    /**
     * Whether a new RFQ's WC number has to be in the WC0000 format — and a
     * changed one. See Setting::wcFormatRequired().
     */
    public function updateWcValidation(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the WC validation.');

        Setting::put('wc_format_required', $request->boolean('enabled') ? '1' : null);

        return $this->backToTab('wc-validation', $request->boolean('enabled') ? 'WC validation turned on.' : 'WC validation turned off.');
    }

    /**
     * Adds a range of someone's own working time on top of everyone's
     * working hours — on the days picked, from one time to a later one that
     * day, counting from the day given (today, or earlier for time already
     * worked that way). For the people whose time is tracked
     * (AttendanceSheet::people()). See ExtraWorkingHour.
     */
    public function storeExtraHours(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change anyone\'s working hours.');

        $validated = $request->validateWithBag('extra_hours', [
            'user_id' => ['required', 'integer', Rule::in(AttendanceSheet::people()->pluck('id'))],
            'days' => ['required', 'array', 'min:1'],
            'days.*' => ['string', Rule::in(Setting::WEEKDAYS)],
            'start' => ['required', 'date_format:H:i'],
            'end' => ['required', 'date_format:H:i', 'after:start'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
        ], [
            'user_id.required' => 'Pick who it\'s for.',
            'user_id.in' => 'Extra hours are for Sourcing, Data Entry and GM Assistant people.',
            'days.required' => 'Pick at least one day.',
            'start.required' => 'Give the time it starts.',
            'end.required' => 'Give the time it ends.',
            'start.date_format' => 'Give a time like 19:00.',
            'end.date_format' => 'Give a time like 20:00.',
            'end.after' => 'It has to end after it starts, the same day — one that runs past midnight is two ranges.',
            'starts_on.required' => 'Give the day it counts from.',
            'starts_on.date_format' => 'Give the day it counts from, like 2026-10-12.',
        ]);

        $range = ExtraWorkingHour::query()->create([
            'user_id' => (int) $validated['user_id'],
            'days' => array_values(array_intersect(Setting::WEEKDAYS, $validated['days'])),
            'start' => $validated['start'],
            'end' => $validated['end'],
            'starts_on' => $validated['starts_on'],
            'created_by' => $request->user()->id,
        ]);

        return $this->backToExtraHours($request, "Extra hours added for {$range->user->name}: {$range->daysLabel()}, {$range->start}–{$range->end}.");
    }

    /**
     * Takes away one of someone's extra ranges from now on: ended, not
     * deleted, so the time it counted until now stays counted.
     */
    public function destroyExtraHours(Request $request, ExtraWorkingHour $extraHours): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change anyone\'s working hours.');
        abort_if($extraHours->ended_at !== null, 404);

        $extraHours->update(['ended_at' => now()]);

        return $this->backToExtraHours($request, "Extra hours removed for {$extraHours->user->name} — from now on; time already worked in them still counts.");
    }

    /**
     * Back to where extra hours were changed from: the Users page (return_to
     * "users"), just as it was, or Settings → Extra Hours.
     */
    private function backToExtraHours(Request $request, string $status): RedirectResponse
    {
        return $request->input('return_to') === 'users'
            ? back()->with('status', $status)
            : $this->backToTab('extra-hours', $status);
    }

    /**
     * The targets[] sent — one per priority in $defaults, in hours, to the
     * quarter hour, within Setting::TARGET_RANGE — validated into error bag
     * $bag, and turned into minutes.
     *
     * @param  array<string, int>  $defaults
     * @return array<string, int>
     */
    private function validatedTargets(Request $request, string $bag, array $defaults): array
    {
        [$fewest, $most] = array_map(fn (int $minutes) => $minutes / 60, Setting::TARGET_RANGE);
        $rules = [];
        $attributes = [];

        foreach (array_keys($defaults) as $priority) {
            $rules["targets.{$priority}"] = ['required', 'numeric', "between:{$fewest},{$most}", 'multiple_of:0.25'];
            $attributes["targets.{$priority}"] = "the {$priority} target";
        }

        $validated = $request->validateWithBag($bag, $rules, [
            'required' => 'Give :attribute.',
            'numeric' => 'Give :attribute in hours, like 4 or 1.5.',
            'between' => "Keep :attribute between {$fewest} and {$most} hours.",
            'multiple_of' => 'Give :attribute to the quarter hour, like 1.25.',
        ], $attributes);

        return collect($validated['targets'])
            ->map(fn ($hours) => (int) round($hours * 60))
            ->all();
    }

    /**
     * The time a half day off takes: the morning off and the afternoon off,
     * each from a time to a later one. On a half day the attendance sheet has
     * someone off one of them, the time that falls in it doesn't count. See
     * Setting::halfDayOff().
     */
    public function updateHalfDay(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('Admin'), 403, 'Only an Admin can change the half day.');

        $rules = [];
        $attributes = [];

        foreach (['morning' => 'the morning off', 'afternoon' => 'the afternoon off'] as $half => $name) {
            $rules["halves.{$half}.start"] = ['required', 'date_format:H:i'];
            $rules["halves.{$half}.end"] = ['required', 'date_format:H:i'];
            $attributes["halves.{$half}.start"] = "when {$name} starts";
            $attributes["halves.{$half}.end"] = "when {$name} ends";
        }

        $validated = Validator::make($request->all(), $rules, [
            'required' => 'Give :attribute.',
            'date_format' => 'Give :attribute as a time, like 08:30.',
        ], $attributes)->after(function (ValidatorInstance $validator) {
            foreach (['morning' => 'The morning off', 'afternoon' => 'The afternoon off'] as $half => $name) {
                $times = $validator->getData()['halves'][$half] ?? [];

                // Zero-padded "HH:MM" (date_format:H:i) compares in time order as a string.
                if (! $validator->errors()->hasAny(["halves.{$half}.start", "halves.{$half}.end"]) && $times['end'] <= $times['start']) {
                    $validator->errors()->add("halves.{$half}.end", "{$name} has to end after it starts.");
                }
            }
        })->validateWithBag('half_day');

        Setting::putHalfDayOff(collect($validated['halves'])
            ->map(fn (array $times) => ['start' => $times['start'], 'end' => $times['end']])
            ->all());

        return $this->backToTab('half-day', 'Half day saved.');
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
