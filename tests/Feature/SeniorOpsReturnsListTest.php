<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

function opsReturnsUrl(array $query = []): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns'] + $query);
}

/**
 * RFQ2001, through Sourcing, Data Entry and Senior Operations' review — at
 * the Head of Business Development's.
 *
 * @return array{ops: User, head: User, riley: User, rfq: Rfq}
 */
function atHeadOfBdReview(): array
{
    $ops = userWithRole('Senior Operations');
    $head = userWithRole('Head of Business Development');
    $riley = userWithRole('Sourcing');

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'subject' => 'Server room chillers']), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->completeSeniorOpsReview($ops);
    expect($rfq->refresh()->stage)->toBe('head_of_bd_review');

    return ['ops' => $ops, 'head' => $head, 'riley' => $riley, 'rfq' => $rfq->refresh()];
}

it('shows the empty state until something is sent back to Senior Operations', function () {
    atHeadOfBdReview();

    test()->actingAs(userWithRole('Senior Operations'))->get(opsReturnsUrl())->assertOk()
        ->assertSee('<title>Returns', false)
        ->assertDontSee('RFQ2001')
        ->assertSee('Nothing\'s been sent back to Senior Operations.', false);
});

it('lists an RFQ sent back to their assignment step with who, why, and Assign Sourcing — until they re-assign it', function () {
    ['ops' => $ops, 'head' => $head, 'riley' => $riley, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'operations', 'reason' => 'Split it by floor instead'])
        ->assertSessionHasNoErrors();

    test()->actingAs($ops)->get(opsReturnsUrl())->assertOk()
        ->assertSee('RFQ2001')
        ->assertSee('Sent back by Head of Business Development ('.$head->name.'): Split it by floor instead')
        ->assertSee('Assignment')
        ->assertSee('data-action="'.route('admin.rfqs.assign', $rfq).'"', false);

    expect(Rfq::seniorOpsReturnsCount())->toBe(1);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.assign', $rfq), ['assignments' => [1 => $riley->id]])
        ->assertSessionHasNoErrors();

    expect(Rfq::seniorOpsReturnsCount())->toBe(0);
    test()->actingAs($ops)->get(opsReturnsUrl())->assertOk()->assertDontSee('RFQ2001');
});

it('lists an RFQ sent back to their second review with a link to Review — until they approve it again', function () {
    ['ops' => $ops, 'head' => $head, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'senior_ops_review', 'reason' => 'Check the totals again'])
        ->assertSessionHasNoErrors();

    test()->actingAs($ops)->get(opsReturnsUrl())->assertOk()
        ->assertSee('RFQ2001')
        ->assertSee('Check the totals again')
        ->assertSee('2nd review')
        ->assertSee('href="'.e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review'])).'"', false);

    $rfq->refresh()->completeSeniorOpsReview($ops);

    expect(Rfq::seniorOpsReturnsCount())->toBe(0);
    test()->actingAs($ops)->get(opsReturnsUrl())->assertOk()->assertDontSee('RFQ2001');
});

it('leaves out what\'s sent back to anyone else', function (string $target) {
    ['ops' => $ops, 'head' => $head, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => $target, 'reason' => 'Not for Operations'])
        ->assertSessionHasNoErrors();

    expect(Rfq::seniorOpsReturnsCount())->toBe(0);
    test()->actingAs($ops)->get(opsReturnsUrl())->assertOk()->assertDontSee('RFQ2001');
})->with(['business_development', 'sourcing', 'data_entry']);

it('keeps the Unassigned page as it was, with its own tabs', function () {
    test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<title>Unassigned RFQs', false)
        ->assertSee('id="rfq-ops-unassigned"', false)
        ->assertDontSee('Nothing\'s been sent back to Senior Operations.', false);
});

it('counts Senior Operations\' Returns badge, on their own sidebar and Admin\'s grouped one', function () {
    ['ops' => $ops, 'head' => $head, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'operations', 'reason' => 'Split it by floor instead']);

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('title="1 sent back by a reviewer"', false);

    expect(Rfq::queueCounts()['ops_returns'])->toBe(1);

    test()->actingAs(userWithRole('Admin'))->get(opsReturnsUrl(['role' => 'senior-operations']))->assertOk()
        ->assertSee('RFQ2001')
        ->assertSee('Split it by floor instead');
});
