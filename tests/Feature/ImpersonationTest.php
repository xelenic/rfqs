<?php

use App\Impersonation;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Log;
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

it('lets an Admin sign in as someone else, says so at the top, and takes them back', function () {
    Log::spy();
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');

    test()->actingAs($admin)->post(route('admin.users.impersonate', $riley))
        ->assertRedirect(route('admin.dashboard'))
        ->assertSessionHas(Impersonation::SESSION_KEY, $admin->id)
        ->assertSessionHas('status', "You're now signed in as {$riley->name}. Use \"Back to your account\" at the top when you're done.");

    $this->assertAuthenticatedAs($riley);

    // The panel as they see it, with the way back.
    test()->get(route('admin.dashboard'))->assertOk()
        ->assertViewIs('admin.dashboard.sourcing')
        ->assertSee('class="impersonation-bar"', false)
        ->assertSee("You're signed in as <strong>".e($riley->name).'</strong>', false)
        ->assertSee('action="'.route('admin.impersonation.leave').'"', false)
        ->assertSee('Back to your account ('.e($admin->name).')', false);

    test()->post(route('admin.impersonation.leave'))
        ->assertRedirect(route('admin.users.show', $riley))
        ->assertSessionMissing(Impersonation::SESSION_KEY)
        ->assertSessionHas('status', "Back in your own account — you were signed in as {$riley->name}.");

    $this->assertAuthenticatedAs($admin);
    test()->get(route('admin.dashboard'))->assertOk()->assertDontSee('class="impersonation-bar"', false);

    Log::shouldHaveReceived('info')->with('Admin signed in as another user.', Mockery::on(fn (array $context) => $context['admin_id'] === $admin->id && $context['user_id'] === $riley->id))->once();
    Log::shouldHaveReceived('info')->with('Admin returned to their own account.', Mockery::on(fn (array $context) => $context['admin_id'] === $admin->id && $context['user_id'] === $riley->id))->once();
});

it('offers "Log in as" to an Admin, on the Users list and a person\'s page — never for an Admin or themselves', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');
    $otherAdmin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.users.index'))->assertOk()
        ->assertSee('action="'.route('admin.users.impersonate', $riley).'"', false)
        ->assertDontSee('action="'.route('admin.users.impersonate', $otherAdmin).'"', false)
        ->assertDontSee('action="'.route('admin.users.impersonate', $admin).'"', false);

    test()->actingAs($admin)->get(route('admin.users.show', $riley))->assertOk()
        ->assertSee('action="'.route('admin.users.impersonate', $riley).'"', false)
        ->assertSee('Log in as');

    // HR Executive sees the Users pages, but signs in as nobody.
    $hr = User::factory()->create()->assignRole('HR Executive');
    test()->actingAs($hr)->get(route('admin.users.index'))->assertOk()
        ->assertDontSee(route('admin.users.impersonate', $riley));
});

it('keeps signing in as someone else to an Admin — and not as an Admin, themselves, or from someone else\'s account', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');

    foreach ([userWithRole('Senior Operations'), User::factory()->create()->assignRole('HR Executive')] as $notAdmin) {
        test()->actingAs($notAdmin)->post(route('admin.users.impersonate', $riley))->assertForbidden();
    }

    test()->actingAs($admin)->post(route('admin.users.impersonate', userWithRole('Admin')))->assertForbidden();
    test()->actingAs($admin)->post(route('admin.users.impersonate', $admin))->assertForbidden();
    $this->assertAuthenticatedAs($admin);

    // In Riley's account, another can't be started.
    test()->actingAs($admin)->post(route('admin.users.impersonate', $riley));
    test()->post(route('admin.users.impersonate', userWithRole('Data Entry')))->assertForbidden();
    $this->assertAuthenticatedAs($riley);
});

it('has nothing to go back to when nobody\'s signed in as anyone else', function () {
    test()->actingAs(userWithRole('Sourcing'))->post(route('admin.impersonation.leave'))->assertForbidden();
});

it('signs out altogether if the Admin to go back to is no longer an Admin', function () {
    $admin = userWithRole('Admin');
    $riley = userWithRole('Sourcing');

    test()->actingAs($admin)->post(route('admin.users.impersonate', $riley));
    $admin->syncRoles(['Sourcing']);

    test()->post(route('admin.impersonation.leave'))->assertRedirect(route('login'));
    $this->assertGuest();
});

it('ends it with signing out', function () {
    test()->actingAs(userWithRole('Admin'))->post(route('admin.users.impersonate', userWithRole('Sourcing')));

    test()->post(route('logout'))->assertRedirect(route('login'));

    $this->assertGuest();
    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
});
