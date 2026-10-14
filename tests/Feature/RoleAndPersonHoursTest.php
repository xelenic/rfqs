<?php

use App\Models\ExtraWorkingHour;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['users.view', 'users.create', 'users.edit', 'users.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Manager'] as $role) {
        Role::findOrCreate($role);
    }

    Role::findByName('Admin')->givePermissionTo(Permission::all());
    Role::findByName('HR Manager')->givePermissionTo(['users.view', 'users.create', 'users.edit']);

    // Monday 5 October, 09:00 in Colombo.
    test()->travelTo(hoursClock('2026-10-05 09:00'));
});

/**
 * A moment in Colombo — the working hours' own time zone; the company works
 * 08:30–17:00 on weekdays, lunch 12:30–13:30.
 */
function hoursClock(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * A working week: $start–$end with lunch on every day but $daysOff.
 *
 * @param  array<int, string>  $daysOff
 * @return array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>
 */
function hoursWeek(string $start, string $end, ?string $lunchStart = '12:00', ?string $lunchEnd = '13:00', array $daysOff = ['saturday', 'sunday']): array
{
    return collect(Setting::WEEKDAYS)->mapWithKeys(fn (string $day) => [$day => [
        'working' => ! in_array($day, $daysOff, true),
        'start' => $start,
        'end' => $end,
        'lunch_start' => $lunchStart,
        'lunch_end' => $lunchEnd,
    ]])->all();
}

/**
 * $week as the week forms send it.
 *
 * @param  array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>  $week
 * @return array{days: array<string, array<string, string>>}
 */
function hoursForm(array $week): array
{
    return ['days' => collect($week)->map(fn (array $day) => [
        'working' => $day['working'] ? '1' : '0',
        'start' => $day['start'],
        'end' => $day['end'],
        'lunch_start' => (string) $day['lunch_start'],
        'lunch_end' => (string) $day['lunch_end'],
    ])->all()];
}

/**
 * $owner — a role or a person — works $week, counting from $from.
 *
 * @param  array<string, array{working: bool, start: string, end: string, lunch_start: ?string, lunch_end: ?string}>  $week
 */
function hoursFor(Role|User $owner, array $week, string $from = '2026-10-01'): WorkingSchedule
{
    return WorkingSchedule::factory()->create([
        'role_id' => $owner instanceof Role ? $owner->id : null,
        'user_id' => $owner instanceof User ? $owner->id : null,
        'week' => $week,
        'starts_on' => $from,
    ]);
}

// ---- whose hours count -----------------------------------------------------

it('counts a role\'s people in its own hours, and everyone else in the company\'s', function () {
    $morgan = userWithRole('Data Entry');
    $riley = userWithRole('Sourcing');
    hoursFor(Role::findByName('Data Entry'), hoursWeek('07:00', '16:00'));

    $monday = [hoursClock('2026-10-05 00:00'), hoursClock('2026-10-06 00:00')];

    // 07:00–12:00 and 13:00–16:00 for Data Entry; 08:30–12:30 and 13:30–17:00 for the rest.
    expect(Setting::workingSecondsBetween(...[...$monday, $morgan->id]))->toBe(8 * 3600)
        ->and(Setting::workingSecondsBetween(...[...$monday, $riley->id]))->toBe(27000)
        ->and(Setting::workingSecondsBetween(...$monday))->toBe(27000)
        ->and(Setting::isWorkingTime(hoursClock('2026-10-05 07:30'), $morgan->id))->toBeTrue()
        ->and(Setting::isWorkingTime(hoursClock('2026-10-05 07:30'), $riley->id))->toBeFalse()
        ->and(Setting::offHoursReason(hoursClock('2026-10-05 16:30'), $morgan->id))
        ->toBe('Working hours are over for today — they ended at 16:00. Start is back tomorrow at 07:00.');
});

it('works a role\'s own days off and working days too', function () {
    $morgan = userWithRole('Data Entry');
    hoursFor(Role::findByName('Data Entry'), hoursWeek('08:30', '17:00', daysOff: ['sunday', 'monday']));

    expect(Setting::isWorkingTime(hoursClock('2026-10-10 10:00'), $morgan->id))->toBeTrue()
        ->and(Setting::isWorkingTime(hoursClock('2026-10-10 10:00')))->toBeFalse()
        ->and(Setting::offHoursReason(hoursClock('2026-10-05 10:00'), $morgan->id))->toBe('Monday is a day off. Start is back tomorrow at 08:30.');
});

it('puts someone\'s own hours before their role\'s, with their extra hours still on top', function () {
    $morgan = userWithRole('Data Entry');
    hoursFor(Role::findByName('Data Entry'), hoursWeek('07:00', '16:00'));
    hoursFor($morgan, hoursWeek('10:00', '19:00', '14:00', '15:00'));
    ExtraWorkingHour::factory()->create(['user_id' => $morgan->id, 'start' => '20:00', 'end' => '21:00', 'starts_on' => '2026-10-01']);

    $at = fn (string $time) => hoursClock("2026-10-05 {$time}")->getTimestamp();

    expect(Setting::workingPeriodsBetween(hoursClock('2026-10-05 00:00'), hoursClock('2026-10-06 00:00'), $morgan->id))
        ->toBe([[$at('10:00'), $at('14:00')], [$at('15:00'), $at('19:00')], [$at('20:00'), $at('21:00')]]);
});

it('gives someone in two roles with their own hours the first of them in the workflow', function () {
    $both = userWithRole('Data Entry')->assignRole('Sourcing');
    hoursFor(Role::findByName('Data Entry'), hoursWeek('07:00', '16:00'));
    hoursFor(Role::findByName('Sourcing'), hoursWeek('10:00', '19:00'));

    // Sourcing comes before Data Entry.
    expect(Setting::isWorkingTime(hoursClock('2026-10-05 18:30'), $both->id))->toBeTrue()
        ->and(Setting::isWorkingTime(hoursClock('2026-10-05 07:30'), $both->id))->toBeFalse();
});

it('follows who holds a role as soon as it changes', function () {
    hoursFor(Role::findByName('Data Entry'), hoursWeek('07:00', '16:00'));
    $riley = userWithRole('Sourcing');
    $early = hoursClock('2026-10-05 07:30');

    expect(Setting::isWorkingTime($early, $riley->id))->toBeFalse();

    $riley->assignRole('Data Entry');
    expect(Setting::isWorkingTime($early, $riley->id))->toBeTrue();

    $riley->syncRoles(['Sourcing']);
    expect(Setting::isWorkingTime($early, $riley->id))->toBeFalse();
});

it('counts a change from the day it\'s made — the days before keep the hours they had', function () {
    $admin = userWithRole('Admin');
    $morgan = userWithRole('Data Entry');
    $dataEntry = Role::findByName('Data Entry');
    $day = fn (string $date) => Setting::workingSecondsBetween(hoursClock("{$date} 00:00"), hoursClock("{$date} 23:59"), $morgan->id);

    // Monday: 07:00–16:00 — then changed again the same day, which changes today's.
    WorkingSchedule::putFor($dataEntry, hoursWeek('07:00', '16:00'), $admin);
    WorkingSchedule::putFor($dataEntry, hoursWeek('07:00', '15:00'), $admin);
    expect(WorkingSchedule::query()->count())->toBe(1);

    // Tuesday: 10:00–19:00 from then on.
    test()->travelTo(hoursClock('2026-10-06 09:00'));
    WorkingSchedule::putFor($dataEntry, hoursWeek('10:00', '19:00'), $admin);

    // Wednesday: back on the company's.
    test()->travelTo(hoursClock('2026-10-07 09:00'));
    expect(WorkingSchedule::endFor($dataEntry))->toBeTrue()
        ->and(WorkingSchedule::endFor($dataEntry))->toBeFalse();

    expect($day('2026-10-02'))->toBe(27000)
        ->and($day('2026-10-05'))->toBe(7 * 3600)
        ->and($day('2026-10-06'))->toBe(8 * 3600)
        ->and($day('2026-10-07'))->toBe(27000)
        ->and($day('2026-10-08'))->toBe(27000);
});

it('lets Data Entry start in their role\'s hours, and sends their countdowns those hours', function () {
    hoursFor(Role::findByName('Data Entry'), hoursWeek('07:00', '16:00'));
    $morgan = userWithRole('Data Entry');

    test()->travelTo(hoursClock('2026-10-02 10:00'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ80001']), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);

    test()->travelTo(hoursClock('2026-10-05 07:15'));
    $html = test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    $schedule = json_decode(Str::betweenFirst($html, '<script type="application/json" id="countdown-schedule">', '</script>'), true);

    expect($schedule['people'])->toHaveKey((string) $morgan->id)
        ->and($schedule['people'][$morgan->id][0])->toBe([hoursClock('2026-10-05 07:15')->getTimestamp(), hoursClock('2026-10-05 12:00')->getTimestamp()]);

    test()->actingAs($morgan)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertSessionHas('status', 'Started RFQ80001 — your time on it is counting.');

    // Everyone else is still waiting for 08:30.
    expect(Setting::offHoursReason(now()))->toBe("Working hours haven't started yet — they start at 08:30.");
});

// ---- Settings → Role Hours -------------------------------------------------

it('lets an Admin give a role its own hours on Settings → Role Hours, from today', function () {
    $admin = userWithRole('Admin');
    $dataEntry = Role::findByName('Data Entry');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'role-hours']))->assertOk()
        ->assertSee('class="tab-pane fade show active" id="settings-pane-role-hours"', false)
        ->assertSeeInOrder(['Business Development', 'Senior Operations', 'Sourcing', 'Data Entry', 'Head of Business Development'])
        ->assertSee('Company hours')
        ->assertSee('id="roleHoursModal-'.$dataEntry->id.'"', false)
        ->assertSee('Nobody — everyone works their role\'s hours or the company\'s.', false);

    test()->actingAs($admin)->put(route('admin.settings.role-hours.update', $dataEntry), hoursForm(hoursWeek('07:00', '16:00')) + ['role_id' => $dataEntry->id])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Data Entry works its own hours from today — days before keep the hours they had.')
        ->assertSessionHas('settings_tab', 'role-hours');

    $schedule = WorkingSchedule::query()->sole();
    expect($schedule)->role_id->toBe($dataEntry->id)->user_id->toBeNull()->created_by->toBe($admin->id)
        ->and($schedule->starts_on->toDateString())->toBe('2026-10-05')
        ->and($schedule->week['monday'])->toBe(['working' => true, 'start' => '07:00', 'end' => '16:00', 'lunch_start' => '12:00', 'lunch_end' => '13:00'])
        ->and($schedule->week['saturday']['working'])->toBeFalse();

    $html = test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'role-hours']))->getContent();
    expect(Str::betweenFirst($html, 'data-role-hours="'.$dataEntry->id.'"', '</tr>'))
        ->toContain('Own hours')
        ->toContain('Mon–Fri 07:00–16:00')
        ->toContain('From Oct 05, 2026')
        ->toContain('40h')
        ->toContain('action="'.route('admin.settings.role-hours.destroy', $dataEntry).'"');
});

