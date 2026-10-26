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

function opsRejectedUrl(array $query = []): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected'] + $query);
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

it('lists an RFQ rejected back to their assignment step on Rejected, with who, why, and Assign Sourcing — until they re-assign it', function () {
    ['ops' => $ops, 'head' => $head, 'riley' => $riley, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'operations', 'reason' => 'Split it by floor instead'])
        ->assertSessionHasNoErrors();

    test()->actingAs($ops)->get(opsRejectedUrl())->assertOk()
        ->assertSee('<title>Rejected', false)
        ->assertSee('RFQ2001')
        ->assertSee('Rejected by Head of Business Development ('.$head->name.'): Split it by floor instead')
        ->assertSee('Assignment')
        ->assertSee('data-action="'.route('admin.rfqs.assign', $rfq).'"', false);

    // A reviewer's rejection, so not on Returns.
    expect(Rfq::seniorOpsReturnsCount(rejected: true))->toBe(1)
        ->and(Rfq::seniorOpsReturnsCount())->toBe(0)
        ->and(pageMain(test()->actingAs($ops)->get(opsReturnsUrl())->assertOk()))->not->toContain('RFQ2001');

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.assign', $rfq), ['assignments' => [1 => $riley->id]])
        ->assertSessionHasNoErrors();

    expect(Rfq::seniorOpsReturnsCount(rejected: true))->toBe(0)
        ->and(pageMain(test()->actingAs($ops)->get(opsRejectedUrl())->assertOk()))->not->toContain('RFQ2001');
});

it('lists an RFQ rejected back to their second review on Rejected, with a link to Review — until they approve it again', function () {
    ['ops' => $ops, 'head' => $head, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'senior_ops_review', 'reason' => 'Check the totals again'])
        ->assertSessionHasNoErrors();

    test()->actingAs($ops)->get(opsRejectedUrl())->assertOk()
        ->assertSee('RFQ2001')
        ->assertSee('Check the totals again')
        ->assertSee('2nd review')
        ->assertSee('href="'.e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review'])).'"', false);

    $rfq->refresh()->completeSeniorOpsReview($ops);

    expect(Rfq::seniorOpsReturnsCount(rejected: true))->toBe(0)
        ->and(pageMain(test()->actingAs($ops)->get(opsRejectedUrl())->assertOk()))->not->toContain('RFQ2001');
});

it('leaves out what\'s sent back to anyone else', function (string $target) {
    ['ops' => $ops, 'head' => $head, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => $target, 'reason' => 'Not for Operations'])
        ->assertSessionHasNoErrors();

    expect(Rfq::seniorOpsReturnsCount())->toBe(0)->and(Rfq::seniorOpsReturnsCount(rejected: true))->toBe(0);
    test()->actingAs($ops)->get(opsReturnsUrl())->assertOk()->assertDontSee('RFQ2001');
    test()->actingAs($ops)->get(opsRejectedUrl())->assertOk()->assertDontSee('RFQ2001');
})->with(['business_development', 'sourcing', 'data_entry']);

it('keeps the Unassigned page as it was, with its own tabs', function () {
    test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<title>Unassigned RFQs', false)
        ->assertSee('id="rfq-ops-unassigned"', false)
        ->assertDontSee('Nothing\'s been sent back to Senior Operations.', false);
});

it('counts Senior Operations\' Rejected badge, on their own sidebar and Admin\'s grouped one', function () {
    ['ops' => $ops, 'head' => $head, 'rfq' => $rfq] = atHeadOfBdReview();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'operations', 'reason' => 'Split it by floor instead']);

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('title="1 rejected back by a reviewer"', false);

    expect(Rfq::queueCounts())->ops_rejected->toBe(1)->ops_returns->toBe(0);

    test()->actingAs(userWithRole('Admin'))->get(opsRejectedUrl(['role' => 'senior-operations']))->assertOk()
        ->assertSee('RFQ2001')
        ->assertSee('Split it by floor instead');
});

it('keeps an RFQ rejected back to their assignment step off the Unassigned page and its count — it\'s on Rejected', function () {
    ['ops' => $ops, 'head' => $head, 'riley' => $riley, 'rfq' => $rfq] = atHeadOfBdReview();
    Rfq::factory()->create(['rfq_number' => 'RFQ2002']);

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'operations', 'reason' => 'Split it by floor instead'])
        ->assertSessionHasNoErrors();

    $unassigned = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('title="1 not yet assigned to Sourcing"', false)
        ->assertSee('title="1 rejected back by a reviewer"', false);
    expect($unassigned->viewData('rfqs')->pluck('rfq_number')->all())->toBe(['RFQ2002'])
        ->and(Rfq::queueCounts())->unassigned->toBe(1)->ops_rejected->toBe(1)
        ->and(test()->actingAs($ops)->get(opsRejectedUrl())->viewData('rfqs')->pluck('rfq_number')->all())->toBe(['RFQ2001']);

    // Admin's grouped sidebar, and their view of the Unassigned page, agree.
    expect(test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'senior-operations']))
        ->viewData('rfqs')->pluck('rfq_number')->all())->toBe(['RFQ2002']);

    // Assigned again, it's on neither: with Sourcing.
    test()->actingAs($ops)->patch(route('admin.rfqs.assign', $rfq), ['assignments' => [1 => $riley->id]])->assertSessionHasNoErrors();

    expect(test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending']))->viewData('rfqs')->pluck('rfq_number')->all())->toBe(['RFQ2002'])
        ->and(Rfq::queueCounts())->unassigned->toBe(1)->ops_rejected->toBe(0);
});

it('keeps a part an Admin sends back to Senior Operations on Returns, not Unassigned', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2003']), [1 => userWithRole('Sourcing')]);

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Wrong supplier group'])
        ->assertSessionHasNoErrors();

    // Sent back by a co-worker, not rejected by a reviewer: Returns, not Rejected.
    expect(test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending']))->viewData('rfqs')->pluck('rfq_number')->all())->toBe([])
        ->and(test()->actingAs($ops)->get(opsReturnsUrl())->viewData('rfqs')->pluck('rfq_number')->all())->toBe(['RFQ2003'])
        ->and(test()->actingAs($ops)->get(opsRejectedUrl())->viewData('rfqs')->pluck('rfq_number')->all())->toBe([])
        ->and(Rfq::queueCounts())->ops_returns->toBe(1)->ops_rejected->toBe(0);
});
