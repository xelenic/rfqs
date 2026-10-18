<?php

use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
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
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => $half]],
    ])->assertSessionHasNoErrors();

    test()->actingAs(User::factory()->create()->assignRole('HR Manager'))
        ->patch(route('admin.attendance.approve', AttendanceSheet::query()->sole()))
        ->assertSessionHasNoErrors();
}

it('saves a half day — which half off, and a note — and lists it', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'morning', 'note' => 'Doctor\'s appointment']],
    ])->assertSessionHas('status', 'Attendance saved for today — 0 present, 1 on a half day, 0 on leave. Sent to HR Manager for approval.');

    expect(Attendance::query()->sole())->toMatchArray([
        'status' => 'half_day',
        'half_off' => 'morning',
        'reason' => null,
        'note' => 'Doctor\'s appointment',
    ]);

    $html = test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('value="half_day"', false)
        ->assertSee('Which half…')
        ->getContent();
    expect(Str::betweenFirst($html, 'data-attendance-sheet="2026-10-05"', '</tr>'))
        ->toContain('1 half day')
        ->toContain('Half day · Morning off')
        ->toContain('Doctor&#039;s appointment');
});

it('asks which half they were off — not why', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day']],
    ])->assertSessionHasErrorsIn('attendance', [
        "attendance.{$riley->id}.half_off" => 'Say which half they were off.',
    ])->assertSessionDoesntHaveErrors("attendance.{$riley->id}.reason", null, 'attendance');

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

// ---- A custom time off ----------------------------------------------------------

/**
 * $by submits Monday's sheet with Riley on a half day off $start to $end —
 * and HR Manager approves it.
 */
function customHalfDayForRiley(User $by, User $riley, string $start, string $end): void
{
    test()->actingAs($by)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'custom', 'half_off_start' => $start, 'half_off_end' => $end]],
    ])->assertSessionHasNoErrors();

    test()->actingAs(User::factory()->create()->assignRole('HR Manager'))
        ->patch(route('admin.attendance.approve', AttendanceSheet::query()->sole()))
        ->assertSessionHasNoErrors();
}

it('offers a custom time on a half day, and saves and lists it', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('<option value="custom" >Custom time (up to 4 hours)…</option>', false)
        ->assertSee('name="attendance['.$riley->id.'][half_off_start]"', false)
        ->assertSee('name="attendance['.$riley->id.'][half_off_end]"', false);

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'custom', 'half_off_start' => '10:00', 'half_off_end' => '14:00', 'note' => 'Bank']],
    ])->assertSessionHas('status', 'Attendance saved for today — 0 present, 1 on a half day, 0 on leave. Sent to HR Manager for approval.');

    expect(Attendance::query()->sole())->toMatchArray([
        'status' => 'half_day',
        'half_off' => 'custom',
        'half_off_start' => '10:00',
        'half_off_end' => '14:00',
        'note' => 'Bank',
    ]);

    $html = test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()->getContent();
    expect(Str::betweenFirst($html, 'data-attendance-sheet="2026-10-05"', '</tr>'))
        ->toContain('Half day · Off 10:00–14:00')
        ->toContain(e(json_encode([$riley->id => ['status' => 'half_day', 'half_off' => 'custom', 'note' => 'Bank', 'half_off_start' => '10:00', 'half_off_end' => '14:00']])));
});

it('doesn\'t count the working time in a custom time off, and counts the rest of the day', function () {
    ['riley' => $riley, 'ops' => $ops, 'rfq' => $rfq] = rileysMonday();

    customHalfDayForRiley($ops, $riley, '10:00', '14:00');

    // Off 10:00–12:30 and 13:30–14:00 — 3h, lunch between; the other 4h 30m counts.
    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray([
        'seconds' => 4 * 3600 + 30 * 60,
        'absent' => 3 * 3600,
    ]);
});

it('takes a custom time off of up to 4 working hours — lunch doesn\'t count towards it', function (string $start, string $end) {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'custom', 'half_off_start' => $start, 'half_off_end' => $end]],
    ])->assertSessionHasNoErrors();
})->with([
    'the morning' => ['08:30', '12:30'],
    'over lunch' => ['10:30', '15:30'],
    'an hour' => ['15:00', '16:00'],
]);

it('refuses a custom time off that isn\'t one', function (array $times, string $message) {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'custom'] + $times],
    ])->assertSessionHasErrorsIn('attendance', ["attendance.{$riley->id}.half_off" => $message]);

    expect(AttendanceSheet::query()->count())->toBe(0);
})->with([
    'no times' => [[], 'Give the time they were off — from and to.'],
    'no end' => [['half_off_start' => '10:00'], 'Give the time they were off — from and to.'],
    'ends first' => [['half_off_start' => '14:00', 'half_off_end' => '10:00'], 'The time off has to end after it starts.'],
    'out of hours' => [['half_off_start' => '18:00', 'half_off_end' => '20:00'], '18:00–20:00 is outside their working hours that day.'],
    'too long' => [['half_off_start' => '08:30', 'half_off_end' => '14:00'], 'A half day is up to 4h of working time — 08:30–14:00 is 4h 30m.'],
]);

it('says a time that isn\'t one is wrong', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'custom', 'half_off_start' => 'ten', 'half_off_end' => '14:00']],
    ])->assertSessionHasErrorsIn('attendance', ["attendance.{$riley->id}.half_off_start" => 'Give the time off as times, like 10:00 to 14:00.']);
});

it('keeps no custom times on a morning off, or on a day they were present', function (string $status, ?string $half) {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [$riley->id => array_filter(['status' => $status, 'half_off' => $half, 'half_off_start' => '10:00', 'half_off_end' => '14:00'])],
    ])->assertSessionHasNoErrors();

    expect(Attendance::query()->sole())->toMatchArray(['half_off_start' => null, 'half_off_end' => null]);
})->with([
    'morning off' => ['half_day', 'morning'],
    'present' => ['present', null],
]);

it('checks a correction against the sheet\'s own day', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();
    customHalfDayForRiley($ops, $riley, '10:00', '14:00');
    $sheet = AttendanceSheet::query()->sole();

    // Tuesday's a working day too — but it's Monday's sheet.
    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), [
        'attendance' => [$riley->id => ['status' => 'half_day', 'half_off' => 'custom', 'half_off_start' => '13:30', 'half_off_end' => '17:00']],
    ])->assertSessionHasNoErrors();

    expect($sheet->attendances()->sole())->toMatchArray(['half_off_start' => '13:30', 'half_off_end' => '17:00']);
});

it('shows a custom time off on the person\'s page', function () {
    ['riley' => $riley, 'ops' => $ops] = rileysMonday();
    customHalfDayForRiley($ops, $riley, '10:00', '14:00');

    $admin = userWithRole('Admin')->givePermissionTo(Permission::findOrCreate('users.view'));

    test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
        ->assertSee('Off 10:00–14:00');
});

it('reads a custom time off back from the book', function () {
    expect(Attendance::timeOffOf(Attendance::customOffStatus('10:00', '14:00')))->toBe(['start' => '10:00', 'end' => '14:00'])
        ->and(Attendance::timeOffOf(Attendance::MORNING_OFF))->toBe(['start' => '08:30', 'end' => '12:30'])
        ->and(Attendance::timeOffOf(Attendance::PRESENT))->toBeNull()
        ->and(Attendance::timeOffLabelOf(Attendance::customOffStatus('10:00', '14:00')))->toBe('Off 10:00–14:00')
        ->and(Attendance::timeOffLabelOf(Attendance::ABSENT))->toBeNull();
});