it('checks a role\'s hours like the working hours, and opens its window again to put them right', function () {
    $admin = userWithRole('Admin');
    $dataEntry = Role::findByName('Data Entry');
    $form = hoursForm(hoursWeek('07:00', '16:00'));
    $form['days']['monday']['end'] = '06:00';

    test()->actingAs($admin)->from(route('admin.settings.edit'))
        ->put(route('admin.settings.role-hours.update', $dataEntry), $form + ['role_id' => $dataEntry->id])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHasErrorsIn('role_hours', ['days.monday.end' => 'Monday has to finish after it starts.']);

    expect(WorkingSchedule::query()->count())->toBe(0);

    test()->actingAs($admin)->from(route('admin.settings.edit'))->followingRedirects()
        ->put(route('admin.settings.role-hours.update', $dataEntry), $form + ['role_id' => $dataEntry->id])
        ->assertSee('class="tab-pane fade show active" id="settings-pane-role-hours"', false)
        ->assertSee('document.getElementById("roleHoursModal-'.$dataEntry->id.'")', false)
        ->assertSee('Monday has to finish after it starts.')
        ->assertSee('value="06:00"', false);
});

it('puts a role back on the company\'s hours from today', function () {
    $admin = userWithRole('Admin');
    $dataEntry = Role::findByName('Data Entry');
    $morgan = userWithRole('Data Entry');
    hoursFor($dataEntry, hoursWeek('07:00', '16:00'));

    test()->actingAs($admin)->delete(route('admin.settings.role-hours.destroy', $dataEntry))
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Data Entry works the company\'s working hours again from today.');

    expect(WorkingSchedule::query()->sole()->ended_on->toDateString())->toBe('2026-10-05')
        ->and(Setting::isWorkingTime(hoursClock('2026-10-05 07:30'), $morgan->id))->toBeFalse()
        // The Friday before still counted in them.
        ->and(Setting::isWorkingTime(hoursClock('2026-10-02 07:30'), $morgan->id))->toBeTrue();

    test()->actingAs($admin)->delete(route('admin.settings.role-hours.destroy', $dataEntry))->assertNotFound();
});

