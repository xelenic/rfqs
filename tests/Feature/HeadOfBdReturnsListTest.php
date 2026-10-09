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

function headOfBdReturnsUrl(array $query = []): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns'] + $query);
}

/**
 * RFQ2001, all the way through to the General Manager's review.
 *
 * @return array{head: User, gm: User, rfq: Rfq}
 */
function atGmReview(): array
{
    $head = userWithRole('Head of Business Development');
    $gm = userWithRole('General Manager');

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'subject' => 'Server room chillers']), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->completeSeniorOpsReview(userWithRole('Senior Operations'));
    $rfq->refresh()->approveByHeadOfBd($head);
    $rfq->refresh()->recordGmAssistantDetails(userWithRole('GM Assistant'));
    expect($rfq->refresh()->stage)->toBe('gm_review');

    return ['head' => $head, 'gm' => $gm, 'rfq' => $rfq->refresh()];
}

it('shows the empty state until the General Manager sends something back', function () {
    ['head' => $head] = atGmReview();

    test()->actingAs($head)->get(headOfBdReturnsUrl())->assertOk()
        ->assertSee('<title>Returns', false)
        ->assertDontSee('RFQ2001')
        ->assertSee('Nothing\'s been sent back to the Head of Business Development.', false);
});

it('lists what the General Manager sent back to their review, with who and why, and Approve and Reject right there', function () {
    ['head' => $head, 'gm' => $gm, 'rfq' => $rfq] = atGmReview();

    test()->actingAs($gm)
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'head_of_bd_review', 'reason' => 'Margin is too thin'])
        ->assertSessionHasNoErrors();

    expect(Rfq::headOfBdReturnsCount())->toBe(1);

    test()->actingAs($head)->get(headOfBdReturnsUrl())->assertOk()
        ->assertSee('RFQ2001')
        ->assertSee('Sent back by General Manager ('.$gm->name.'): Margin is too thin')
        ->assertSee('Returned At')
        ->assertSee('action="'.route('admin.rfqs.approve-head-of-bd-part', $rfq).'"', false)
        ->assertSee('data-action="'.route('admin.rfqs.reject-head-of-bd', $rfq).'"', false)
        ->assertSee('id="rejectRfqModal"', false)
        ->assertSee('title="1 sent back by the General Manager"', false);
});

it('lists it only on Returns — not on their Review page, or in its count', function () {
    ['head' => $head, 'gm' => $gm, 'rfq' => $rfq] = atGmReview();

    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'head_of_bd_review', 'reason' => 'Margin is too thin']);

    // Something else that's reached them the usual way stays on Review.
    $fresh = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3001']), [1 => userWithRole('Sourcing')]);
    $fresh->refresh()->completeSourcingPart(1);
    $fresh->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $fresh->refresh()->finalizePart(1);
    $fresh->refresh()->completeSeniorOpsReview(userWithRole('Senior Operations'));

    expect(Rfq::headOfBdReviewCount())->toBe(1)
        ->and(Rfq::queueCounts()['head_of_bd'])->toBe(1);

    test()->actingAs($head)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<title>Review', false)
        ->assertSee('RFQ3001')
        ->assertDontSee('RFQ2001')
        ->assertSee('title="1 awaiting your review"', false);

    test()->actingAs($head)->get(headOfBdReturnsUrl())->assertOk()
        ->assertSee('RFQ2001')
        ->assertDontSee('RFQ3001');
});

it('moves on to GM Assistant when approved from Returns, and off the list', function () {
    ['head' => $head, 'gm' => $gm, 'rfq' => $rfq] = atGmReview();
    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'head_of_bd_review', 'reason' => 'Margin is too thin']);

    test()->actingAs($head)
        ->from(headOfBdReturnsUrl())
        ->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1])
        ->assertSessionHasNoErrors()
        ->assertRedirect(headOfBdReturnsUrl());

    expect($rfq->refresh()->stage)->toBe('gm_assistant')
        ->and(Rfq::headOfBdReturnsCount())->toBe(0);
    test()->actingAs($head)->get(headOfBdReturnsUrl())->assertOk()->assertDontSee('RFQ2001');
});

it('leaves out what the General Manager sends back to anyone else', function (string $target) {
    ['head' => $head, 'gm' => $gm, 'rfq' => $rfq] = atGmReview();

    test()->actingAs($gm)
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => $target, 'reason' => 'Not for the Head'])
        ->assertSessionHasNoErrors();

    expect(Rfq::headOfBdReturnsCount())->toBe(0);
    test()->actingAs($head)->get(headOfBdReturnsUrl())->assertOk()->assertDontSee('RFQ2001');
})->with(['senior_ops_review', 'gm_assistant', 'business_development']);

it('drops off once the Head sends it further back', function () {
    ['head' => $head, 'gm' => $gm, 'rfq' => $rfq] = atGmReview();

    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'head_of_bd_review', 'reason' => 'Margin is too thin']);
    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'senior_ops_review', 'reason' => 'Recheck the totals'])
        ->assertSessionHasNoErrors();

    expect(Rfq::headOfBdReturnsCount())->toBe(0)
        ->and(Rfq::seniorOpsReturnsCount())->toBe(1);
});

