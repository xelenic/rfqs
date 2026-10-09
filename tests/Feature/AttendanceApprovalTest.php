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
    Role::findOrCreate('HR Manager');

    // Attendance from Monday 2026-10-05 — and it's Monday, 11:00.
    Setting::put('attendance_since', '2026-10-05');
    test()->travelTo(CarbonImmutable::parse('2026-10-05 11:00', Setting::DEFAULT_TIMEZONE));
});

/**
 * Senior Operations submits Monday's sheet: Riley present, Morgan on sick leave.
 *
 * @return array{ops: User, riley: User, morgan: User, sheet: AttendanceSheet}
 */
function mondaysSheet(): array
{
    $ops = userWithRole('Senior Operations');
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');

    test()->actingAs($ops)->post(route('admin.attendance.store'), [
        'date' => '2026-10-05',
        'attendance' => [
            $riley->id => ['status' => 'present'],
            $morgan->id => ['status' => 'absent', 'note' => 'Sick'],
        ],
    ])->assertSessionHasNoErrors();

    return ['ops' => $ops, 'riley' => $riley, 'morgan' => $morgan, 'sheet' => AttendanceSheet::query()->sole()];
}

function hrReviewer(): User
{
    return User::factory()->create()->assignRole('HR Manager');
}

it('sends a submitted sheet to HR Manager, who sees it waiting with Approve and Return', function () {
    ['ops' => $ops, 'sheet' => $sheet] = mondaysSheet();
    $hr = hrReviewer();

    expect($sheet->isAwaitingApproval())->toBeTrue()
        ->and(AttendanceSheet::awaitingApprovalCount())->toBe(1);

    $html = test()->actingAs($hr)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('title="1 awaiting your approval">1</span>', false)
        ->getContent();
    $row = Str::betweenFirst($html, 'data-attendance-sheet="2026-10-05"', '</tr>');

    expect($row)->toContain('Awaiting HR approval')
        ->toContain('action="'.route('admin.attendance.approve', $sheet).'"')
        ->toContain('data-action="'.route('admin.attendance.return', $sheet).'"')
        ->not->toContain('js-attendance-open');

    // Senior Operations sees where it stands, and can still correct it — but not approve it.
    $opsRow = Str::betweenFirst(test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()->getContent(), 'data-attendance-sheet="2026-10-05"', '</tr>');
    expect($opsRow)->toContain('Awaiting HR approval')
        ->toContain('js-attendance-open')
        ->not->toContain(route('admin.attendance.approve', $sheet));
});

it('counts the day only once HR Manager approves it', function () {
    ['riley' => $riley, 'sheet' => $sheet] = mondaysSheet();
    $hr = hrReviewer();

    $rfq = Rfq::factory()->create();
    test()->travelTo(CarbonImmutable::parse('2026-10-05 09:00', Setting::DEFAULT_TIMEZONE));
    $rfq = splitAmong($rfq, [1 => $riley]);
    test()->travelTo(CarbonImmutable::parse('2026-10-05 11:00', Setting::DEFAULT_TIMEZONE));

    expect($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray(['seconds' => 0, 'awaiting' => 7200]);

    test()->actingAs($hr)->patch(route('admin.attendance.approve', $sheet))
        ->assertRedirect(route('admin.attendance.index'))
        ->assertSessionHas('status', 'Attendance for today approved — it counts now.');

    expect($sheet->refresh())
        ->isApproved()->toBeTrue()
        ->approved_by->toBe($hr->id)
        ->and($rfq->refresh()->timeSpent()['roles']['Sourcing'])->toMatchArray(['seconds' => 7200, 'awaiting' => 0]);

    test()->actingAs($hr)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('Approved')
        ->assertSee('by '.e($hr->name), false)
        ->assertDontSee(route('admin.attendance.approve', $sheet));

    // Done with: nothing left to approve or return.
    test()->actingAs($hr)->patch(route('admin.attendance.approve', $sheet))->assertStatus(422);
    test()->actingAs($hr)->patch(route('admin.attendance.return', $sheet), ['reason' => 'x'])->assertStatus(422);
});

it('lets HR Manager return a sheet to Senior Operations, with what needs correcting, until it\'s submitted again', function () {
    ['ops' => $ops, 'morgan' => $morgan, 'sheet' => $sheet] = mondaysSheet();
    $hr = hrReviewer();

    test()->actingAs($hr)->patch(route('admin.attendance.return', $sheet), ['reason' => 'Morgan was on casual leave, not sick'])
        ->assertSessionHas('status', 'Attendance for today returned to Senior Operations.');

    expect($sheet->refresh())
        ->isReturned()->toBeTrue()
        ->returned_by->toBe($hr->id)
        ->return_reason->toBe('Morgan was on casual leave, not sick')
        ->and(AttendanceSheet::returnedCount())->toBe(1);

    $html = test()->actingAs($ops)->get(route('admin.attendance.index'))->assertOk()->getContent();
    $row = Str::betweenFirst($html, 'data-attendance-sheet="2026-10-05"', '</tr>');
    expect($row)->toContain('Returned')
        ->toContain('Morgan was on casual leave, not sick')
        ->toContain('Correct')
        ->and($html)->toContain('title="1 returned by HR Manager">1</span>');

    // Corrected and submitted again: HR Manager's to review afresh.
    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), ['attendance' => [
        $morgan->id => ['status' => 'absent', 'note' => 'Casual'],
    ]])->assertSessionHasNoErrors();

    expect($sheet->refresh())
        ->isAwaitingApproval()->toBeTrue()
        ->return_reason->toBeNull();
});

it('asks HR Manager what needs correcting, reopening the popup against the same sheet', function () {
    ['sheet' => $sheet] = mondaysSheet();
    $hr = hrReviewer();

    test()->actingAs($hr)->from(route('admin.attendance.index'))
        ->patch(route('admin.attendance.return', $sheet), ['reason' => '', 'return_sheet_id' => $sheet->id])
        ->assertRedirect(route('admin.attendance.index'));

    test()->actingAs($hr)->get(route('admin.attendance.index'))->assertOk()
        ->assertSee('action="'.route('admin.attendance.return', $sheet).'"', false)
        ->assertSee('Say what needs correcting.')
        ->assertSee('getElementById(\'attendanceReturnModal\')).show()', false);

    expect($sheet->refresh()->isAwaitingApproval())->toBeTrue();
});

it('sends an approved sheet back for approval once it\'s corrected', function () {
    ['ops' => $ops, 'riley' => $riley, 'sheet' => $sheet] = mondaysSheet();
    test()->actingAs(hrReviewer())->patch(route('admin.attendance.approve', $sheet));

    test()->actingAs($ops)->put(route('admin.attendance.update', $sheet), ['attendance' => [
        $riley->id => ['status' => 'absent'],
    ]])->assertSessionHas('status', 'Attendance saved for today — 0 present, 1 on leave. Sent to HR Manager for approval.');

    expect($sheet->refresh())
        ->isAwaitingApproval()->toBeTrue()
        ->approved_by->toBeNull();
});

it('lets only HR Manager and Admin approve or return a sheet', function () {
    ['sheet' => $sheet] = mondaysSheet();

    foreach (['Senior Operations', 'Sourcing', 'Business Development'] as $role) {
        test()->actingAs(userWithRole($role))->patch(route('admin.attendance.approve', $sheet))->assertForbidden();
        test()->actingAs(userWithRole($role))->patch(route('admin.attendance.return', $sheet), ['reason' => 'x'])->assertForbidden();
    }

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.attendance.approve', $sheet))->assertSessionHasNoErrors();

    expect($sheet->refresh()->isApproved())->toBeTrue();
});
