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

    // Attendance from Monday 2026-10-05.
    Setting::put('attendance_since', '2026-10-05');
});

/**
 * A moment in Colombo time — 2026-10-05 is a Monday.
 */
function atLk(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * Riley's Urgent RFQ11001, with them 09:00–11:00 on Monday, seen at 11:00.
 *
 * @return array{riley: User, rfq: Rfq}
 */
function rileysMorning(): array
{
    $riley = userWithRole('Sourcing');

    test()->travelTo(atLk('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ11001', 'priority_level' => 'Urgent']), [1 => $riley]);
    test()->travelTo(atLk('2026-10-05 11:00'));
    $rfq->refresh()->completeSourcingPart(1);

    return ['riley' => $riley, 'rfq' => $rfq->refresh()];
}

function makeSheet($by, string $date)
{
    return test()->actingAs($by)->post(route('admin.attendance.store'), ['date' => $date]);
}

/**
 * The marks the sheet's form sends: everyone present but those in $absent
 * (user id => reason).
 *
 * @param  array<int, string>  $absent
 */
function marks(AttendanceSheet $sheet, array $absent = []): array
{
    return ['attendance' => $sheet->attendances->mapWithKeys(fn (Attendance $line) => [$line->user_id => array_key_exists($line->user_id, $absent)
        ? ['status' => 'absent', 'reason' => $absent[$line->user_id]]
        : ['status' => 'present']])->all()];
}

// ---- The sheet ---------------------------------------------------------------------

it('is kept by Senior Operations and Admin alone', function () {
    rileysMorning();

    foreach (['Senior Operations', 'Admin'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.attendance.index'))->assertOk()
            ->assertSee('<title>Attendance', false);
        test()->actingAs(userWithRole($role))->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.attendance.index'));
    }

    foreach (['Sourcing', 'Data Entry', 'GM Assistant', 'General Manager', 'Business Development'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.attendance.index'))->assertForbidden();
        test()->actingAs(userWithRole($role))->post(route('admin.attendance.store'), ['date' => '2026-10-05'])->assertForbidden();
    }
});

it('makes the day\'s sheet with every Sourcing, Data Entry and GM Assistant person present', function () {
    ['riley' => $riley] = rileysMorning();
    $dataEntry = userWithRole('Data Entry');
    $assistant = userWithRole('GM Assistant');
    userWithRole('General Manager');
    $ops = userWithRole('Senior Operations');

    test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('No attendance sheet for today yet')
        ->assertSee('Make attendance sheet');

    makeSheet($ops, '2026-10-05')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.attendance.index', ['date' => '2026-10-05']))
        ->assertSessionHas('status', "Attendance sheet made for today — everyone's present until you mark them absent.");

    $sheet = AttendanceSheet::query()->sole();
    expect($sheet->created_by)->toBe($ops->id)
        ->and($sheet->attendances->pluck('user_id')->sort()->values()->all())->toBe(collect([$riley->id, $dataEntry->id, $assistant->id])->sort()->values()->all())
        ->and($sheet->attendances->pluck('status')->unique()->all())->toBe(['present']);

    // Making it again just opens it.
    makeSheet($ops, '2026-10-05')->assertSessionHas('status', 'There\'s already a sheet for today.');
    expect(AttendanceSheet::query()->count())->toBe(1);
});

it('shows each person\'s time that day on the sheet', function () {
    ['riley' => $riley] = rileysMorning();
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05');

    $html = test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('Save attendance')
        ->getContent();

    expect(Str::betweenFirst($html, 'data-attendance-user="'.$riley->id.'"', '</tr>'))
        ->toContain('2h')
        ->toContain('value="present" autocomplete="off" checked');
});

it('marks someone absent, with why, and back again', function () {
    ['riley' => $riley] = rileysMorning();
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05');
    $sheet = AttendanceSheet::query()->with('attendances')->sole();

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), marks($sheet, [$riley->id => 'Sick leave']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Attendance saved for today — 0 present, 1 absent.');

    expect($sheet->attendances()->where('user_id', $riley->id)->first())->toMatchArray(['status' => 'absent', 'reason' => 'Sick leave'])
        ->and($sheet->refresh()->updated_by)->toBe($ops->id);

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), marks($sheet->load('attendances')))->assertSessionHasNoErrors();

    expect($sheet->attendances()->where('user_id', $riley->id)->first())->toMatchArray(['status' => 'present', 'reason' => null]);
});

it('needs a reason for an absence, and nothing that isn\'t one', function (?string $reason) {
    ['riley' => $riley] = rileysMorning();
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05');
    $sheet = AttendanceSheet::query()->with('attendances')->sole();

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), ['attendance' => [$riley->id => ['status' => 'absent', 'reason' => $reason]]])
        ->assertSessionHasErrorsIn('attendance', ["attendance.{$riley->id}.reason"]);

    expect($sheet->attendances()->where('user_id', $riley->id)->value('status'))->toBe('present');
})->with([null, 'Holiday in Bali']);

it('keeps the sheet to Sourcing, Data Entry and GM Assistant people', function () {
    rileysMorning();
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05');
    $sheet = AttendanceSheet::query()->sole();

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), ['attendance' => [userWithRole('General Manager')->id => ['status' => 'present']]])
        ->assertStatus(422);
});

it('adds someone who joined after the sheet was made when it\'s saved', function () {
    rileysMorning();
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05');
    $sheet = AttendanceSheet::query()->with('attendances')->sole();

    $newcomer = userWithRole('Data Entry');

    expect(Str::betweenFirst(test()->actingAs($ops)->get(route('admin.attendance.index'))->getContent(), 'data-attendance-user="'.$newcomer->id.'"', '</tr>'))
        ->toContain('New');

    $payload = marks($sheet);
    $payload['attendance'][$newcomer->id] = ['status' => 'absent', 'reason' => 'Personal leave'];

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), $payload)->assertSessionHasNoErrors();

    expect($sheet->attendances()->where('user_id', $newcomer->id)->value('status'))->toBe('absent');
});

