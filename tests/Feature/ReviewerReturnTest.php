<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * $number, kept whole, through Senior Operations' review to the Head of
 * Business Development — and, with $toGm, on through GM Assistant to the
 * General Manager.
 */
function returnableRfq(string $number, bool $toGm = false): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'subject' => 'Chiller spares']), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->completeSeniorOpsReview(userWithRole('Senior Operations'));

    if ($toGm) {
        $rfq->refresh()->approveByHeadOfBd(userWithRole('Head of Business Development'));
        $rfq->refresh()->recordGmAssistantDetails(userWithRole('GM Assistant'));
    }

    return $rfq->refresh();
}

/**
 * $reviewer's Return (or, with $asReturn false, Reject) of $rfq to $target.
 */
function reviewerSendsBack(User $reviewer, Rfq $rfq, string $target, bool $asReturn = true, string $reason = 'Needs the supplier\'s datasheet'): TestResponse
{
    $route = $reviewer->hasRole('General Manager') ? 'admin.rfqs.reject-gm' : 'admin.rfqs.reject-head-of-bd';

    return test()->actingAs($reviewer)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route($route, $rfq), ['target_stage' => $target, 'reason' => $reason] + ($asReturn ? ['as_return' => '1'] : []));
}

/**
 * The RFQ numbers on $viewer's Pending page, ?view=$view — Data Entry's
 * table is its own list (bySourcingRfqs).
 *
 * @return array<int, string>
 */
function listedOn(User $viewer, ?string $view): array
{
    $response = test()->actingAs($viewer)->get(route('admin.rfqs.index', array_filter(['status' => 'Pending', 'view' => $view])))->assertOk();

    return ($response->viewData('bySourcingRfqs') ?? $response->viewData('rfqs'))->pluck('rfq_number')->all();
}

it('offers the Head of Business Development and the General Manager a Return beside their Reject', function () {
    $head = userWithRole('Head of Business Development');
    $headsRfq = returnableRfq('RFQ8001');

    test()->actingAs($head)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-as-return="1"', false)
        ->assertSee('<i class="bi bi-arrow-return-left"></i> Return', false);
    test()->actingAs($head)->get(route('admin.rfqs.show', $headsRfq))->assertOk()
        ->assertSee('data-as-return="1"', false)
        ->assertSee('name="as_return"', false);

    $gm = userWithRole('General Manager');
    returnableRfq('RFQ8002', toGm: true);

    test()->actingAs($gm)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-as-return="1"', false);

    // Not Senior Operations' second review: theirs is Reject alone.
    $ops = userWithRole('Senior Operations');
    $atReview = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $atReview->refresh()->completeSourcingPart(1);
    $atReview->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $atReview->refresh()->finalizePart(1);

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('js-reject-rfq', false)
        ->assertDontSee('data-as-return="1"', false);
});

it('puts what the Head Returns to Sourcing on Sourcing\'s Returns, not their Rejected page', function () {
    $head = userWithRole('Head of Business Development');
    $rfq = returnableRfq('RFQ8003');
    $sourcing = $rfq->assigneeForPart(1);

    reviewerSendsBack($head, $rfq, 'sourcing')
        ->assertSessionHas('status', 'Returned to Sourcing.');

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;

    expect($rfq->reject_is_return)->toBeTrue()
        ->and($pivot->isReturnedTo('sourcing'))->toBeTrue()
        ->and($pivot->isRejectedTo('sourcing'))->toBeFalse()
        ->and(listedOn($sourcing, 'returns'))->toBe(['RFQ8003'])
        ->and(listedOn($sourcing, 'rejected'))->toBe([])
        ->and(Rfq::sourcingRejectedCount($sourcing->id))->toBe(0);

    test()->actingAs($sourcing)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Returned by Head of Business Development: Needs the supplier&#039;s datasheet', false);

    // On the thread, a Return — not a rejection.
    $comment = $rfq->comments()->where('action', 'rejected')->sole();
    expect($comment->actionLabel())->toBe('Returned')
        ->and($comment->isReviewerReturn())->toBeTrue();
});

