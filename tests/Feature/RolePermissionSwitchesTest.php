<?php

use App\Impersonation;
use App\Models\Role;
use App\Permissions;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['roles.view', 'roles.edit', 'roles.create', 'permissions.view', 'permissions.edit', 'permissions.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }

    Role::findOrCreate('Admin')->givePermissionTo(['roles.view', 'roles.edit', 'roles.create', 'permissions.view', 'permissions.edit', 'permissions.delete']);
});

/**
 * Saves the role called $name on its Roles page with $on switched on, as an
 * Admin does — its name and description left as they are.
 *
 * @param  array<int, string>  $on
 */
function switchOnFor(string $name, array $on): Role
{
    $role = Role::findByName($name);

    test()->actingAs(userWithRole('Admin'))->put(route('admin.roles.update', $role), [
        'name' => $role->name,
        'description' => $role->description,
        'is_active' => '1',
        'permissions' => $on,
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.roles.index'));

    return $role->refresh();
}

/**
 * The permissions the role called $name has now, by name.
 *
 * @return array<int, string>
 */
function permissionsOf(string $name): array
{
    return Role::findByName($name)->permissions()->pluck('name')->sort()->values()->all();
}

it('lists every permission on a role\'s page as a switch, by area, on for what the role has', function () {
    $hr = Role::findOrCreate('HR Executive');
    Permission::findOrCreate('reports.export');

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.roles.edit', $hr))->assertOk()
        ->assertSeeInOrder(['RFQs', 'Reports', 'Attendance and hours', 'Users', 'Configuration', 'System', Permissions::OTHER_AREA])
        ->assertSee('Time Spent report')
        ->assertSee('How long each step took, and the deadlines exceeded.')
        ->getContent();

    $switch = fn (string $name) => preg_match('/<input[^>]*role="switch"[^>]*value="'.preg_quote($name, '/').'"[^>]*>/', $html, $match) ? $match[0] : null;

    expect($switch('reports.time-spent'))->toContain('checked')
        ->and($switch('attendance.approve'))->toContain('checked')
        ->and($switch('attendance.keep'))->not->toBeNull()->not->toContain('checked')
        ->and($switch('backups.manage'))->not->toBeNull()->not->toContain('checked')
        // One made on the Permissions page, by its own name.
        ->and($switch('reports.export'))->not->toBeNull();
});

it('takes a feature away from a role switched off, and gives one to a role switched on', function () {
    $hr = userWithRole('HR Executive');
    $sourcing = userWithRole('Sourcing');

    test()->actingAs($hr)->get(route('admin.reports.time-spent'))->assertOk();
    test()->actingAs($sourcing)->get(route('admin.reports.time-spent'))->assertForbidden();

    switchOnFor('HR Executive', array_diff(permissionsOf('HR Executive'), ['reports.time-spent']));
    switchOnFor('Sourcing', [...permissionsOf('Sourcing'), 'reports.time-spent']);

    test()->actingAs($hr->fresh())->get(route('admin.reports.time-spent'))->assertForbidden();
    test()->actingAs($hr->fresh())->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.reports.time-spent'));
    test()->actingAs($sourcing->fresh())->get(route('admin.reports.time-spent'))->assertOk();

    expect($hr->fresh()->canApproveAttendance())->toBeTrue();
});

it('hands an Admin\'s tools to another role: logs, backups, settings, group chats, logging in as someone', function () {
    $hr = userWithRole('HR Executive');
    $riley = userWithRole('Sourcing');

    test()->actingAs($hr)->get(route('admin.logs.index'))->assertForbidden();
    test()->actingAs($hr)->get(route('admin.backups.index'))->assertForbidden();
    expect(Impersonation::canStart($hr, $riley))->toBeFalse();

    switchOnFor('HR Executive', [...permissionsOf('HR Executive'), 'logs.view', 'backups.manage', 'settings.manage', 'message-groups.manage', 'users.impersonate']);
    $hr = $hr->fresh();

    test()->actingAs($hr)->get(route('admin.logs.index'))->assertOk();
    test()->actingAs($hr)->get(route('admin.backups.index'))->assertOk();
    test()->actingAs($hr)->get(route('admin.dashboard'))->assertSee(route('admin.logs.index'))->assertSee(route('admin.backups.index'));
    test()->actingAs($hr)->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('id="settings-tab-application"', false)
        ->assertSee('id="settings-tab-role-hours"', false)
        // The Danger Zone is a permission of its own.
        ->assertDontSee('id="settings-tab-danger-zone"', false);
    test()->actingAs($hr)->patch(route('admin.settings.application'), ['company_name' => 'Acme Marine', 'live_interval' => 15])
        ->assertSessionHasNoErrors();

    expect(Impersonation::canStart($hr, $riley))->toBeTrue()
        // Never as an Admin.
        ->and(Impersonation::canStart($hr, userWithRole('Admin')))->toBeFalse();
});

it('keeps what an Admin switched off when the role is renamed', function () {
    $ops = userWithRole('HBDs Operations');
    switchOnFor('HBDs Operations', array_diff(permissionsOf('HBDs Operations'), ['rfqs.critical-monitor']));

    $role = Role::findByName('HBDs Operations');
    test()->actingAs(userWithRole('Admin'))->put(route('admin.roles.update', $role), [
        'name' => 'Senior Ops',
        'is_active' => '1',
        'permissions' => $role->permissions->pluck('name')->all(),
    ])->assertSessionHasNoErrors();

    $ops = $ops->fresh();

    expect($ops->canSeeCriticalMonitor())->toBeFalse()
        ->and($ops->canListAllRfqs())->toBeTrue();
    test()->actingAs($ops)->get(route('admin.critical-monitor'))->assertForbidden();
});

it('gives a role it makes its slug\'s features, and one added on the Roles page only what was switched on', function () {
    $ops = Role::create(['name' => 'HBDs Operations']);
    $trainee = Role::create(['name' => 'Operation Assistant Trainee']);

    expect($ops->permissions->pluck('name')->all())->toEqualCanonicalizing(Permissions::defaultsFor('hbds-operations'))
        ->and($trainee->permissions->pluck('name'))->toContain('reports.performance')->not->toContain('rfqs.change-priority');

    test()->actingAs(userWithRole('Admin'))->post(route('admin.roles.store'), [
        'name' => 'HR Executive',
        'is_active' => '1',
        'permissions' => ['reports.time-spent'],
    ])->assertSessionHasNoErrors();

    expect(permissionsOf('HR Executive'))->toBe(['reports.time-spent']);
});

it('gives the roles there already what they could do by their names, and nothing more', function () {
    $migration = require database_path('migrations/2026_11_23_213920_add_feature_permissions.php');
    foreach (['HBDs Operations', 'Operation Assistant Trainee', 'HR Executive', 'Sourcing'] as $name) {
        Role::findOrCreate($name);
    }
    $migration->down();

    expect(Permission::query()->whereIn('name', array_keys(Permissions::AREAS['Reports']))->exists())->toBeFalse();

    $migration->up();
    $migration->up();

    expect(permissionsOf('Sourcing'))->toBe([])
        ->and(permissionsOf('HR Executive'))->toBe(['attendance.approve', 'reports.performance', 'reports.time-spent', 'users.change-details', 'working-hours.manage'])
        ->and(permissionsOf('HBDs Operations'))->toEqualCanonicalizing(Permissions::defaultsFor('hbds-operations'))
        ->and(permissionsOf('Operation Assistant Trainee'))->toEqualCanonicalizing(Permissions::defaultsFor('operation-assistant-trainee'))
        ->and(permissionsOf('Operation Assistant Trainee'))->not->toContain('rfqs.change-priority')
        // Run twice, nothing's given twice.
        ->and(DB::table('role_has_permissions')->count())->toBe(DB::table('role_has_permissions')->select('role_id', 'permission_id')->distinct()->get()->count());
});

it('lists each feature the migration makes, with the same roles, in App\Permissions', function () {
    $features = (new ReflectionClassConstant(require database_path('migrations/2026_11_23_213920_add_feature_permissions.php'), 'FEATURES'))->getValue();

    $catalogue = collect(Permissions::AREAS)->flatMap(fn (array $permissions) => $permissions)
        ->filter(fn (array $permission) => $permission['roles'] !== null)
        ->map(fn (array $permission) => $permission['roles'])
        ->all();

    expect($features)->toEqual($catalogue);
});

it('refuses to rename or delete a built-in permission, but not one added on the Permissions page', function () {
    $builtIn = Permission::findOrCreate('reports.time-spent');
    $added = Permission::findOrCreate('reports.export');
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.permissions.index'))->assertOk()
        ->assertSeeInOrder(['Time Spent report', 'reports.time-spent', 'Built in'], false);
    test()->actingAs($admin)->put(route('admin.permissions.update', $builtIn), ['name' => 'reports.hours'])
        ->assertRedirect(route('admin.permissions.index'))->assertSessionHas('error');
    test()->actingAs($admin)->delete(route('admin.permissions.destroy', $builtIn))->assertSessionHas('error');
    test()->actingAs($admin)->put(route('admin.permissions.update', $added), ['name' => 'reports.download'])->assertSessionHasNoErrors();

    expect($builtIn->fresh()->name)->toBe('reports.time-spent')
        ->and($added->fresh()->name)->toBe('reports.download');
});

it('leaves out of Admin\'s sidebar the pages switched off for Admin', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
        ->assertSee(route('admin.reports.sourcing-daily'))
        ->assertSee(route('admin.reports.time-spent'));

    switchOnFor('Admin', array_diff(permissionsOf('Admin'), ['reports.performance', 'reports.time-spent', 'logs.view']));

    test()->actingAs($admin->fresh())->get(route('admin.dashboard'))->assertOk()
        ->assertDontSee(route('admin.reports.sourcing-daily'))
        ->assertDontSee(route('admin.reports.time-spent'))
        ->assertDontSee(route('admin.logs.index'))
        ->assertSee(route('admin.attendance.index'))
        ->assertSee(route('admin.backups.index'));
});
