<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

function unassignedPageUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Pending']);
}

it('gives Senior Operations a Get Details Again button on an Unassigned RFQ', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2001']);

    test()->actingAs($ops)->get(unassignedPageUrl())->assertOk()
        ->assertSee('Get Details Again')
        ->assertSee('data-action="'.route('admin.rfqs.request-details', $rfq).'"', false)
        ->assertSee('id="requestDetailsModal"', false);
});

it('sends the RFQ to Business Development\'s Returns page with the reason', function () {
    $ops = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'subject' => 'Server room chillers']);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Which floor is the server room on?'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Sent RFQ2001 back to Business Development for details.');

    expect($rfq->refresh())
        ->isReturnedToBusinessDevelopment()->toBeTrue()
        ->reject_from_stage->toBe('operations')
        ->rejected_by->toBe($ops->id)
        ->bd_return_count->toBe(1)
        ->and(Rfq::bdReturnsCount())->toBe(1)
        ->and($rfq->comments()->where('action', 'rejected')->value('body'))->toBe('Which floor is the server room on?');

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('RFQ2001')
        ->assertSee('Which floor is the server room on?')
        ->assertSee($ops->name);
});

it('marks it as with Business Development on the Unassigned queue, without the button, until they update it', function () {
    $ops = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2001']);

    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Need the site address']);

    test()->actingAs($ops)->get(unassignedPageUrl())->assertOk()
        ->assertSee('With Business Development: Need the site address')
        ->assertDontSee('data-action="'.route('admin.rfqs.request-details', $rfq).'"', false);

    test()->actingAs($bd)->put(route('admin.rfqs.update', $rfq), [
        'wc_number' => $rfq->wc_number,
        'rfq_number' => $rfq->rfq_number,
        'priority_level' => 'Medium',
        'number_of_items' => 5,
        'status' => 'Pending',
        'subject' => 'Now with the site address',
    ])->assertSessionHasNoErrors();

    test()->actingAs($ops)->get(unassignedPageUrl())->assertOk()
        ->assertDontSee('With Business Development:')
        ->assertSee('data-action="'.route('admin.rfqs.request-details', $rfq).'"', false);
});

it('refuses a reason left empty, changing nothing', function () {
    $rfq = Rfq::factory()->create();

    test()->actingAs(userWithRole('Senior Operations'))
        ->patch(route('admin.rfqs.request-details', $rfq), ['reason' => ''])
        ->assertSessionHasErrors('reason', null, 'requestDetails');

    expect($rfq->refresh()->isReturnedToBusinessDevelopment())->toBeFalse();
});

it('refuses anyone but Senior Operations and Admin', function (string $role) {
    $rfq = Rfq::factory()->create();

    test()->actingAs(userWithRole($role))
        ->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Need more'])
        ->assertForbidden();

    expect($rfq->refresh()->isReturnedToBusinessDevelopment())->toBeFalse();
})->with(['Business Development', 'Sourcing', 'Data Entry', 'General Manager']);

it('lets Admin do it on Senior Operations\' behalf', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Need more', 'acting_user_id' => $ops->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh())
        ->isReturnedToBusinessDevelopment()->toBeTrue()
        ->rejected_by->toBe($ops->id);
});

it('refuses an RFQ that\'s already fully assigned, or already with Business Development', function () {
    $ops = userWithRole('Senior Operations');
    $assigned = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.request-details', $assigned), ['reason' => 'Need more'])
        ->assertStatus(422);

    $rfq = Rfq::factory()->create();
    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'First time']);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Second time'])
        ->assertStatus(422);

    expect($rfq->refresh()->bd_return_count)->toBe(1);
});