it('only makes sheets for days since attendance started, up to today', function (string $date) {
    test()->travelTo(atLk('2026-10-06 10:00'));

    makeSheet(userWithRole('Senior Operations'), $date)->assertSessionHasErrorsIn('attendance', ['date']);

    expect(AttendanceSheet::query()->count())->toBe(0);
})->with(['2026-10-07', '2026-10-02', 'Tuesday']);

it('lists the working days still without a sheet, skipping days off', function () {
    // Friday 9th: Mon–Fri since the 5th, with Wednesday's sheet made.
    test()->travelTo(atLk('2026-10-09 10:00'));
    AttendanceSheet::factory()->create(['date' => '2026-10-07']);

    expect(AttendanceSheet::missingDays())->toBe(['2026-10-09', '2026-10-08', '2026-10-06', '2026-10-05']);

    // Monday 12th: the weekend needs none.
    test()->travelTo(atLk('2026-10-12 10:00'));
    expect(AttendanceSheet::missingDays())->toBe(['2026-10-12', '2026-10-09', '2026-10-08', '2026-10-06', '2026-10-05']);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.dashboard'))->assertOk()
        ->assertSee('title="5 working days without a sheet"', false);
});

// ---- What it counts --------------------------------------------------------------------

it('holds a day\'s time back until its sheet is made', function () {
    ['rfq' => $rfq] = rileysMorning();

    expect($rfq->timeSpent()['roles']['Sourcing'])->toMatchArray(['seconds' => 0, 'awaiting' => 7200, 'absent' => 0]);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('2h awaiting attendance');
});

it('counts the day for someone present, and not for someone absent', function () {
    ['riley' => $riley, 'rfq' => $rfq] = rileysMorning();
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05');

    expect($rfq->timeSpent()['roles']['Sourcing'])->toMatchArray(['seconds' => 7200, 'awaiting' => 0]);

    $sheet = AttendanceSheet::query()->with('attendances')->sole();
    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), marks($sheet, [$riley->id => 'Casual leave']));

    expect($rfq->timeSpent()['roles']['Sourcing'])->toMatchArray(['seconds' => 0, 'absent' => 7200]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('2h absent');
});

it('counts days before attendance started, and everything while it\'s off', function () {
    $riley = userWithRole('Sourcing');

    test()->travelTo(atLk('2026-10-02 09:00'));
    $before = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    test()->travelTo(atLk('2026-10-02 11:00'));
    $before->refresh()->completeSourcingPart(1);

    expect($before->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray(['seconds' => 7200, 'awaiting' => 0]);

    ['rfq' => $rfq] = rileysMorning();
    Setting::put('attendance_since', null);

    expect($rfq->timeSpent()['roles']['Sourcing'])->toMatchArray(['seconds' => 7200, 'awaiting' => 0]);
});

it('credits Data Entry\'s time to whoever finishes it, by their attendance', function () {
    ['rfq' => $rfq] = rileysMorning();
    $dataEntry = userWithRole('Data Entry');

    test()->travelTo(atLk('2026-10-05 12:00'));
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    expect($rfq->refresh()->steps->firstWhere('step', 'data_entry')->worked_by)->toBe($dataEntry->id)
        ->and($rfq->timeSpent()['roles']['Data Entry'])->toMatchArray(['seconds' => 0, 'awaiting' => 3600]);

    makeSheet(userWithRole('Senior Operations'), '2026-10-05');

    expect($rfq->timeSpent()['roles']['Data Entry'])->toMatchArray(['seconds' => 3600, 'awaiting' => 0]);
});

it('doesn\'t count a day absent against Sourcing\'s deadline', function () {
    $riley = userWithRole('Sourcing');
    $ops = userWithRole('Senior Operations');

    test()->travelTo(atLk('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(['priority_level' => 'Urgent']), [1 => $riley]);

    // Tuesday 10:00: all of Monday with them — 7h 30m against an Urgent 4h.
    test()->travelTo(atLk('2026-10-06 10:00'));
    expect($rfq->refresh()->sourcingDeadline()['exceeded'])->toBeTrue();

    // Off sick on Monday: only Tuesday 08:30–10:00 counts.
    makeSheet($ops, '2026-10-05');
    $sheet = AttendanceSheet::query()->with('attendances')->sole();
    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), marks($sheet, [$riley->id => 'Sick leave']));

    expect($rfq->refresh()->sourcingDeadline())->toMatchArray(['remaining' => 4 * 3600 - 90 * 60, 'exceeded' => false]);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('2h 30m left');
});

it('turns attendance on and off from Settings', function () {
    Setting::put('attendance_since', null);
    $payload = ['days' => collect(Setting::workingHours())->map(fn (array $day) => ['working' => $day['working'] ? '1' : '0'] + $day)->all(), 'timezone' => Setting::timezone()];

    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit', ['tab' => 'working-hours']))->assertOk()
        ->assertSee('Attendance from');

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.working-hours'), $payload + ['attendance_since' => '2026-10-05'])
        ->assertSessionHasNoErrors();
    expect(Setting::attendanceSince())->toBe('2026-10-05');

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.settings.working-hours'), $payload + ['attendance_since' => ''])
        ->assertSessionHasNoErrors();
    expect(Setting::attendanceSince())->toBeNull();

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('Attendance isn\'t counted yet', false);
});
