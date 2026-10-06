<?php

use App\Models\Attendance;
use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
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

/**
 * Submits a new day's sheet from the popup: everyone who belongs on it
 * present, but those in $absent (user id => reason).
 *
 * @param  array<int, string>  $absent
 */
function makeSheet($by, string $date, array $absent = [])
{
    $attendance = AttendanceSheet::people()->mapWithKeys(fn ($person) => [$person->id => array_key_exists($person->id, $absent)
        ? ['status' => 'absent', 'reason' => $absent[$person->id]]
        : ['status' => 'present']])->all();

    return test()->actingAs($by)->post(route('admin.attendance.store'), ['date' => $date, 'attendance' => $attendance]);
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
        test()->actingAs(userWithRole($role))->post(route('admin.attendance.store'), ['date' => '2026-10-05', 'attendance' => []])->assertForbidden();
    }
});

it('fills a new sheet in a popup, everyone present to start', function () {
    ['riley' => $riley] = rileysMorning();
    $dataEntry = userWithRole('Data Entry');
    $assistant = userWithRole('GM Assistant');
    userWithRole('General Manager');
    $ops = userWithRole('Senior Operations');

    $html = test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('No attendance submitted yet')
        ->assertSee('New attendance sheet')
        ->assertSee('id="attendanceModal"', false)
        ->assertSee('data-action="'.route('admin.attendance.store').'" data-date="2026-10-05"', false)
        // Not a live page: a refresh would wipe a half-filled sheet.
        ->assertSee('id="live-main" data-live="off"', false)
        ->getContent();

    // Everyone who belongs on it, present; nobody else.
    $popup = Str::betweenFirst($html, 'id="attendanceModal"', '</form>');
    foreach ([$riley, $dataEntry, $assistant] as $person) {
        expect(Str::betweenFirst($popup, 'data-attendance-user="'.$person->id.'"', '</tr>'))->toContain('value="present" autocomplete="off" checked');
    }
    expect(substr_count($popup, 'data-attendance-user='))->toBe(3);
});

it('submits the day\'s sheet with everyone\'s attendance at once', function () {
    ['riley' => $riley] = rileysMorning();
    $dataEntry = userWithRole('Data Entry');
    $ops = userWithRole('Senior Operations');

    makeSheet($ops, '2026-10-05', [$dataEntry->id => 'Sick leave'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.attendance.index'))
        ->assertSessionHas('status', 'Attendance saved for today — 1 present, 1 on leave.');

    $sheet = AttendanceSheet::query()->sole();
    expect($sheet->created_by)->toBe($ops->id)
        ->and($sheet->attendances()->where('user_id', $riley->id)->value('status'))->toBe('present')
        ->and($sheet->attendances()->where('user_id', $dataEntry->id)->first())->toMatchArray(['status' => 'absent', 'reason' => 'Sick leave']);

    // A second for the same day is refused — that one's edited instead.
    makeSheet($ops, '2026-10-05')
        ->assertSessionHasErrorsIn('attendance', ['date' => 'There\'s already a sheet for that day — only one per day. Edit it from the list.']);
    expect(AttendanceSheet::query()->count())->toBe(1);
});

it('lists each day\'s submitted sheet, with who was absent and why, and an Edit that opens it', function () {
    ['riley' => $riley] = rileysMorning();
    $dataEntry = userWithRole('Data Entry');
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05', [$dataEntry->id => 'Casual leave']);

    test()->travelTo(atLk('2026-10-06 10:00'));
    makeSheet($ops, '2026-10-06');

    $html = test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()->getContent();
    $monday = Str::betweenFirst($html, 'data-attendance-sheet="2026-10-05"', '</tr>');
    $sheet = AttendanceSheet::query()->where('date', '2026-10-05')->sole();

    // Newest first.
    expect(strpos($html, 'data-attendance-sheet="2026-10-06"'))->toBeLessThan(strpos($html, 'data-attendance-sheet="2026-10-05"'))
        ->and($monday)->toContain('1 present')
        ->toContain(e($dataEntry->name))
        ->toContain('Casual leave')
        ->toContain(e($ops->name))
        ->toContain('data-action="'.route('admin.attendance.update', $sheet).'"')
        ->toContain('data-sheet-id="'.$sheet->id.'"')
        ->toContain(e(json_encode([$riley->id => ['status' => 'present', 'reason' => null, 'note' => null], $dataEntry->id => ['status' => 'absent', 'reason' => 'Casual leave', 'note' => null]])));
});

it('brings a refused submission back in the popup, as it was sent', function () {
    ['riley' => $riley] = rileysMorning();
    $ops = userWithRole('Senior Operations');

    $html = test()->actingAs($ops)
        ->from(route('admin.attendance.index'))
        ->followingRedirects()
        ->post(route('admin.attendance.store'), ['date' => '2026-10-05', 'attendance' => [$riley->id => ['status' => 'absent', 'note' => 'Called in']]])
        ->assertOk()
        ->getContent();

    $row = Str::betweenFirst(Str::betweenFirst($html, 'id="attendanceModal"', '</form>'), 'data-attendance-user="'.$riley->id.'"', '</tr>');

    expect($html)->toContain('bootstrap.Modal.getOrCreateInstance(document.getElementById(\'attendanceModal\')).show()')
        ->and($row)->toContain('value="absent" autocomplete="off" checked')
        ->toContain('value="Called in"')
        ->toContain('Say why they were on leave.');
    expect(AttendanceSheet::query()->count())->toBe(0);
});

it('marks someone absent, with why, and back again', function () {
    ['riley' => $riley] = rileysMorning();
    $ops = userWithRole('Senior Operations');
    makeSheet($ops, '2026-10-05');
    $sheet = AttendanceSheet::query()->with('attendances')->sole();

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), marks($sheet, [$riley->id => 'Sick leave']))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.attendance.index'))
        ->assertSessionHas('status', 'Attendance saved for today — 0 present, 1 on leave.');

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

    // The popup has a line for them — not on the sheet's own marks yet.
    expect(Str::betweenFirst(test()->actingAs($ops)->get(route('admin.attendance.index'))->getContent(), 'id="attendanceModal"', '</form>'))
        ->toContain('data-attendance-user="'.$newcomer->id.'"');

    $payload = marks($sheet);
    $payload['attendance'][$newcomer->id] = ['status' => 'absent', 'reason' => 'Personal leave'];

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), $payload)->assertSessionHasNoErrors();

    expect($sheet->attendances()->where('user_id', $newcomer->id)->value('status'))->toBe('absent');
});

