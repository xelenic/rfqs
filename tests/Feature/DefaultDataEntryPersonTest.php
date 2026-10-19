<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['users.view', 'users.create', 'users.edit', 'users.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Executive'] as $role) {
        Role::findOrCreate($role);
    }

    Role::findByName('Admin')->givePermissionTo(Permission::all());
    Role::findByName('HR Executive')->givePermissionTo(['users.view', 'users.create', 'users.edit']);
});

it('lets an Admin set a Sourcing member\'s Data Entry person on their page', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');

    test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
        ->assertSee('id="user-default-data-entry"', false)
        ->assertSee('action="'.route('admin.users.default-data-entry', $riley).'"', false)
        ->assertSee('<option value="'.$morgan->id.'" >'.e($morgan->name).'</option>', false);

    test()->actingAs($admin)->patch(route('admin.users.default-data-entry', $riley), ['data_entry_user_id' => $morgan->id])
        ->assertRedirect(route('admin.users.show', $riley))
        ->assertSessionHas('status', "{$riley->name}'s parts go to {$morgan->name} in Data Entry to begin with.");

    expect($riley->refresh()->default_data_entry_user_id)->toBe($morgan->id);

    test()->actingAs($admin)->get(route('admin.users.show', $riley))
        ->assertSee('<option value="'.$morgan->id.'" selected>'.e($morgan->name).'</option>', false);
    test()->actingAs($admin)->get(route('admin.users.show', $morgan))
        ->assertSeeInOrder(['Usual Data Entry person for', e($riley->name)], false);

    // Back to anyone.
    test()->actingAs($admin)->patch(route('admin.users.default-data-entry', $riley), ['data_entry_user_id' => ''])
        ->assertSessionHas('status', "{$riley->name}'s parts go to anyone in Data Entry to begin with.");

    expect($riley->refresh()->default_data_entry_user_id)->toBeNull();
});

it('takes only someone in Data Entry, and only for someone in Sourcing', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');

    test()->actingAs($admin)->from(route('admin.users.show', $riley))
        ->patch(route('admin.users.default-data-entry', $riley), ['data_entry_user_id' => userWithRole('Senior Operations')->id])
        ->assertSessionHasErrorsIn('default_data_entry', ['data_entry_user_id' => 'Pick someone in Data Entry from the list.']);

    test()->actingAs($admin)->patch(route('admin.users.default-data-entry', userWithRole('Data Entry')), ['data_entry_user_id' => userWithRole('Data Entry')->id])
        ->assertStatus(422);

    expect($riley->refresh()->default_data_entry_user_id)->toBeNull();
});

it('leaves setting it to an Admin — HR Executive sees who it is', function () {
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $riley->forceFill(['default_data_entry_user_id' => $morgan->id])->save();
    $hr = User::factory()->create()->assignRole('HR Executive');

    test()->actingAs($hr)->patch(route('admin.users.default-data-entry', $riley), ['data_entry_user_id' => null])->assertForbidden();

    test()->actingAs($hr)->get(route('admin.users.show', $riley))->assertOk()
        ->assertSee('id="user-default-data-entry"', false)
        ->assertDontSee('action="'.route('admin.users.default-data-entry', $riley).'"', false)
        ->assertSee($morgan->name);

    expect($riley->refresh()->default_data_entry_user_id)->toBe($morgan->id);
});

it('picks the member\'s Data Entry person to begin with — whoever had the part last first', function () {
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $sam = userWithRole('Data Entry');
    $riley->forceFill(['default_data_entry_user_id' => $morgan->id])->save();

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9601']), [1 => $riley]);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-de-assignee="'.$morgan->id.'"', false);

    // Given to Sam, and sent back: Sam had it last.
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'line_items' => 5, 'comment' => 'Done', 'data_entry_user_id' => $sam->id]);
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $sam);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('data-de-assignee="'.$sam->id.'"', false);
});

it('forgets the Data Entry person once their account is gone', function () {
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $riley->forceFill(['default_data_entry_user_id' => $morgan->id])->save();

    $morgan->delete();

    expect($riley->refresh()->default_data_entry_user_id)->toBeNull();
});
