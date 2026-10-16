<?php

use App\Impersonation;
use App\Models\AttendanceSheet;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['users.view', 'users.create', 'users.edit', 'users.delete'] as $permission) {
        Permission::findOrCreate($permission);
    }

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin', 'HR Manager'] as $role) {
        Role::findOrCreate($role);
    }

    Role::findByName('Admin')->givePermissionTo(Permission::all());
    Role::findByName('HR Manager')->givePermissionTo(['users.view', 'users.create', 'users.edit']);
});

function deactivate(User $admin, User $user): TestResponse
{
    return test()->actingAs($admin)->from(route('admin.users.show', $user))->patch(route('admin.users.deactivate', $user));
}

function signIn(User $user): TestResponse
{
    return test()->post(route('login'), ['email' => $user->email, 'password' => 'password']);
}

it('lets an Admin deactivate someone — who can\'t sign in, and is signed out of what they had open', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    DB::table('sessions')->insert(['id' => 'riley-session', 'user_id' => $riley->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

    deactivate($admin, $riley)
        ->assertRedirect(route('admin.users.show', $riley))
        ->assertSessionHas('status', "Deactivated {$riley->name} — they can't sign in any more.");

    $riley->refresh();
    expect($riley->isDeactivated())->toBeTrue()
        ->and($riley->deactivated_by)->toBe($admin->id)
        ->and(DB::table('sessions')->where('user_id', $riley->id)->exists())->toBeFalse();

    // Whatever session they still had ends on their next request.
    test()->actingAs($riley)->get(route('admin.dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Your account has been deactivated — ask an Admin if you need it back.']);
    $this->assertGuest();

    // And the right password doesn't let them back in.
    signIn($riley)->assertSessionHasErrors(['email' => 'Your account has been deactivated — ask an Admin if you need it back.']);
    $this->assertGuest();
});

it('lets them back in once activated', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    deactivate($admin, $riley);

    test()->actingAs($admin)->patch(route('admin.users.activate', $riley))
        ->assertSessionHas('status', "Activated {$riley->name} — they can sign in again.");

    expect($riley->refresh()->isDeactivated())->toBeFalse();

    test()->post(route('logout'));
    signIn($riley)->assertRedirect(route('admin.dashboard'));
    $this->assertAuthenticatedAs($riley);
});

it('leaves them out of every list people are picked from — their name staying on what they did', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $rfq = Rfq::factory()->create(['created_by' => $morgan->id]);
    deactivate($admin, $riley);
    deactivate($admin, $morgan);

    expect(User::query()->holdingRole('Data Entry')->pluck('id'))->not->toContain($morgan->id)
        ->and(User::roleMembers('Sourcing')->pluck('id'))->not->toContain($riley->id)
        ->and(AttendanceSheet::people()->pluck('id'))->not->toContain($riley->id, $morgan->id)
        ->and($rfq->refresh()->creator->name)->toBe($morgan->name);

    // Not offered to Sourcing, and refused if sent anyway.
    $html = test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();
    expect($html)->not->toContain('value="'.$riley->id.'" data-name="'.e($riley->name).'"');

    $sourcing = userWithRole('Sourcing');
    $handedOver = splitAmong(Rfq::factory()->create(), [1 => $sourcing]);
    test()->actingAs($sourcing)->patch(route('admin.rfqs.complete-sourcing', $handedOver), ['part' => 1, 'comment' => 'Done', 'data_entry_user_id' => $morgan->id])
        ->assertSessionHas('error', 'Pick someone in Data Entry from the list, or leave it with anyone there.');
});

it('hands their Data Entry parts not yet started back to anyone, and stops parts going to them by default', function () {
    $admin = userWithRole('Admin');
    $morgan = userWithRole('Data Entry');
    $riley = userWithRole('Sourcing');
    $riley->forceFill(['default_data_entry_user_id' => $morgan->id])->save();

    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1, null, $morgan);

    deactivate($admin, $morgan)
        ->assertSessionHas('status', fn (string $status) => str_contains($status, '1 Data Entry part given to them went back to anyone in Data Entry.'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_assigned_to)->toBeNull()
        ->and($riley->refresh()->default_data_entry_user_id)->toBeNull();
});

it('says what they still hold, to hand on', function () {
    $riley = userWithRole('Sourcing');
    splitAmong(Rfq::factory()->create(), [1 => $riley]);
    splitAmong(Rfq::factory()->create(), [1 => $riley]);

    deactivate(userWithRole('Admin'), $riley)
        ->assertSessionHas('status', fn (string $status) => str_ends_with($status, 'They still hold 2 open parts — hand them on from their RFQs.'));
});

it('marks them on the Users list and their page — and filters them', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    $sam = userWithRole('Sourcing');
    deactivate($admin, $riley);

    test()->actingAs($admin)->get(route('admin.users.index', ['state' => 'deactivated']))->assertOk()
        ->assertSee($riley->name)
        ->assertDontSee($sam->email)
        ->assertSee('action="'.route('admin.users.activate', $riley).'"', false);

    test()->actingAs($admin)->get(route('admin.users.index', ['state' => 'active']))->assertOk()
        ->assertDontSee($riley->email)
        ->assertSee($sam->email);

    test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
        ->assertSee('This account is deactivated')
        ->assertDontSee('action="'.route('admin.users.impersonate', $riley).'"', false)
        ->assertDontSee('action="'.route('admin.users.password-link', $riley).'"', false);

    expect(Impersonation::canStart($admin, $riley->refresh()))->toBeFalse();
});

it('won\'t send a message to a deactivated account', function () {
    $riley = userWithRole('Sourcing');
    deactivate(userWithRole('Admin'), $riley);

    test()->actingAs(userWithRole('Senior Operations'))->postJson(route('admin.messages.store'), ['recipient_id' => $riley->id, 'body' => 'Hello'])
        ->assertStatus(422)
        ->assertJsonPath('message', "{$riley->name}'s account has been deactivated — they won't see it.");
});

it('keeps deactivating to an Admin — and never their own account', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');

    foreach ([User::factory()->create()->assignRole('HR Manager'), userWithRole('Senior Operations')] as $notAdmin) {
        deactivate($notAdmin, $riley)->assertForbidden();
    }

    deactivate($admin, $admin)->assertSessionHas('error', "You can't deactivate your own account.");

    expect($riley->refresh()->isDeactivated())->toBeFalse()
        ->and($admin->refresh()->isDeactivated())->toBeFalse();
});
