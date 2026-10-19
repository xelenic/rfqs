<?php

use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\BusinessRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Someone holding HR Executive — as seeded: no RFQ permissions at all.
 */
function hrExecutive(): User
{
    Role::findOrCreate('HR Executive');

    return User::factory()->create()->assignRole('HR Executive');
}

it('seeds HR Executive with a description, adding and keeping people, and no RFQ permissions', function () {
    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class]);

    $role = Role::findByName('HR Executive');

    expect($role->description)->toContain('attendance sheet')
        ->and($role->permissions->pluck('name')->sort()->values()->all())->toBe(['users.create', 'users.edit', 'users.view']);
});

it('lets HR Executive review the attendance sheets, but not fill them in', function () {
    $hr = hrExecutive();
    $sourcing = userWithRole('Sourcing');

    test()->actingAs($hr)->get(route('admin.attendance.index'))->assertOk()
        ->assertDontSee('New attendance sheet')
        ->assertDontSee('id="attendanceModal"', false);

    test()->actingAs($hr)->post(route('admin.attendance.store'), [
        'date' => now()->toDateString(),
        'attendance' => [$sourcing->id => ['status' => 'absent']],
    ])->assertForbidden();

    expect(AttendanceSheet::query()->count())->toBe(0);
});

it('lets HR Executive see the Time Spent report, without opening the RFQs themselves', function () {
    $hr = hrExecutive();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ14001']), [1 => userWithRole('Sourcing')]);

    test()->actingAs($hr)->get(route('admin.reports.time-spent'))->assertOk()
        ->assertSee('RFQ14001')
        ->assertDontSee(route('admin.rfqs.show', $rfq));

    test()->actingAs($hr)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertForbidden();
    test()->actingAs($hr)->get(route('admin.rfqs.show', $rfq))->assertForbidden();
});

it('gives HR Executive a sidebar of Attendance and Time Spent, and no RFQ pages', function () {
    test()->actingAs(hrExecutive())->get(route('admin.dashboard'))->assertOk()
        ->assertSee('<div class="sidebar-section-title">Reports</div>', false)
        ->assertSee(route('admin.attendance.index'))
        ->assertSee(route('admin.reports.time-spent'))
        ->assertDontSee(route('admin.rfqs.index', ['status' => 'Pending']));
});

/**
 * Runs the migration that renamed HR Manager to HR Executive.
 */
function renameHrManagerRole(): void
{
    (require database_path('migrations/2026_10_19_072154_rename_hr_manager_role_to_hr_executive.php'))->up();
}

it('renames an HR Manager role from before to HR Executive, keeping its people and permissions', function () {
    $role = Role::create(['name' => 'HR Manager'])->givePermissionTo(Permission::findOrCreate('users.view'));
    $alex = User::factory()->create()->assignRole($role);

    renameHrManagerRole();

    expect(Role::query()->where('name', 'HR Manager')->exists())->toBeFalse()
        ->and(Role::findByName('HR Executive')->id)->toBe($role->id)
        ->and($alex->fresh()->hasRole('HR Executive'))->toBeTrue()
        ->and($alex->fresh()->can('users.view'))->toBeTrue()
        ->and($alex->fresh()->canApproveAttendance())->toBeTrue();
});

it('folds HR Manager into an HR Executive role already made, people and permissions too', function () {
    $executive = Role::create(['name' => 'HR Executive']);
    $both = User::factory()->create()->assignRole($executive);
    $manager = Role::create(['name' => 'HR Manager'])->givePermissionTo(Permission::findOrCreate('users.edit'));
    $alex = User::factory()->create()->assignRole($manager);
    $both->assignRole($manager);

    renameHrManagerRole();

    expect(Role::query()->where('name', 'HR Manager')->exists())->toBeFalse()
        ->and($alex->fresh()->hasRole('HR Executive'))->toBeTrue()
        ->and($alex->fresh()->can('users.edit'))->toBeTrue()
        ->and($both->fresh()->roles->pluck('name')->all())->toBe(['HR Executive']);
});

it('leaves an HR Executive role alone when there\'s no HR Manager', function () {
    $executive = Role::create(['name' => 'HR Executive']);

    renameHrManagerRole();

    expect(Role::query()->pluck('name')->all())->toContain('HR Executive')->not->toContain('HR Manager')
        ->and(Role::findByName('HR Executive')->id)->toBe($executive->id);
});
