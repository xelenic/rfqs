<?php

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
    Role::findOrCreate('HR Executive');

    // Attendance from Monday 2026-10-05 — and it's Monday, 11:00.
    Setting::put('attendance_since', '2026-10-05');
    test()->travelTo(CarbonImmutable::parse('2026-10-05 11:00', Setting::DEFAULT_TIMEZONE));
});

/**
 * Senior Operations (Sam) submits Monday's sheet: Riley present, Morgan on
 * leave, Jordan off 10:00–14:00.
 *
 * @return array{ops: User, riley: User, morgan: User, jordan: User, sheet: AttendanceSheet}
 */
function sheetWithEveryMark(): array
{
    $ops = User::factory()->create(['name' => 'Sam Operations'])->assignRole('Senior Operations');
    $riley = User::factory()->create(['name' => 'Riley Sourcing'])->assignRole('Sourcing');
    $morgan = User::factory()->create(['name' => 'Morgan Entry'])->assignRole('Data Entry');
    $jordan = User::factory()->create(['name' => 'Jordan Assistant'])->assignRole('GM Assistant');

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [
            $riley->id => ['status' => 'present'],
            $morgan->id => ['status' => 'absent', 'note' => 'Sick'],
            $jordan->id => ['status' => 'half_day', 'half_off' => 'custom', 'half_off_start' => '10:00', 'half_off_end' => '14:00', 'note' => 'Bank'],
        ],
    ])->assertSessionHasNoErrors();

    return ['ops' => $ops, 'riley' => $riley, 'morgan' => $morgan, 'jordan' => $jordan, 'sheet' => AttendanceSheet::query()->sole()];
}

function sheetReviewer(): User
{
    return User::factory()->create(['name' => 'Harper Hr'])->assignRole('HR Executive');
}

it('opens a sheet\'s own page for Admin, Senior Operations and HR Executive alone', function () {
    ['sheet' => $sheet] = sheetWithEveryMark();

    test()->actingAs(sheetReviewer())->get(route('admin.attendance.show', $sheet))->assertOk()
        ->assertSee('<title>Attendance — Mon, Oct 5, 2026', false);

    foreach (['Senior Operations', 'Admin'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.attendance.show', $sheet))->assertOk();
    }

    foreach (['Sourcing', 'Data Entry', 'GM Assistant', 'General Manager', 'Business Development'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.attendance.show', $sheet))->assertForbidden();
    }
});

it('shows everyone on the sheet with their role, mark and note, and who submitted it when', function () {
    ['riley' => $riley, 'morgan' => $morgan, 'jordan' => $jordan, 'sheet' => $sheet] = sheetWithEveryMark();

    $html = test()->actingAs(sheetReviewer())->get(route('admin.attendance.show', $sheet))->assertOk()
        ->assertSeeInOrder(['Monday, October 5, 2026', 'Today', 'Awaiting HR approval'])
        ->assertSeeInOrder(['People on the sheet', '3', 'Full-Day Leave', '1', 'Half-Day Leave', '1', 'Worked the full day', '1'])
        // By name.
        ->assertSeeInOrder(['Jordan Assistant', 'Morgan Entry', 'Riley Sourcing'])
        ->assertSeeInOrder(['Submitted by', 'Sam Operations', 'Submitted', 'Oct 05, 2026 11:00 AM'])
        ->assertDontSee(' ago')
        ->getContent();

    expect(Str::betweenFirst($html, 'data-attendance-line="'.$riley->id.'"', '</tr>'))
        ->toContain('Sourcing')->toContain('Present — full day')
        ->and(Str::betweenFirst($html, 'data-attendance-line="'.$morgan->id.'"', '</tr>'))
        ->toContain('Data Entry')->toContain('Full-Day Leave')->toContain('Sick')
        ->and(Str::betweenFirst($html, 'data-attendance-line="'.$jordan->id.'"', '</tr>'))
        ->toContain('GM Assistant')->toContain('Half-Day Leave – Custom Time')->toContain('10:00–14:00')->toContain('Bank');
});

it('lets HR Executive approve from the page, and comes back to it', function () {
    ['sheet' => $sheet] = sheetWithEveryMark();
    $hr = sheetReviewer();

    $html = test()->actingAs($hr)->get(route('admin.attendance.show', $sheet))->assertOk()->getContent();
    expect($html)->toContain('action="'.route('admin.attendance.approve', $sheet).'"')
        ->toContain('data-action="'.route('admin.attendance.return', $sheet).'"')
        ->toContain('id="attendanceReturnModal"')
        // HR Executive reviews; they don't fill sheets in.
        ->not->toContain('js-attendance-open')
        ->not->toContain('id="attendanceModal"');

    test()->actingAs($hr)->patch(route('admin.attendance.approve', $sheet), ['return_to' => 'show'])
        ->assertRedirect(route('admin.attendance.show', $sheet))
        ->assertSessionHas('status', 'Attendance for today approved — it counts now.');

    test()->actingAs($hr)->get(route('admin.attendance.show', $sheet))->assertOk()
        ->assertSee('badge-soft-success"><i class="bi bi-check2-circle"></i> Approved', false)
        ->assertSeeInOrder(['Approved by', 'Harper Hr', 'Approved', 'Oct 05, 2026 11:00 AM'])
        ->assertDontSee(route('admin.attendance.approve', $sheet));

    expect($sheet->refresh()->isApproved())->toBeTrue();
});

it('lets HR Executive return it from the page, and Senior Operations correct it there', function () {
    ['ops' => $ops, 'morgan' => $morgan, 'sheet' => $sheet] = sheetWithEveryMark();
    $hr = sheetReviewer();

    test()->actingAs($hr)->patch(route('admin.attendance.return', $sheet), ['reason' => 'Morgan was on casual leave', 'return_to' => 'show'])
        ->assertRedirect(route('admin.attendance.show', $sheet))
        ->assertSessionHas('status', 'Attendance for today returned to Senior Operations.');

    $html = test()->actingAs($ops)->get(route('admin.attendance.show', $sheet))->assertOk()
        ->assertSeeInOrder(['What needs correcting', 'from Harper Hr', 'Morgan was on casual leave'])
        ->assertSeeInOrder(['Returned by', 'Harper Hr'])
        ->getContent();

    // Senior Operations corrects it here — the popup comes back here once saved.
    expect($html)->toContain('data-action="'.route('admin.attendance.update', $sheet).'"')
        ->toContain('<i class="bi bi-pencil"></i> Correct')
        ->toContain('id="attendanceModal"')
        ->toContain('<input type="hidden" name="return_to" value="show">')
        ->not->toContain(route('admin.attendance.approve', $sheet));

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), [
        'return_to' => 'show',
        'attendance' => [$morgan->id => ['status' => 'absent', 'note' => 'Casual']],
    ])->assertRedirect(route('admin.attendance.show', $sheet));

    expect($sheet->refresh()->isAwaitingApproval())->toBeTrue();
});

