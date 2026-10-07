<?php

use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
    Role::findOrCreate('HR Manager');

    // Attendance from Monday 2026-10-05: 08:30–17:00, lunch 12:30–13:30.
    Setting::put('attendance_since', '2026-10-05');
});

function halfDayClock(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * Riley with an RFQ all of Monday — 4h in the morning, 3h 30m in the
 * afternoon — seen at 17:00, and Senior Operations to mark them.
 *
 * @return array{riley: User, ops: User, rfq: Rfq}
 */
function rileysMonday(): array
{
    $riley = userWithRole('Sourcing');

    test()->travelTo(halfDayClock('2026-10-05 08:30'));
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    test()->travelTo(halfDayClock('2026-10-05 17:00'));

    return ['riley' => $riley, 'ops' => userWithRole('Senior Operations'), 'rfq' => $rfq];
}

/**
 * $by submits Monday's sheet with Riley on a half day, off $half — and HR
 * Manager approves it.
 */
function halfDayForRiley(User $by, User $riley, string $half): void
{
    test()->actingAs($by)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => $half, 'reason' => 'Personal leave']],
    ])->assertSessionHasNoErrors();

    test()->actingAs(User::factory()->create()->assignRole('HR Manager'))
        ->patch(route('admin.attendance.approve', AttendanceSheet::query()->sole()))
        ->assertSessionHasNoErrors();
}

it('saves a half day — which half off, and why — and lists it', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'morning', 'reason' => 'Sick leave', 'note' => 'Doctor\'s appointment']],
    ])->assertSessionHas('status', 'Attendance saved for today — 0 present, 1 on a half day, 0 on leave. Sent to HR Manager for approval.');

    expect(Attendance::query()->sole())->toMatchArray([
        'status' => 'half_day',
        'half_off' => 'morning',
        'reason' => 'Sick leave',
        'note' => 'Doctor\'s appointment',
    ]);

    $html = test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('value="half_day"', false)
        ->assertSee('Which half…')
        ->getContent();
    expect(Str::betweenFirst($html, 'data-attendance-sheet="2026-10-05"', '</tr>'))
        ->toContain('1 half day')
        ->toContain('Half day · Morning off')
        ->toContain('Sick leave');
});

it('asks which half they were off, and why', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day']],
    ])->assertSessionHasErrorsIn('attendance', [
        "attendance.{$riley->id}.half_off" => 'Say which half they were off.',
        "attendance.{$riley->id}.reason" => 'Say why they were on leave.',
    ]);

    expect(AttendanceSheet::query()->count())->toBe(0);
});

it('counts only the afternoon of a half day with the morning off', function () {
    ['riley' => $riley, 'ops' => $ops, 'rfq' => $rfq] = rileysMonday();

    halfDayForRiley($ops, $riley, 'morning');

    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray([
        'seconds' => 3 * 3600 + 30 * 60,
        'absent' => 4 * 3600,
        'awaiting' => 0,
    ]);
});

it('counts only the morning of a half day with the afternoon off', function () {
    ['riley' => $riley, 'ops' => $ops, 'rfq' => $rfq] = rileysMonday();

    halfDayForRiley($ops, $riley, 'afternoon');

    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray([
        'seconds' => 4 * 3600,
        'absent' => 3 * 3600 + 30 * 60,
    ]);
});

it('doesn\'t count the morning off as Settings has it, and counts the rest of the day', function () {
    ['riley' => $riley, 'ops' => $ops, 'rfq' => $rfq] = rileysMonday();
    Setting::putHalfDayOff(['morning' => ['start' => '08:30', 'end' => '10:30'], 'afternoon' => ['start' => '13:30', 'end' => '17:00']]);

    halfDayForRiley($ops, $riley, 'morning');

    // 08:30–10:30 off: 2h; the other 5h 30m of the day counts.
    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray([
        'seconds' => 5 * 3600 + 30 * 60,
        'absent' => 2 * 3600,
    ]);
});

it('measures how much of each day falls in the morning off and the afternoon off', function () {
    expect(Setting::workingSecondsWithHalfDaysOff(halfDayClock('2026-10-05 08:30'), halfDayClock('2026-10-06 10:30')))
        ->toBe([
            '2026-10-05' => ['all' => 7 * 3600 + 30 * 60, 'morning' => 4 * 3600, 'afternoon' => 3 * 3600 + 30 * 60],
            '2026-10-06' => ['all' => 2 * 3600, 'morning' => 2 * 3600, 'afternoon' => 0],
        ]);
});

it('lets an Admin set the morning off and the afternoon off on the Settings page', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'half-day']))->assertOk()
        ->assertSee('id="settings-pane-half-day"', false)
        ->assertSee('name="halves[morning][start]"', false)
        ->assertSee('value="08:30"', false);

    test()->actingAs($admin)->patch(route('admin.settings.half-day'), ['halves' => [
        'morning' => ['start' => '08:00', 'end' => '12:00'],
        'afternoon' => ['start' => '13:00', 'end' => '16:30'],
    ]])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Half day saved.')
        ->assertSessionHas('settings_tab', 'half-day');

    expect(Setting::halfDayOff())->toBe([
        'morning' => ['start' => '08:00', 'end' => '12:00'],
        'afternoon' => ['start' => '13:00', 'end' => '16:30'],
    ]);

    // The attendance sheet says what each half takes.
    userWithRole('Sourcing');
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('Morning off (08:00–12:00)')
        ->assertSee('Afternoon off (13:00–16:30)');
});

it('refuses a half day off that ends before it starts, or isn\'t a time', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->patch(route('admin.settings.half-day'), ['halves' => [
        'morning' => ['start' => '12:00', 'end' => '08:00'],
        'afternoon' => ['start' => 'noon', 'end' => '17:00'],
    ]])->assertSessionHasErrorsIn('half_day', [
        'halves.morning.end' => 'The morning off has to end after it starts.',
        'halves.afternoon.start' => 'Give when the afternoon off starts as a time, like 08:30.',
    ]);

    expect(Setting::halfDayOff())->toBe(Setting::DEFAULT_HALF_DAY_OFF);

    test()->actingAs(userWithRole('Senior Operations'))->patch(route('admin.settings.half-day'), ['halves' => Setting::DEFAULT_HALF_DAY_OFF])
        ->assertForbidden();
});
