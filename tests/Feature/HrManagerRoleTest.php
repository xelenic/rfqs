<?php

use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\BusinessRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Someone holding HR Manager — as seeded: no RFQ permissions at all.
 */
function hrManager(): User
{
    Role::findOrCreate('HR Manager');

    return User::factory()->create()->assignRole('HR Manager');
}

it('seeds HR Manager with a description and no RFQ permissions', function () {
    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class]);

    $role = Role::findByName('HR Manager');

    expect($role->description)->toContain('attendance sheet')
        ->and($role->permissions)->toBeEmpty();
});

it('lets HR Manager review the attendance sheets, but not fill them in', function () {
    $hr = hrManager();
    $sourcing = userWithRole('Sourcing');

    test()->actingAs($hr)->get(route('admin.attendance.index'))->assertOk()
        ->assertDontSee('New attendance sheet')
        ->assertDontSee('id="attendanceModal"', false);

    test()->actingAs($hr)->post(route('admin.attendance.store'), [
        'date' => now()->toDateString(),
        'attendance' => [$sourcing->id => ['status' => 'absent', 'reason' => 'Sick leave']],
    ])->assertForbidden();

    expect(AttendanceSheet::query()->count())->toBe(0);
});

it('lets HR Manager see the Time Spent report, without opening the RFQs themselves', function () {
    $hr = hrManager();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ14001']), [1 => userWithRole('Sourcing')]);

    test()->actingAs($hr)->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('RFQ14001')
        ->assertDontSee(route('admin.rfqs.show', $rfq));

    test()->actingAs($hr)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertForbidden();
    test()->actingAs($hr)->get(route('admin.rfqs.show', $rfq))->assertForbidden();
});

it('gives HR Manager a sidebar of Attendance and Time Spent, and no RFQ pages', function () {
    test()->actingAs(hrManager())->get(route('admin.dashboard'))->assertOk()
        ->assertSee('<div class="sidebar-section-title">Reports</div>', false)
        ->assertSee(route('admin.attendance.index'))
        ->assertSee(route('admin.reports.time-spent'))
        ->assertDontSee(route('admin.rfqs.index', ['status' => 'Pending']));
});
