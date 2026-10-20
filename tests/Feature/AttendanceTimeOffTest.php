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
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }
    Permission::findOrCreate('users.view');
    Role::findByName('HR Executive')->givePermissionTo('users.view');

    // Attendance since December — and it's Wednesday 21 October, 11:00.
    Setting::put('attendance_since', '2025-12-01');
    test()->travelTo(CarbonImmutable::parse('2026-10-21 11:00', Setting::DEFAULT_TIMEZONE));
});

/**
 * A sheet for $date with $marks (user id => their line), approved by HR
 * Executive unless said otherwise.
 *
 * @param  array<int, array<string, string>>  $marks
 */
function timeOffSheet(string $date, array $marks, bool $approved = true): AttendanceSheet
{
    $sheet = AttendanceSheet::query()->create(['date' => $date]);

    foreach ($marks as $userId => $mark) {
        $sheet->attendances()->create(['user_id' => $userId] + $mark);
    }

    if ($approved) {
        $sheet->approve(User::factory()->create()->assignRole('HR Executive'));
    }

    return $sheet;
}

/**
 * Riley's time off: a leave last December, a morning off in September, a
 * leave (Sick) and an afternoon 10:00–14:00 off this month — and a leave on
 * yesterday's sheet, still awaiting approval. Morgan's leave is theirs alone.
 *
 * @return array{riley: User, morgan: User, sheets: array<string, AttendanceSheet>}
 */
function rileysTimeOff(): array
{
    $riley = User::factory()->create(['name' => 'Riley Sourcing'])->assignRole('Sourcing');
    $morgan = User::factory()->create(['name' => 'Morgan Entry'])->assignRole('Data Entry');
    $present = ['status' => Attendance::PRESENT];

    $sheets = [
        '2025-12-15' => timeOffSheet('2025-12-15', [$riley->id => ['status' => Attendance::ABSENT], $morgan->id => $present]),
        '2026-09-14' => timeOffSheet('2026-09-14', [$riley->id => ['status' => Attendance::HALF_DAY, 'half_off' => 'morning'], $morgan->id => $present]),
        '2026-10-05' => timeOffSheet('2026-10-05', [$riley->id => ['status' => Attendance::ABSENT, 'note' => 'Sick'], $morgan->id => $present]),
        '2026-10-12' => timeOffSheet('2026-10-12', [$riley->id => ['status' => Attendance::HALF_DAY, 'half_off' => Attendance::CUSTOM_HALF, 'half_off_start' => '10:00', 'half_off_end' => '14:00', 'note' => 'Bank'], $morgan->id => ['status' => Attendance::ABSENT]]),
        '2026-10-20' => timeOffSheet('2026-10-20', [$riley->id => ['status' => Attendance::ABSENT], $morgan->id => $present], approved: false),
    ];

    return ['riley' => $riley, 'morgan' => $morgan, 'sheets' => $sheets];
}

it('tallies each person\'s leaves and half days by approved sheet, with what still awaits approval', function () {
    ['riley' => $riley, 'morgan' => $morgan] = rileysTimeOff();
    $nobody = User::factory()->create()->assignRole('Sourcing');

    $tally = Attendance::timeOffTally([$riley->id, $morgan->id, $nobody->id, null]);

    expect($tally[$riley->id])->toBe([
        'month' => ['leaves' => 1, 'half_days' => 1],
        'year' => ['leaves' => 1, 'half_days' => 2],
        'all' => ['leaves' => 2, 'half_days' => 2],
        'pending' => ['leaves' => 1, 'half_days' => 0],
        'last_leave' => '2026-10-05',
        'last_half_day' => '2026-10-12',
    ])
        ->and($tally[$morgan->id]['all'])->toBe(['leaves' => 1, 'half_days' => 0])
        ->and($tally[$nobody->id]['all'])->toBe(['leaves' => 0, 'half_days' => 0])
        ->and($tally[$nobody->id]['last_leave'])->toBeNull()
        ->and(Attendance::timeOffTally([]))->toBe([])
        // A half day is half a day off.
        ->and(Attendance::daysOffLabel($tally[$riley->id]['month']))->toBe('1.5')
        ->and(Attendance::daysOffLabel($tally[$riley->id]['year']))->toBe('2')
        ->and(Attendance::daysOffLabel($tally[$riley->id]['all']))->toBe('3');
});