it('keeps role and personal hours to Admin and HR Manager', function () {
    $dataEntry = Role::findByName('Data Entry');
    $morgan = userWithRole('Data Entry');
    $form = hoursForm(hoursWeek('07:00', '16:00'));

    foreach ([userWithRole('Senior Operations'), userWithRole('Sourcing')] as $neither) {
        test()->actingAs($neither)->put(route('admin.settings.role-hours.update', $dataEntry), $form)->assertForbidden();
        test()->actingAs($neither)->delete(route('admin.settings.role-hours.destroy', $dataEntry))->assertForbidden();
        test()->actingAs($neither)->put(route('admin.settings.user-hours.update', $morgan), $form)->assertForbidden();
        test()->actingAs($neither)->delete(route('admin.settings.user-hours.destroy', $morgan))->assertForbidden();

        $tabs = preg_match_all('/data-settings-tab="([a-z-]+)"/', test()->actingAs($neither)->get(route('admin.settings.edit'))->getContent(), $matches) ? $matches[1] : [];
        expect($tabs)->not->toContain('role-hours');
    }

    expect(WorkingSchedule::query()->count())->toBe(0);
});

it('gives HR Manager Role Hours — and someone\'s own hours — but leaves the company\'s working hours to the Admin', function () {
    $hr = User::factory()->create()->assignRole('HR Manager');
    $dataEntry = Role::findByName('Data Entry');
    $morgan = userWithRole('Data Entry');

    $html = test()->actingAs($hr)->get(route('admin.settings.edit', ['tab' => 'role-hours']))->assertOk()
        ->assertSee('class="tab-pane fade show active" id="settings-pane-role-hours"', false)
        ->assertSee('<span class="badge badge-soft-primary">HR Manager</span>', false)
        ->assertSee('id="roleHoursModal-'.$dataEntry->id.'"', false)
        ->getContent();

    expect(preg_match_all('/data-settings-tab="([a-z-]+)"/', $html, $matches) ? $matches[1] : [])
        ->toBe(['profile', 'password', 'preferences', 'role-hours']);

    test()->actingAs($hr)->put(route('admin.settings.role-hours.update', $dataEntry), hoursForm(hoursWeek('07:00', '16:00')) + ['role_id' => $dataEntry->id])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Data Entry works its own hours from today — days before keep the hours they had.');

    test()->actingAs($hr)->get(route('admin.users.show', [$morgan, 'tab' => 'working-hours']))->assertOk()
        ->assertSee('Data Entry\'s hours')
        ->assertSee('Set their own hours')
        ->assertSee('id="userHoursModal"', false);

    test()->actingAs($hr)->put(route('admin.settings.user-hours.update', $morgan), hoursForm(hoursWeek('10:00', '19:00')))
        ->assertRedirect(route('admin.users.show', ['user' => $morgan, 'tab' => 'working-hours']));

    test()->actingAs($hr)->delete(route('admin.settings.role-hours.destroy', $dataEntry))->assertRedirect(route('admin.settings.edit'));

    expect(WorkingSchedule::query()->where('created_by', $hr->id)->count())->toBe(2)
        ->and(Setting::isWorkingTime(hoursClock('2026-10-05 18:30'), $morgan->id))->toBeTrue();

    // The company's working hours stay the Admin's.
    test()->actingAs($hr)->patch(route('admin.settings.working-hours'), hoursForm(hoursWeek('07:00', '16:00')) + ['timezone' => 'Asia/Colombo'])
        ->assertForbidden();
});