it('reopens the Return popup on the page when it\'s sent without a reason', function () {
    ['sheet' => $sheet] = sheetWithEveryMark();
    $hr = sheetReviewer();

    test()->actingAs($hr)->from(route('admin.attendance.show', $sheet))
        ->patch(route('admin.attendance.return', $sheet), ['reason' => '', 'return_sheet_id' => $sheet->id, 'return_to' => 'show'])
        ->assertRedirect(route('admin.attendance.show', $sheet));

    test()->actingAs($hr)->get(route('admin.attendance.show', $sheet))->assertOk()
        ->assertSee('Say what needs correcting.')
        ->assertSee('getElementById(\'attendanceReturnModal\')).show()', false);

    expect($sheet->refresh()->isAwaitingApproval())->toBeTrue();
});

it('steps to the sheets either side, by day', function () {
    ['ops' => $ops, 'sheet' => $monday] = sheetWithEveryMark();

    test()->travelTo(CarbonImmutable::parse('2026-10-07 11:00', Setting::DEFAULT_TIMEZONE));
    foreach (['2026-10-07', '2026-10-06'] as $day) {
        test()->actingAs($ops)->post(route('admin.attendance.store'), ['date' => $day, 'attendance' => $monday->attendances->mapWithKeys(fn ($line) => [$line->user_id => ['status' => 'present']])->all()])
            ->assertSessionHasNoErrors();
    }
    $tuesday = AttendanceSheet::query()->whereDate('date', '2026-10-06')->sole();
    $wednesday = AttendanceSheet::query()->whereDate('date', '2026-10-07')->sole();

    test()->actingAs($ops)->get(route('admin.attendance.show', $tuesday))->assertOk()
        ->assertSee('href="'.route('admin.attendance.show', $monday).'" class="btn btn-outline-secondary" title="Mon, Oct 5, 2026"', false)
        ->assertSee('href="'.route('admin.attendance.show', $wednesday).'" class="btn btn-outline-secondary" title="Wed, Oct 7, 2026"', false);

    test()->actingAs($ops)->get(route('admin.attendance.show', $monday))->assertOk()
        ->assertSee('<button type="button" class="btn btn-outline-secondary" disabled><i class="bi bi-chevron-left"></i> Previous</button>', false);
});