it('shows a person\'s leaves and half days on their page', function () {
    ['riley' => $riley, 'sheets' => $sheets] = rileysTimeOff();

    $html = test()->actingAs(User::factory()->create()->assignRole('HR Executive'))
        ->get(route('admin.users.show', $riley))->assertOk()
        // At a glance, under their name.
        ->assertSee('href="'.route('admin.users.show', ['user' => $riley, 'tab' => 'attendance']).'"', false)
        ->assertSeeInOrder(['This year: 1 leave', '· 2 half days'])
        ->assertSeeInOrder(['Leaves this year', '1', 'This month: 1 · In all: 2'])
        ->assertSeeInOrder(['Half days this year', '2', 'This month: 1 · In all: 2'])
        ->assertSeeInOrder(['Days off this year', '2', 'A half day counts as half · In all: 3'])
        ->assertSeeInOrder(['Last time off', 'Mon, Oct 12, 2026', 'Last leave: Mon, Oct 5, 2026 · Last half day: Mon, Oct 12, 2026'])
        ->assertSee('Not counted yet: 1 leave on sheets HR Executive hasn\'t approved yet.', false)
        ->getContent();

    // This year's, latest first — last December's isn't among them.
    expect(substr_count($html, 'data-time-off-day="'))->toBe(4)
        ->and($html)->not->toContain('data-time-off-day="2025-12-15"');

    $row = fn (string $date) => Str::betweenFirst($html, 'data-time-off-day="'.$date.'"', '</tr>');
    expect(strpos($html, 'data-time-off-day="2026-10-20"'))->toBeLessThan(strpos($html, 'data-time-off-day="2026-09-14"'))
        ->and($row('2026-10-20'))->toContain('Full-Day Leave')->toContain('Awaiting approval — not counted yet')
        ->and($row('2026-10-12'))->toContain('Half-Day Leave – Custom Time')->toContain('10:00–14:00')->toContain('Bank')->toContain('Approved')
        ->and($row('2026-10-05'))->toContain('Full-Day Leave')->toContain('Sick')
        ->toContain('href="'.route('admin.attendance.show', $sheets['2026-10-05']).'"')
        ->and($row('2026-09-14'))->toContain('Half-Day Leave – First Half')->toContain('08:30–12:30 · 4h');
});

it('shows no time off for someone whose time isn\'t tracked, or while attendance isn\'t kept', function () {
    ['riley' => $riley] = rileysTimeOff();
    $hr = User::factory()->create()->assignRole('HR Executive');

    test()->actingAs($hr)->get(route('admin.users.show', User::factory()->create()->assignRole('Business Development')))->assertOk()
        ->assertDontSee('data-time-off-summary', false)
        ->assertDontSee('data-time-off-stats', false);

    Setting::put('attendance_since', null);

    test()->actingAs($hr)->get(route('admin.users.show', $riley))->assertOk()
        ->assertDontSee('data-time-off-summary', false)
        ->assertDontSee('Leaves this year');
});