it('keeps a Reject on the Rejected page, as before', function () {
    $head = userWithRole('Head of Business Development');
    $rfq = returnableRfq('RFQ8004');
    $sourcing = $rfq->assigneeForPart(1);

    reviewerSendsBack($head, $rfq, 'sourcing', asReturn: false)
        ->assertSessionHas('status', 'Sent back to Sourcing.');

    expect($rfq->refresh()->reject_is_return)->toBeFalse()
        ->and(listedOn($sourcing, 'rejected'))->toBe(['RFQ8004'])
        ->and(listedOn($sourcing, 'returns'))->toBe([])
        ->and($rfq->comments()->where('action', 'rejected')->sole()->actionLabel())->toBe('Rejected');
});

it('gives Data Entry a Returns page for what the Head Returns to them — off Ready and off Rejected', function () {
    $head = userWithRole('Head of Business Development');
    $dataEntry = userWithRole('Data Entry');
    $rfq = returnableRfq('RFQ8005');

    reviewerSendsBack($head, $rfq, 'data_entry')->assertSessionHas('status', 'Returned to Data Entry.');

    expect(listedOn($dataEntry, 'returns'))->toBe(['RFQ8005'])
        ->and(listedOn($dataEntry, 'rejected'))->toBe([])
        ->and(Rfq::dataEntryReturnsCount($dataEntry->id))->toBe(1)
        ->and(Rfq::dataEntryRejectedCount($dataEntry->id))->toBe(0)
        ->and(Rfq::queueCounts())->toMatchArray(['data_entry' => 0, 'data_entry_returns' => 1, 'data_entry_rejected' => 0]);

    $ready = test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk();
    expect($ready->viewData('bySourcingRfqs')->pluck('rfq_number')->all())->toBe([]);

    test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('<title>Returns', false)
        ->assertSee('Returned by Head of Business Development: Needs the supplier&#039;s datasheet', false)
        ->assertSee('<span class="nav-link-count" title="1 returned by a reviewer">1</span>', false);
});

it('puts what the General Manager Returns on Senior Operations\', Business Development\'s, the Head\'s and GM Assistant\'s Returns', function (string $target, string $role, Closure $returnsCount, Closure $rejectedCount) {
    $gm = userWithRole('General Manager');
    $rfq = returnableRfq('RFQ8006', toGm: true);

    reviewerSendsBack($gm, $rfq, $target)->assertSessionHas('status', 'Returned to '.Rfq::stageLabel($target).'.');

    // Business Development sees only the RFQs they created.
    $viewer = userWithRole($role);
    $rfq->forceFill(['created_by' => $viewer->id])->save();

    expect($returnsCount())->toBe(1)
        ->and($rejectedCount())->toBe(0)
        ->and(listedOn($viewer, 'returns'))->toContain('RFQ8006')
        ->and(listedOn($viewer, 'rejected'))->not->toContain('RFQ8006');
})->with([
    'Senior Operations' => ['operations', 'Senior Operations', fn () => Rfq::seniorOpsReturnsCount(), fn () => Rfq::seniorOpsReturnsCount(rejected: true)],
    'Business Development' => ['business_development', 'Business Development', fn () => Rfq::bdReturnsCount(), fn () => Rfq::bdReturnsCount(rejected: true)],
    'Head of Business Development' => ['head_of_bd_review', 'Head of Business Development', fn () => Rfq::headOfBdReturnsCount(), fn () => Rfq::headOfBdReturnsCount(rejected: true)],
    'GM Assistant' => ['gm_assistant', 'GM Assistant', fn () => Rfq::gmAssistantReturnsCount(), fn () => Rfq::gmAssistantReturnsCount(rejected: true)],
]);

it('shows the Head\'s Returns page with who Returned it, and a link to it beside Rejected', function () {
    $gm = userWithRole('General Manager');
    $head = userWithRole('Head of Business Development');
    $rfq = returnableRfq('RFQ8007', toGm: true);

    reviewerSendsBack($gm, $rfq, 'head_of_bd_review', reason: 'Check the margin');

    test()->actingAs($head)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Returned by General Manager ('.$gm->name.'): Check the margin')
        ->assertSee('Returned At')
        ->assertSee('<span class="nav-link-count" title="1 returned to you">1</span>', false)
        ->assertSee('<span class="nav-link-label">Rejected</span>', false);
});