it('shows Admin the Head\'s Returns page, linked from the grouped sidebar', function () {
    ['gm' => $gm, 'rfq' => $rfq] = atGmReview();
    test()->actingAs($gm)->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'head_of_bd_review', 'reason' => 'Margin is too thin']);

    expect(Rfq::queueCounts()['head_of_bd_returns'])->toBe(1);

    test()->actingAs(userWithRole('Admin'))->get(headOfBdReturnsUrl(['role' => 'head-of-business-development']))->assertOk()
        ->assertSee('Returns · Head of Business Development')
        ->assertSee('RFQ2001')
        ->assertSee('Margin is too thin')
        ->assertSee('action="'.route('admin.rfqs.approve-head-of-bd-part', $rfq).'"', false)
        // Admin's "Rejected by" picker on the shared modal.
        ->assertSee('id="reject-acting-as"', false);
});

/**
 * RFQ4001, split two ways, both parts through to the General Manager's review.
 *
 * @return array{head: User, gm: User, rfq: Rfq}
 */
function splitAtGmReview(): array
{
    $head = userWithRole('Head of Business Development');
    $gm = userWithRole('General Manager');
    $sourcing = userWithRole('Sourcing');

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ4001']), [1 => $sourcing, 2 => $sourcing]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
        $rfq->refresh()->approveHeadOfBdPart($part, $head);
        $rfq->refresh()->recordGmAssistantPart($part, userWithRole('GM Assistant'));
    }
    expect($rfq->refresh()->stage)->toBe('gm_review');

    return ['head' => $head, 'gm' => $gm, 'rfq' => $rfq->refresh()];
}

it('keeps an RFQ returned to the Head off every other role\'s review page, other parts and all, until they\'ve dealt with it', function () {
    ['head' => $head, 'gm' => $gm, 'rfq' => $rfq] = splitAtGmReview();

    // The General Manager sends part 1 back; part 2 was still waiting on them.
    test()->actingAs($gm)
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'head_of_bd_review', 'reason' => 'Check P1 margin'])
        ->assertSessionHasNoErrors();

    // Left at the Head's review — every part is past Senior Operations.
    expect($rfq->refresh()->stage)->toBe('head_of_bd_review')
        ->and(Rfq::headOfBdReturnsCount())->toBe(1)
        ->and(Rfq::seniorOpsReviewCount())->toBe(0)
        ->and(Rfq::headOfBdReviewCount())->toBe(0)
        ->and(Rfq::gmAssistantReviewCount())->toBe(0)
        ->and(Rfq::gmReviewCount())->toBe(0);

    foreach ([
        ['Senior Operations', ['status' => 'Pending', 'view' => 'review']],
        ['GM Assistant', ['status' => 'Pending']],
        ['General Manager', ['status' => 'Pending']],
    ] as [$role, $query]) {
        // (A table cell — the General Manager's own "Sent … back" message can
        // still be on the next page they load.)
        test()->actingAs(userWithRole($role))->get(route('admin.rfqs.index', $query))->assertOk()
            ->assertDontSee('>RFQ4001', false);
    }

    // Nor can the General Manager act on part 2 meanwhile.
    test()->actingAs($gm)->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 2])->assertStatus(422);

    // Only on the Head's Returns page — just the part sent back.
    test()->actingAs($head)->get(headOfBdReturnsUrl())->assertOk()
        ->assertSee('RFQ4001-P1 of P2')
        ->assertDontSee('RFQ4001-P2 of P2')
        ->assertSee('Check P1 margin');

    // Once the Head approves it, both parts are back where they belong.
    test()->actingAs($head)->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1])->assertSessionHasNoErrors();

    expect(Rfq::headOfBdReturnsCount())->toBe(0)
        ->and(Rfq::gmAssistantReviewCount())->toBe(1)
        ->and(Rfq::gmReviewCount())->toBe(1);

    test()->actingAs($gm)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('RFQ4001-P2 of P2');
});

it('releases an RFQ once the Head has dealt with the part sent back, even with another part still on its way', function () {
    $head = userWithRole('Head of Business Development');
    $gm = userWithRole('General Manager');
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');

    // P1 all the way to the General Manager; P2 still with Sourcing.
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ6001']), [1 => $sourcing, 2 => $sourcing]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, $ops);
    $rfq->refresh()->approveHeadOfBdPart(1, $head);
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'));

    test()->actingAs($gm)
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'head_of_bd_review', 'reason' => 'Check P1'])
        ->assertSessionHasNoErrors();
    expect($rfq->refresh()->isHeldOnAnotherReturnsPage())->toBeTrue();

    test()->actingAs($head)->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1])->assertSessionHasNoErrors();

    // Nothing of it left for the Head, so it's no longer held — P2 can carry
    // on to Senior Operations' review as normal.
    expect($rfq->refresh()->reject_target_stage)->toBeNull()
        ->and(Rfq::headOfBdReturnsCount())->toBe(0);

    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(2);

    expect(Rfq::seniorOpsReviewCount())->toBe(1);
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('RFQ6001-P2 of P2');
});