// ---- a person's page -------------------------------------------------------

it('shows on someone\'s page the week their time counts in, and lets an Admin give them their own', function () {
    $admin = userWithRole('Admin');
    $morgan = userWithRole('Data Entry');
    hoursFor(Role::findByName('Data Entry'), hoursWeek('07:00', '16:00'));
    $page = fn () => test()->actingAs($admin)->get(route('admin.users.show', [$morgan, 'tab' => 'working-hours']))->assertOk();

    $page()
        ->assertSee('class="tab-pane fade show active" id="user-pane-working-hours"', false)
        ->assertSee('Data Entry\'s hours')
        ->assertSeeInOrder(['Monday', '07:00 – 16:00', '12:00 – 13:00', '8h'])
        ->assertSee('Set for everyone in Data Entry on Settings → Role Hours.')
        ->assertSee('Set their own hours')
        ->assertSee('id="userHoursModal"', false)
        ->assertDontSee('Use Data Entry\'s hours');

    test()->actingAs($admin)->put(route('admin.settings.user-hours.update', $morgan), hoursForm(hoursWeek('10:00', '19:00')))
        ->assertRedirect(route('admin.users.show', ['user' => $morgan, 'tab' => 'working-hours']))
        ->assertSessionHas('status', "{$morgan->name} works their own hours from today — days before keep the hours they had.");

    $page()
        ->assertSee('Their own hours')
        ->assertSee('10:00 – 19:00')
        ->assertSee('Use Data Entry\'s hours')
        ->assertSee('Their own hours, from Oct 05, 2026 — in place of Data Entry\'s hours.');

    expect(Setting::isWorkingTime(hoursClock('2026-10-05 18:30'), $morgan->id))->toBeTrue();

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'role-hours']))
        ->assertSee('href="'.route('admin.users.show', ['user' => $morgan, 'tab' => 'working-hours']).'"', false)
        ->assertSee('Mon–Fri 10:00–19:00');

    test()->actingAs($admin)->delete(route('admin.settings.user-hours.destroy', $morgan))
        ->assertRedirect(route('admin.users.show', ['user' => $morgan, 'tab' => 'working-hours']));

    // Back on Data Entry's.
    expect(Setting::isWorkingTime(hoursClock('2026-10-05 07:30'), $morgan->id))->toBeTrue()
        ->and(Setting::isWorkingTime(hoursClock('2026-10-05 18:30'), $morgan->id))->toBeFalse();
});