it('returns one part of a split on its own', function () {
    $head = userWithRole('Head of Business Development');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8008']), [1 => $riley, 2 => $sam]);

    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
    }

    test()->actingAs($head)->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'Wrong quote', 'as_return' => '1'])
        ->assertSessionHas('status', 'Returned RFQ8008-P1 of P2 to Sourcing.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->isReturnedTo('sourcing'))->toBeTrue()
        ->and(listedOn($riley, 'returns'))->toBe(['RFQ8008'])
        ->and(listedOn($sam, 'returns'))->toBe([]);
});

it('lets only the Head of Business Development and the General Manager Return', function () {
    $atReview = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $atReview->refresh()->completeSourcingPart(1);
    $atReview->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $atReview->refresh()->finalizePart(1);

    test()->actingAs(userWithRole('Senior Operations'))
        ->patch(route('admin.rfqs.reject-senior-ops', $atReview), ['target_stage' => 'sourcing', 'reason' => 'Redo', 'as_return' => '1'])
        ->assertStatus(422);

    expect($atReview->refresh()->rejected_at)->toBeNull();
});

it('keeps what\'s Returned to Senior Operations\' review on their Returns page alone — approved right there, then on to the Head', function () {
    $head = userWithRole('Head of Business Development');
    $ops = userWithRole('Senior Operations');
    $rfq = returnableRfq('RFQ8010');

    reviewerSendsBack($head, $rfq, 'senior_ops_review', reason: 'Recount the line items')
        ->assertSessionHas('status', 'Returned to Senior Operations (2nd review).');

    $review = fn () => test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->viewData('seniorOpsReviewRfqs')->pluck('rfq_number')->all();

    expect($review())->toBe([])
        ->and(Rfq::seniorOpsReviewCount())->toBe(0)
        ->and(Rfq::queueCounts()['ops_review'])->toBe(0)
        ->and(listedOn($ops, 'returns'))->toBe(['RFQ8010'])
        ->and(listedOn($ops, 'rejected'))->toBe([])
        ->and(Rfq::seniorOpsReturnsCount())->toBe(1);

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Returned by Head of Business Development')
        ->assertSee('data-action="'.route('admin.rfqs.approve-senior-ops-part', $rfq).'"', false)
        ->assertDontSee('Approve it again from the Review page');

    // Approved from there: on to the Head, off their Returns.
    test()->actingAs($ops)->from(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'actual_line_items' => [1 => 12]])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->stage)->toBe('head_of_bd_review')
        ->and($rfq->reject_target_stage)->toBeNull()
        ->and(Rfq::seniorOpsReturnsCount())->toBe(0)
        ->and($review())->toBe([]);
});

it('takes a split off Senior Operations\' Returns once the part sent back is approved, the next part on to Review', function () {
    $head = userWithRole('Head of Business Development');
    $ops = userWithRole('Senior Operations');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8011']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    $rfq->refresh()->approveSeniorOpsPart(1, $ops);

    // The Head Returns part 1 to Senior Operations' review; part 2 is still with Sourcing.
    test()->actingAs($head)->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'senior_ops_review', 'reason' => 'Recount', 'as_return' => '1'])
        ->assertSessionHasNoErrors();

    expect(listedOn($ops, 'returns'))->toBe(['RFQ8011'])
        ->and(Rfq::seniorOpsReviewCount())->toBe(0);

    $rfq->refresh()->approveSeniorOpsPart(1, $ops);

    expect($rfq->refresh()->reject_target_stage)->toBeNull()
        ->and(listedOn($ops, 'returns'))->toBe([]);

    // Part 2 comes to their review later: on Review, as usual.
    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(2);

    expect(Rfq::seniorOpsReviewCount())->toBe(1)
        ->and(listedOn($ops, 'returns'))->toBe([]);
});