it('shows a person\'s time off on hovering their name on a sheet\'s page', function () {
    ['riley' => $riley, 'morgan' => $morgan, 'sheets' => $sheets] = rileysTimeOff();

    $html = test()->actingAs(User::factory()->create()->assignRole('HR Executive'))
        ->get(route('admin.attendance.show', $sheets['2026-10-12']))->assertOk()
        ->getContent();

    expect(Str::betweenFirst($html, 'data-attendance-line="'.$riley->id.'"', '</tr>'))
        ->toContain('data-hover-card="time-off-'.$riley->id.'"')
        ->and(Str::betweenFirst($html, 'data-attendance-line="'.$morgan->id.'"', '</tr>'))
        ->toContain('data-hover-card="time-off-'.$morgan->id.'"');

    $card = Str::betweenFirst($html, '<template id="time-off-'.$riley->id.'">', '</template>');
    expect($card)->toContain('Riley Sourcing')
        ->toMatch('#<th>This month</th>\s*<td>1</td>\s*<td>1</td>\s*<td>1\.5</td>#')
        ->toMatch('#<th>This year</th>\s*<td>1</td>\s*<td>2</td>\s*<td>2</td>#')
        ->toMatch('#<th>In all</th>\s*<td>2</td>\s*<td>2</td>\s*<td>3</td>#')
        ->toContain('Last leave</span> Mon, Oct 5, 2026')
        ->toContain('Last half day</span> Mon, Oct 12, 2026')
        ->toContain('+ 1 leave on sheets not yet approved — not counted yet');

    expect(substr_count($html, '<template id="time-off-'.$riley->id.'">'))->toBe(1);
});

it('shows the time off of anyone off on the attendance list, and of everyone in the sheet popup', function () {
    ['riley' => $riley, 'morgan' => $morgan] = rileysTimeOff();

    $html = test()->actingAs(User::factory()->create()->assignRole('HR Executive'))
        ->get(route('admin.attendance.index'))->assertOk()
        ->getContent();

    expect(Str::betweenFirst($html, 'data-attendance-sheet="2026-10-12"', '</tr>'))
        ->toContain('data-hover-card="time-off-'.$riley->id.'">Riley Sourcing</span>')
        ->toContain('data-hover-card="time-off-'.$morgan->id.'">Morgan Entry</span>')
        // One card each, however many sheets name them.
        ->and(substr_count($html, '<template id="time-off-'.$riley->id.'">'))->toBe(1);

    // Senior Operations, marking a sheet, sees it for everyone in the popup.
    $popup = Str::betweenFirst(test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.attendance.index'))->assertOk()->getContent(), 'id="attendanceModal"', '</form>');
    expect($popup)->toContain('data-hover-card="time-off-'.$riley->id.'">Riley Sourcing</span>')
        ->toContain('data-hover-card="time-off-'.$morgan->id.'">Morgan Entry</span>');
});

it('names each kind of leave, with its hours and working time off', function () {
    ['riley' => $riley] = rileysTimeOff();
    $line = fn (array $mark) => new Attendance(['user_id' => $riley->id] + $mark);

    expect($line(['status' => Attendance::ABSENT])->leaveTypeLabel())->toBe('Full-Day Leave')
        ->and($line(['status' => Attendance::HALF_DAY, 'half_off' => 'morning'])->leaveTypeLabel())->toBe('Half-Day Leave – First Half')
        ->and($line(['status' => Attendance::HALF_DAY, 'half_off' => 'afternoon'])->leaveTypeLabel())->toBe('Half-Day Leave – Second Half')
        ->and($line(['status' => Attendance::HALF_DAY, 'half_off' => Attendance::CUSTOM_HALF, 'half_off_start' => '09:00', 'half_off_end' => '11:00'])->leaveTypeLabel())->toBe('Half-Day Leave – Custom Time')
        ->and($line(['status' => Attendance::PRESENT])->leaveTypeLabel())->toBeNull()
        // A Monday: their whole working day, less lunch.
        ->and($line(['status' => Attendance::ABSENT])->hoursOff('2026-10-19'))->toBe(['start' => '08:30', 'end' => '17:00'])
        ->and($line(['status' => Attendance::ABSENT])->workingSecondsOff('2026-10-19'))->toBe(27000)
        ->and($line(['status' => Attendance::HALF_DAY, 'half_off' => 'morning'])->workingSecondsOff('2026-10-19'))->toBe(4 * 3600)
        // A Sunday: no working day to be off.
        ->and($line(['status' => Attendance::ABSENT])->hoursOff('2026-10-18'))->toBeNull()
        ->and($line(['status' => Attendance::ABSENT])->workingSecondsOff('2026-10-18'))->toBe(0)
        ->and($line(['status' => Attendance::PRESENT])->workingSecondsOff('2026-10-19'))->toBe(0);
});
