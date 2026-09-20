<?php

use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\BusinessRoleSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

/**
 * The accounts seeded as General Managers: the two that used to hold the
 * "Manager" role, and the two added with the approval chain.
 */
const GENERAL_MANAGER_LOGINS = ['manager@rfqms.test', 'manager2@rfqms.test', 'general.manager@rfqms.test', 'general.manager2@rfqms.test'];

it('seeds no Manager role, and General Manager users — the old Manager accounts among them', function () {
    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class, UserSeeder::class]);

    expect(Role::where('name', 'Manager')->exists())->toBeFalse()
        ->and(User::role('General Manager')->count())->toBe(4);

    foreach (GENERAL_MANAGER_LOGINS as $email) {
        expect(User::where('email', $email)->firstOrFail()->getRoleNames()->all())->toBe(['General Manager']);
    }
});

it('gives the General Manager role its approval permissions, and no oversight-only ones', function () {
    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class, UserSeeder::class]);

    $permissions = Role::findByName('General Manager')->permissions->pluck('name')->sort()->values()->all();

    // What the Manager had on top — users, roles and permissions — isn't the General Manager's.
    expect($permissions)->toBe(['rfqs.edit', 'rfqs.view']);
});

it('folds a Manager role from an earlier seed into General Manager, keeping its accounts and their logins', function () {
    // A database seeded before: a Manager role with people in it — one of whom held another role too.
    $manager = Role::findOrCreate('Manager');
    $morgan = User::factory()->create(['email' => 'manager@rfqms.test'])->assignRole($manager);
    $both = User::factory()->create()->assignRole($manager, Role::findOrCreate('Sourcing'));
    $someoneElse = User::factory()->create()->assignRole(Role::findOrCreate('Sourcing'));

    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class]);

    expect(Role::where('name', 'Manager')->exists())->toBeFalse()
        ->and($morgan->refresh()->getRoleNames()->all())->toBe(['General Manager'])
        ->and($both->refresh()->getRoleNames()->sort()->values()->all())->toBe(['General Manager', 'Sourcing'])
        ->and($someoneElse->refresh()->getRoleNames()->all())->toBe(['Sourcing'])
        ->and(User::where('email', 'manager@rfqms.test')->count())->toBe(1);
});

it('can be seeded again without changing anything', function () {
    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class, UserSeeder::class]);
    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class, UserSeeder::class]);

    expect(Role::where('name', 'Manager')->exists())->toBeFalse()
        ->and(User::role('General Manager')->count())->toBe(4)
        ->and(User::whereIn('email', GENERAL_MANAGER_LOGINS)->count())->toBe(4);
});

it('lets a seeded General Manager account work the approvals, part by part', function () {
    test()->seed([RolePermissionSeeder::class, BusinessRoleSeeder::class, UserSeeder::class]);

    $ops = userWithRole('Senior Operations');
    $dataEntry = userWithRole('Data Entry');
    $head = userWithRole('Head of Business Development');
    $assistant = userWithRole('GM Assistant');
    $sourcing = userWithRole('Sourcing');

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs']), [1 => $sourcing, 2 => $sourcing]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry);
        $rfq->refresh()->approveSeniorOpsPart($part, $ops);
        $rfq->refresh()->approveHeadOfBdPart($part, $head);
    }
    $rfq->refresh()->recordGmAssistantPart(1, $assistant, 'Acme Ltd', null);

    // The account that used to be the Manager, signed in.
    $morgan = User::where('email', 'manager@rfqms.test')->firstOrFail();

    test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ1001-P1 of P2</td>', false)
        ->assertDontSee('RFQ1001-P2 of P2')
        ->assertSee('<span class="nav-link-count" title="1 awaiting your approval">1</span>', false)
        ->assertSee(route('admin.rfqs.approve-gm-part', $rfq));

    test()->actingAs($morgan)->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 1])
        ->assertSessionHas('status', 'Approved RFQ1001-P1 of P2 — waiting on the rest of the parts.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->gm_approved_by)->toBe($morgan->id);
});
