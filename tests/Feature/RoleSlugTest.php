<?php

use App\Models\Rfq;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Permissions;
use Database\Seeders\BusinessRoleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['roles.edit', 'users.view', 'users.create', 'users.edit'] as $permission) {
        Permission::findOrCreate($permission);
    }

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive', 'Operation Assistant Trainee'] as $role) {
        Role::findOrCreate($role);
    }

    Role::findOrCreate('Admin')->givePermissionTo(['roles.edit', 'users.view', 'users.create', 'users.edit']);
});

/**
 * Renames the role called $from to $to on the Roles page, as an Admin does —
 * its description and permissions left as they are.
 */
function renameRoleOnRolesPage(string $from, string $to): Role
{
    $role = Role::query()->where('name', $from)->firstOrFail();

    test()->actingAs(userWithRole('Admin'))->put(route('admin.roles.update', $role), [
        'name' => $to,
        'description' => $role->description,
        'is_active' => '1',
        'permissions' => $role->permissions->pluck('name')->all(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.roles.index'));

    return $role->refresh();
}

it('gives every role a slug from the name it was made with, shown on the Roles page', function () {
    expect(Role::query()->pluck('slug', 'name')->all())->toMatchArray([
        'Admin' => 'admin',
        'HBDs Operations' => 'hbds-operations',
        'GMs Operations' => 'gms-operations',
        'Head of Business Development' => 'head-of-business-development',
        'Operation Assistant Trainee' => 'operation-assistant-trainee',
    ]);

    Permission::findOrCreate('roles.view');
    Role::findOrCreate('Admin')->givePermissionTo('roles.view');
    $ops = Role::findBySlug('hbds-operations');

    test()->actingAs(userWithRole('Admin'))->get(route('admin.roles.index'))->assertOk()
        ->assertSeeInOrder(['HBDs Operations', '<code', 'hbds-operations</code>'], false);
    test()->actingAs(userWithRole('Admin'))->get(route('admin.roles.edit', $ops))->assertOk()
        ->assertSee('Key <code>hbds-operations</code>', false);
});

it('keeps a renamed role\'s people and everything it gives them, by its slug', function () {
    $ops = userWithRole('HBDs Operations');
    $sourcing = userWithRole('Sourcing');
    $admin = userWithRole('Admin');

    $renamed = renameRoleOnRolesPage('HBDs Operations', 'Senior Ops');
    renameRoleOnRolesPage('Sourcing', 'Purchasing');
    renameRoleOnRolesPage('Admin', 'Super Admin');
    [$ops, $sourcing, $admin] = [$ops->fresh(), $sourcing->fresh(), $admin->fresh()];

    expect($renamed)->toMatchArray(['name' => 'Senior Ops', 'slug' => 'hbds-operations'])
        ->and($renamed->permissions->pluck('name')->all())->toEqualCanonicalizing(['rfqs.view', 'rfqs.edit', ...Permissions::defaultsFor('hbds-operations')])
        // By the name the code knows it by, or its slug.
        ->and($ops->hasRole('HBDs Operations'))->toBeTrue()
        ->and($ops->hasRole('hbds-operations'))->toBeTrue()
        ->and($ops->hasAnyRole(['Admin', 'HBDs Operations']))->toBeTrue()
        ->and($ops->hasRole('Senior Ops'))->toBeFalse()
        ->and($ops->canViewPerformanceReports())->toBeTrue()
        ->and($ops->canListAllRfqs())->toBeTrue()
        ->and($admin->hasRole('Admin'))->toBeTrue()
        // Spatie's lookups by name go by slug too.
        ->and(User::role('Sourcing')->pluck('id')->all())->toBe([$sourcing->id])
        ->and(User::holdingRole('Sourcing')->pluck('id')->all())->toBe([$sourcing->id])
        ->and(User::roleMembers('HBDs Operations')->pluck('id')->all())->toBe([$ops->id]);

    test()->actingAs($ops)->get(route('admin.reports.sourcing-summary.sheet'))->assertOk();
    test()->actingAs($sourcing)->get(route('admin.dashboard'))->assertOk()->assertViewIs('admin.dashboard.sourcing');
    test()->actingAs($sourcing)->get(route('admin.reports.sourcing-summary.sheet'))->assertForbidden();
    test()->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertViewIs('admin.dashboard.admin');
});

it('counts a trainee as HBDs Operations whatever either role is called', function () {
    $trainee = userWithRole('Operation Assistant Trainee');

    renameRoleOnRolesPage('HBDs Operations', 'Senior Ops');
    renameRoleOnRolesPage('Operation Assistant Trainee', 'Ops Trainee');
    $trainee = $trainee->fresh();

    expect($trainee->hasRole('HBDs Operations'))->toBeTrue()
        ->and(User::holdingRole('HBDs Operations')->pluck('id')->all())->toBe([$trainee->id])
        ->and($trainee->canChangeRfqPriority())->toBeFalse();
});

it('keeps Settings → Messaging\'s rules for renamed roles, and tells people their new names', function () {
    Setting::putMessageRoles(['sourcing' => ['admin', 'hbds-operations']]);
    $riley = userWithRole('Sourcing');

    renameRoleOnRolesPage('HBDs Operations', 'Senior Ops');
    renameRoleOnRolesPage('Sourcing', 'Purchasing');
    $riley = $riley->fresh();

    expect($riley->canMessage(userWithRole('HBDs Operations')))->toBeTrue()
        ->and($riley->canMessage(userWithRole('General Manager')))->toBeFalse()
        ->and($riley->messagingLimitText())->toBe('As Purchasing, you can message Admin and Senior Ops — and answer anyone who writes to you.');
});

it('gives a role made with a renamed role\'s old name a slug of its own, and none of that role\'s access', function () {
    Notification::fake();
    $sourcing = userWithRole('Sourcing');
    renameRoleOnRolesPage('Sourcing', 'Purchasing');

    $newcomer = Role::create(['name' => 'Sourcing']);

    expect($newcomer->slug)->toBe('sourcing-2');

    // Ticked on Users by its name, it's the new role that's given.
    test()->actingAs(userWithRole('Admin'))->post(route('admin.users.store'), ['name' => 'Morgan Perera', 'email' => 'morgan@company.lk', 'roles' => ['Sourcing']])
        ->assertSessionHasNoErrors();
    $morgan = User::query()->where('email', 'morgan@company.lk')->sole();

    expect($morgan->roles->pluck('id')->all())->toBe([$newcomer->id])
        ->and($morgan->hasRole('Sourcing'))->toBeFalse()
        ->and(User::holdingRole('Sourcing')->pluck('id')->all())->toBe([$sourcing->id]);
});

it('runs the role seeder without making a renamed role again, or renaming it back', function () {
    renameRoleOnRolesPage('HBDs Operations', 'Senior Ops');
    $roles = Role::query()->count();

    test()->seed(BusinessRoleSeeder::class);

    expect(Role::query()->count())->toBe($roles)
        ->and(Role::findBySlug('hbds-operations')->name)->toBe('Senior Ops')
        ->and(Role::query()->where('name', 'HBDs Operations')->exists())->toBeFalse();
});

it('gives roles made before slugs theirs, by their names now and before, and keys the messaging rules by slug', function () {
    $migration = require database_path('migrations/2026_11_23_200106_add_slug_to_roles_table.php');
    DB::table('roles')->delete();
    $migration->down();

    foreach (['HBDs Operations', 'Senior Operations', 'GM Assistant', 'HR Manager', 'Sourcing', 'Purchasing'] as $name) {
        DB::table('roles')->insert(['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]);
    }

    Setting::put('message_roles', json_encode(['Sourcing' => ['GM Assistant', 'Admin']]));

    $migration->up();

    expect(DB::table('roles')->pluck('slug', 'name')->all())->toBe([
        'HBDs Operations' => 'hbds-operations',
        // An older copy of a role keeps its own name's slug.
        'Senior Operations' => 'senior-operations',
        'GM Assistant' => 'gms-operations',
        'HR Manager' => 'hr-executive',
        'Sourcing' => 'sourcing',
        'Purchasing' => 'purchasing',
    ])
        ->and(Setting::messageRoles())->toBe(['sourcing' => ['admin', 'gms-operations']]);
});