it('opens someone\'s hours window again when what was sent doesn\'t go in', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    $form = hoursForm(hoursWeek('07:00', '16:00'));
    $form['days']['tuesday']['lunch_end'] = '';

    test()->actingAs($admin)->from(route('admin.users.show', $riley))->followingRedirects()
        ->put(route('admin.settings.user-hours.update', $riley), $form)
        ->assertSee('class="tab-pane fade show active" id="user-pane-working-hours"', false)
        ->assertSee('document.getElementById("userHoursModal")', false)
        ->assertSee('Give both ends of Tuesday&#039;s lunch, or neither.', false);

    expect(WorkingSchedule::query()->count())->toBe(0);
});

it('shows someone\'s working week read-only to whoever can see their page but can\'t change hours', function () {
    $riley = userWithRole('Sourcing');
    $viewer = userWithRole('Senior Operations')->givePermissionTo('users.view');

    test()->actingAs($viewer)->get(route('admin.users.show', [$riley, 'tab' => 'working-hours']))->assertOk()
        ->assertSee('The working hours')
        ->assertSee('The company\'s working hours, from Settings → Working Hours.', false)
        ->assertDontSee('id="userHoursModal"', false)
        ->assertDontSee('Set their own hours');
});

// ---- how it reads ----------------------------------------------------------

it('reads a week in short', function () {
    $week = hoursWeek('08:30', '17:00');
    $week['friday']['end'] = '13:00';

    expect(Setting::weekLabel($week))->toBe('Mon–Thu 08:30–17:00, Fri 08:30–13:00')
        ->and(Setting::weekLabel(hoursWeek('08:30', '17:00', daysOff: Setting::WEEKDAYS)))->toBe('No working days')
        ->and(Setting::daysLabel(['monday', 'wednesday', 'saturday']))->toBe('Mon, Wed, Sat')
        ->and(Setting::daysLabel(['sunday', 'monday', 'tuesday', 'wednesday', 'thursday']))->toBe('Sun–Thu')
        ->and(Setting::daysLabel(['saturday', 'sunday']))->toBe('Weekends');
});