it('only makes sheets for days since attendance started, up to today', function (string $date) {
    test()->travelTo(atLk('2026-10-06 10:00'));

    userWithRole('Sourcing');

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
        ->assertSee('2h on leave');
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

it('leaves no sheet behind when a new one has someone on it who doesn\'t belong', function () {
    $riley = userWithRole('Sourcing');

    test()->actingAs(userWithRole('Senior Operations'))->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [
            $riley->id => ['status' => 'present'],
            userWithRole('General Manager')->id => ['status' => 'present'],
        ],
    ])->assertStatus(422);

    expect(AttendanceSheet::query()->count())->toBe(0);
});

it('opens New attendance sheet on the newest day without one, and turns it off once every day has one', function () {
    userWithRole('Sourcing');
    $ops = userWithRole('Senior Operations');

    // Wednesday: Monday's done, Tuesday's and today's aren't.
    test()->travelTo(atLk('2026-10-07 10:00'));
    makeSheet($ops, '2026-10-05');

    $newButton = fn () => Str::betweenFirst(test()->actingAs($ops)->get(route('admin.attendance.index'))->getContent(), '<span>Attendance sheets</span>', '</div>');

    expect($newButton())->toContain('data-date="2026-10-07"')->toContain('New attendance sheet');

    makeSheet($ops, '2026-10-07');
    expect($newButton())->toContain('data-date="2026-10-06"');

    makeSheet($ops, '2026-10-06');
    expect($newButton())->toContain('Today\'s sheet is done')->toContain('disabled')
        ->not->toContain('js-attendance-open');
});

it('tells the popup which days already have a sheet, so a second is turned away before it\'s sent', function () {
    userWithRole('Sourcing');
    $ops = userWithRole('Senior Operations');
    test()->travelTo(atLk('2026-10-06 10:00'));
    makeSheet($ops, '2026-10-05');

    test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('data-sheet-dates="'.e(json_encode(['2026-10-05'])).'"', false)
        ->assertSee('id="attendance-date-taken"', false);
});

it('keeps one sheet a day in the database too', function () {
    AttendanceSheet::factory()->create(['date' => '2026-10-05']);

    expect(fn () => AttendanceSheet::factory()->create(['date' => '2026-10-05']))
        ->toThrow(UniqueConstraintViolationException::class);
});
