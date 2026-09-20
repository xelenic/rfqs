<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

function userWhoCanCreateRfqs(): User
{
    Permission::findOrCreate('rfqs.create');

    return userWithRole('Business Development')->givePermissionTo('rfqs.create');
}

function newRfqPayload(array $overrides = []): array
{
    return [
        'wc_number' => 'WC1234',
        'priority_level' => 'High',
        'subject' => 'Replace the lobby lighting',
        ...$overrides,
    ];
}

beforeEach(function () {
    // The list page looks up its Sourcing and Senior Operations members.
    Role::findOrCreate('Sourcing');
    Role::findOrCreate('Senior Operations');
});

it('has no status to choose when creating, but still does when editing', function () {
    $response = test()->actingAs(userWhoCanCreateRfqs())
        ->get(route('admin.rfqs.index'))
        ->assertOk();

    $response->assertDontSee('id="create-status"', false)
        ->assertSee('id="edit-status"', false);
});

it('creates every RFQ as Pending without being given a status', function () {
    test()->actingAs(userWhoCanCreateRfqs())
        ->post(route('admin.rfqs.store'), newRfqPayload())
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'RFQ created successfully.');

    expect(Rfq::sole()->status)->toBe('Pending');
});

it('ignores a status sent along with a new RFQ', function () {
    test()->actingAs(userWhoCanCreateRfqs())
        ->post(route('admin.rfqs.store'), newRfqPayload(['status' => 'Completed']))
        ->assertSessionHasNoErrors();

    expect(Rfq::sole()->status)->toBe('Pending');
});

it('still lets an existing RFQ\'s status be edited', function () {
    $rfq = Rfq::factory()->create();

    test()->actingAs(userWithRole('Business Development'))
        ->put(route('admin.rfqs.update', $rfq), newRfqPayload(['rfq_number' => $rfq->rfq_number, 'status' => 'Completed']))
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->status)->toBe('Completed');
});

it('sends someone creating from the Closed list to the Pending one, where the new RFQ is', function () {
    $user = userWhoCanCreateRfqs();

    test()->actingAs($user)
        ->post(route('admin.rfqs.store'), newRfqPayload(['redirect_status' => 'Completed']))
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']));

    // From the Pending list, or with none, they stay where they were.
    test()->actingAs($user)
        ->post(route('admin.rfqs.store'), newRfqPayload(['wc_number' => 'WC5678', 'redirect_status' => 'Pending']))
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']));

    test()->actingAs($user)
        ->post(route('admin.rfqs.store'), newRfqPayload(['wc_number' => 'WC9012']))
        ->assertRedirect(route('admin.rfqs.index'));
});
