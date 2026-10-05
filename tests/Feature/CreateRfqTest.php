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
        'number_of_items' => 5,
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
    $response = test()->actingAs(userWithRole('Admin'))
        ->get(route('admin.rfqs.index'))
        ->assertOk();

    $response->assertDontSee('id="create-status"', false)
        ->assertSee('id="edit-status"', false);
});

it('gives Business Development no status to choose even when editing — always Pending, see RfqController::update()', function () {
    test()->actingAs(userWhoCanCreateRfqs())
        ->get(route('admin.rfqs.index'))
        ->assertOk()
        ->assertDontSee('id="create-status"', false)
        ->assertDontSee('id="edit-status"', false);
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

// ---- Number of items ------------------------------------------------------------------

it('asks for the number of items when creating an RFQ, and when editing one', function () {
    test()->actingAs(userWithRole('Admin'))
        ->get(route('admin.rfqs.index'))
        ->assertOk()
        ->assertSee('id="create-number_of_items"', false)
        ->assertSee('id="edit-number_of_items"', false)
        ->assertSee('Number of items');
});

it('keeps the number of items given for a new RFQ', function () {
    test()->actingAs(userWhoCanCreateRfqs())
        ->post(route('admin.rfqs.store'), newRfqPayload(['number_of_items' => 12]))
        ->assertSessionHasNoErrors();

    expect(Rfq::query()->sole()->number_of_items)->toBe(12);
});

it('needs a whole number of items, at least one', function (mixed $items) {
    test()->actingAs(userWhoCanCreateRfqs())
        ->post(route('admin.rfqs.store'), newRfqPayload(['number_of_items' => $items]))
        ->assertSessionHasErrorsIn('create', ['number_of_items']);

    expect(Rfq::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'none' => [0],
    'fewer than none' => [-3],
    'not a number' => ['a few'],
    'part of one' => [2.5],
]);

it('changes the number of items on an edit, and shows it on the RFQ\'s page', function () {
    $rfq = Rfq::factory()->create(['number_of_items' => 4]);
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('data-number-of-items="4"', false)
        ->assertSee('Number of items');

    test()->actingAs($admin)->put(route('admin.rfqs.update', $rfq), [
        'wc_number' => $rfq->wc_number,
        'rfq_number' => $rfq->rfq_number,
        'priority_level' => 'Medium',
        'number_of_items' => 9,
        'status' => 'Pending',
        'subject' => $rfq->subject,
    ])->assertSessionHasNoErrors();

    expect($rfq->refresh()->number_of_items)->toBe(9);

    test()->actingAs($admin)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSeeInOrder(['<dt>Number of items</dt>', '<dd>9</dd>'], false);
});
