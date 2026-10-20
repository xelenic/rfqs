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
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }

    // Attendance from Monday 2026-10-05; it's Wednesday morning.
    Setting::put('attendance_since', '2026-10-05');
    test()->travelTo(CarbonImmutable::parse('2026-10-07 10:00', Setting::DEFAULT_TIMEZONE));
});

/**
 * HR Executive, as seeded: no RFQ permissions.
 */
function hrPerson(): User
{
    return User::factory()->create(['name' => 'Alex Fernando'])->assignRole('HR Executive');
}

/**
 * Riley (Sourcing), Morgan (Data Entry) and Casey (GM Assistant), with this
 * week's sheets: Monday's approved — Riley present, Morgan on leave, Casey
 * off the morning; Tuesday's and today's awaiting approval — today Riley
 * present, Morgan off 10:00–14:00, Casey on leave.
 *
 * @return array{riley: User, morgan: User, casey: User}
 */
function hrWeek(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $morgan = tap(userWithRole('Data Entry'))->update(['name' => 'Morgan Ito']);
    $casey = tap(userWithRole('GM Assistant'))->update(['name' => 'Casey Brooks']);

    $sheet = fn (string $date, array $marks, bool $approved) => tap(AttendanceSheet::factory()->create(['date' => $date, 'approved_at' => $approved ? now() : null]),
        fn (AttendanceSheet $sheet) => collect($marks)->each(fn (array $mark, int $userId) => $sheet->attendances()->create(['user_id' => $userId] + $mark)));

    $sheet('2026-10-05', [
        $riley->id => ['status' => Attendance::PRESENT],
        $morgan->id => ['status' => Attendance::ABSENT, 'note' => 'Sick'],
        $casey->id => ['status' => Attendance::HALF_DAY, 'half_off' => 'morning'],
    ], true);
    $sheet('2026-10-06', [$riley->id => ['status' => Attendance::PRESENT], $morgan->id => ['status' => Attendance::PRESENT], $casey->id => ['status' => Attendance::PRESENT]], false);
    $sheet('2026-10-07', [
        $riley->id => ['status' => Attendance::PRESENT],
        $morgan->id => ['status' => Attendance::HALF_DAY, 'half_off' => Attendance::CUSTOM_HALF, 'half_off_start' => '10:00', 'half_off_end' => '14:00'],
        $casey->id => ['status' => Attendance::ABSENT, 'note' => 'Family event'],
    ], false);

    return compact('riley', 'morgan', 'casey');
}

it('gives HR Executive a dashboard of their own, about people and attendance', function () {
    hrWeek();

    $response = test()->actingAs(hrPerson())->get(route('admin.dashboard'))->assertOk()
        ->assertViewIs('admin.dashboard.hr-executive')
        ->assertSee('Welcome back, Alex')
        ->assertSeeInOrder(['Present today', 'On leave today', 'Awaiting your approval', 'Days without a sheet', 'Attendance', 'Active people'])
        ->assertDontSee('Total Users');

    expect($response->viewData('overview'))->toMatchArray([
        'todayCounts' => ['present' => 1, 'half' => 1, 'leave' => 1, 'unmarked' => 0],
        'awaitingApproval' => 2,
        'missingDays' => [],
        'attendanceRate' => 50,
    ]);
});

it('lists today\'s sheet — each person\'s mark and today\'s hours', function () {
    hrWeek();

    $html = test()->actingAs(hrPerson())->get(route('admin.dashboard'))->assertOk()->getContent();
    $today = Str::betweenFirst($html, 'id="hr-today"', '</table>');

    expect($today)->toContain('Awaiting your approval')
        ->and(Str::betweenFirst($today, 'Casey Brooks', '</tr>'))->toContain('Full-Day Leave · 08:30–17:00')->toContain('Family event')->toContain('08:30–17:00')
        ->and(Str::betweenFirst($today, 'Morgan Ito', '</tr>'))->toContain('Half-Day Leave – Custom Time · 10:00–14:00')
        ->and(Str::betweenFirst($today, 'Riley Chen', '</tr>'))->toContain('Present');
});