it('is linked from the attendance list and HR Executive\'s dashboard', function () {
    ['sheet' => $sheet] = sheetWithEveryMark();
    $hr = sheetReviewer();
    $link = 'href="'.route('admin.attendance.show', $sheet).'"';

    $row = Str::betweenFirst(test()->actingAs($hr)->get(route('admin.attendance.index'))->assertOk()->getContent(), 'data-attendance-sheet="2026-10-05"', '</tr>');
    expect($row)->toContain($link)->toContain('<i class="bi bi-eye"></i> View');

    expect(test()->actingAs($hr)->get(route('admin.dashboard'))->assertOk()->getContent())->toContain($link);
});

it('opens a sheet from anywhere on its row in the list, which no longer counts who was present', function () {
    ['sheet' => $sheet] = sheetWithEveryMark();

    $html = test()->actingAs(sheetReviewer())->get(route('admin.attendance.index'))->assertOk()
        ->assertDontSee('<th>Present</th>', false)
        ->assertDontSee(' present</span>', false)
        ->getContent();

    expect($html)->toContain('<tr data-attendance-sheet="2026-10-05" data-row-href="'.route('admin.attendance.show', $sheet).'" class="is-clickable-row');
});

it('names each leave on the list — its type, hours and working time — and says when nobody was off', function () {
    ['ops' => $ops, 'morgan' => $morgan, 'jordan' => $jordan, 'sheet' => $sheet] = sheetWithEveryMark();
    $hr = sheetReviewer();

    $row = Str::betweenFirst(test()->actingAs($hr)->get(route('admin.attendance.index'))->assertOk()->getContent(), 'data-attendance-sheet="2026-10-05"', '</tr>');
    expect($row)->toMatch('#Morgan Entry</span>\s*<span class="badge badge-soft-danger"><i class="bi bi-x-lg"></i> Full-Day Leave</span>\s*<span class="text-muted-soft text-nowrap">08:30–17:00 · 7h 30m</span>#u')
        ->toMatch('#Jordan Assistant</span>\s*<span class="badge badge-soft-orange"><i class="bi bi-circle-half"></i> Half-Day Leave – Custom Time</span>\s*<span class="text-muted-soft text-nowrap">10:00–14:00 · 3h</span>#u')
        ->not->toContain('Riley Sourcing');

    // Corrected so everyone was in.
    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), ['attendance' => [
        $morgan->id => ['status' => 'present'],
        $jordan->id => ['status' => 'present'],
    ]])->assertSessionHasNoErrors();

    expect(Str::betweenFirst(test()->actingAs($hr)->get(route('admin.attendance.index'))->getContent(), 'data-attendance-sheet="2026-10-05"', '</tr>'))
        ->toContain('No leave — everyone was present');
});

it('lists the leave to review on the sheet\'s page — type, date, hours off, duration and note, full days first', function () {
    ['riley' => $riley, 'morgan' => $morgan, 'jordan' => $jordan, 'sheet' => $sheet] = sheetWithEveryMark();
    $first = User::factory()->create(['name' => 'Avery First'])->assignRole('Sourcing');
    $second = User::factory()->create(['name' => 'Blake Second'])->assignRole('Sourcing');
    $sheet->attendances()->create(['user_id' => $first->id, 'status' => 'half_day', 'half_off' => 'morning']);
    $sheet->attendances()->create(['user_id' => $second->id, 'status' => 'half_day', 'half_off' => 'afternoon']);

    $html = test()->actingAs(sheetReviewer())->get(route('admin.attendance.show', $sheet))->assertOk()->getContent();
    $review = Str::betweenFirst($html, 'id="leave-to-review"', '</table>');
    $line = fn (User $person) => Str::betweenFirst($review, 'data-leave-line="'.$person->id.'"', '</tr>');

    expect($review)->toContain('1 Full-Day · 3 Half-Day')
        ->not->toContain('data-leave-line="'.$riley->id.'"')
        // The full day first, then the half days by name.
        ->and(strpos($review, 'data-leave-line="'.$morgan->id.'"'))->toBeLessThan(strpos($review, 'data-leave-line="'.$first->id.'"'))
        ->and(strpos($review, 'data-leave-line="'.$first->id.'"'))->toBeLessThan(strpos($review, 'data-leave-line="'.$second->id.'"'))
        ->and(strpos($review, 'data-leave-line="'.$second->id.'"'))->toBeLessThan(strpos($review, 'data-leave-line="'.$jordan->id.'"'))
        ->and($line($morgan))->toContain('Full-Day Leave')->toContain('Mon, Oct 5, 2026')->toContain('08:30–17:00')->toContain('Their whole working day')->toContain('7h 30m')->toContain('Sick')
        ->and($line($first))->toContain('Half-Day Leave – First Half')->toContain('08:30–12:30')->toContain('4h')
        ->and($line($second))->toContain('Half-Day Leave – Second Half')->toContain('13:30–17:00')->toContain('3h 30m')
        ->and($line($jordan))->toContain('Half-Day Leave – Custom Time')->toContain('10:00–14:00')->toContain('3h')->toContain('Bank');
});