it('counts each person\'s working days over the period by the approved sheets', function () {
    ['riley' => $riley, 'morgan' => $morgan, 'casey' => $casey] = hrWeek();

    $attendance = test()->actingAs(hrPerson())->get(route('admin.dashboard', ['period' => 'week']))->assertOk()
        ->viewData('overview')['attendance']->keyBy(fn (array $row) => $row['user']->name);

    // Monday's approved; Tuesday's and today's still waiting, so not counted yet.
    expect(collect($attendance['Riley Chen'])->except('user')->all())->toBe(['present' => 1, 'half' => 0, 'leave' => 0, 'unmarked' => 2, 'rate' => 100])
        ->and(collect($attendance['Morgan Ito'])->except('user')->all())->toBe(['present' => 0, 'half' => 0, 'leave' => 1, 'unmarked' => 2, 'rate' => 0])
        ->and(collect($attendance['Casey Brooks'])->except('user')->all())->toBe(['present' => 0, 'half' => 1, 'leave' => 0, 'unmarked' => 2, 'rate' => 50]);
});

it('tells HR what\'s waiting on them, what\'s missing, and who\'s on leave', function () {
    hrWeek();
    AttendanceSheet::query()->whereDate('date', '2026-10-06')->delete();

    $titles = collect(test()->actingAs(hrPerson())->get(route('admin.dashboard'))->assertOk()->viewData('notifications'))->pluck('title')->all();

    expect($titles)->toBe([
        '1 attendance sheet awaits your approval',
        '1 earlier working day has no sheet',
        '1 person is on leave today',
    ]);
});

it('says when today\'s sheet isn\'t in yet', function () {
    hrWeek();
    AttendanceSheet::query()->whereDate('date', '2026-10-07')->delete();

    $response = test()->actingAs(hrPerson())->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Today&#039;s sheet isn&#039;t in yet', false);

    expect(collect($response->viewData('notifications'))->pluck('title'))->toContain('Today\'s attendance sheet isn\'t in yet')
        ->and($response->viewData('overview')['todayCounts'])->toBe(['present' => 0, 'half' => 0, 'leave' => 0, 'unmarked' => 3]);
});

it('says so when attendance isn\'t counted yet', function () {
    Setting::put('attendance_since', null);
    hrWeek();

    test()->actingAs(hrPerson())->get(route('admin.dashboard'))->assertOk()
        ->assertSee('Attendance isn&#039;t counted yet', false);
});

it('shows the team by role, who joined in the period and who\'s deactivated', function () {
    hrWeek();
    userWithRole('Sourcing')->forceFill(['deactivated_at' => now()])->save();

    $overview = test()->actingAs(hrPerson())->get(route('admin.dashboard'))->assertOk()->viewData('overview');

    expect($overview['team']->pluck('count', 'role')->all())->toBe(['Data Entry' => 1, 'GM Assistant' => 1, 'HR Executive' => 1, 'Sourcing' => 1])
        ->and($overview['activePeople'])->toBe(4)
        ->and($overview['deactivated'])->toBe(1)
        ->and($overview['joined'])->toBe(5);
});

it('leads to HR\'s own pages, and none of the RFQs', function () {
    $html = test()->actingAs(hrPerson())->get(route('admin.dashboard'))->assertOk()->getContent();

    expect(Str::after($html, '<div class="card-header">Go to</div>'))
        ->toContain('href="'.route('admin.attendance.index').'"')
        ->toContain('href="'.route('admin.reports.time-spent').'"')
        ->toContain('href="'.route('admin.reports.sourcing-daily').'"')
        ->toContain('href="'.route('admin.reports.rfq-tracking').'"')
        ->and($html)->not->toContain('href="'.route('admin.rfqs.index', ['status' => 'Pending']).'"');
});

it('keeps Admin on their own dashboard', function () {
    $admin = userWithRole('Admin')->assignRole('HR Executive');

    test()->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertViewIs('admin.dashboard.admin');
});
