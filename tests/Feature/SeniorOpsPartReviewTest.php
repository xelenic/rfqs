<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // The list page looks up its Sourcing and Senior Operations members.
    Role::findOrCreate('Sourcing');
    Role::findOrCreate('Senior Operations');
    Role::findOrCreate('Data Entry');
    Role::findOrCreate('Head of Business Development');
});

function reviewUrl(array $query = []): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review'] + $query);
}

/**
 * A two-way split, RFQ1001, with part 1 through Sourcing and Data Entry and
 * part 2 still with Sourcing. Returns the RFQ and the people.
 *
 * @return array{0: Rfq, 1: User, 2: User, 3: User}
 */
function splitWithPartOneThrough(): array
{
    [$ops, $dataEntry, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Sourcing')];

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs', 'priority_level' => 'Medium']), [1 => $riley, 2 => $riley]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    return [$rfq->refresh(), $ops, $dataEntry, $riley];
}

it('lists a part as its own row as soon as it is through Sourcing and Data Entry, and only those', function () {
    [$rfq, $ops] = splitWithPartOneThrough();

    $html = test()->actingAs($ops)->get(reviewUrl())->assertOk()
        ->assertSee('Replace exit signs')
        ->assertSee('RFQ1001-P1 of P2')
        // Part 2 is still with Sourcing, so it isn't there to be looked at.
        ->assertDontSee('RFQ1001-P2 of P2')
        ->getContent();

    expect($html)->toContain('<input type="hidden" name="part" value="1">')
        ->toContain(route('admin.rfqs.approve-senior-ops-part', $rfq))
        ->not->toContain('<input type="hidden" name="part" value="2">')
        // Each part is approved on its own: nothing for the RFQ as a whole, no grouping.
        ->not->toContain(route('admin.rfqs.complete-senior-ops-review', $rfq))
        ->not->toContain('rfq-part-row');
});

it('lists each ready part of an RFQ separately, leaving out the ones with Sourcing, Data Entry or already approved', function () {
    [$ops, $dataEntry, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Sourcing')];

    // Part 1 approved, 2 and 3 ready, 4 with Data Entry, 5 in progress, 6 sent back.
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Six ways']), array_fill(1, 6, $riley));
    foreach ([1, 2, 3, 4, 6] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
    }
    foreach ([1, 2, 3] as $part) {
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry);
    }
    $rfq->refresh()->returnSourcingPart(6, 'Missing prices', $dataEntry);
    $rfq->refresh()->approveSeniorOpsPart(1, $ops);

    $html = test()->actingAs($ops)->get(reviewUrl())->assertOk()->getContent();

    expect(substr_count($html, 'name="part" value="'))->toBe(2)
        ->and($html)->toContain('RFQ1001-P2 of P6')
        ->toContain('RFQ1001-P3 of P6')
        ->not->toContain('RFQ1001-P1 of P6')
        ->not->toContain('RFQ1001-P4 of P6')
        ->not->toContain('RFQ1001-P5 of P6')
        ->not->toContain('RFQ1001-P6 of P6');
});

it('leaves an RFQ out until one of its parts has been through both', function () {
    [$ops, $dataEntry, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Sourcing')];

    // Part 1 is with Data Entry, part 2 still with Sourcing: nothing for Senior Operations yet.
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'Not there yet']), [1 => $riley, 2 => $riley]);
    $rfq->completeSourcingPart(1);

    test()->actingAs($ops)->get(reviewUrl())->assertOk()
        ->assertDontSee('Not there yet')
        ->assertSee('Nothing\'s waiting on your review right now.', false);

    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    test()->actingAs($ops)->get(reviewUrl())->assertSee('Not there yet');
});

it('approves a part on its own, and the RFQ moves on once every part has been approved', function () {
    [$rfq, $ops, $dataEntry] = splitWithPartOneThrough();

    test()->actingAs($ops)->from(reviewUrl())
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1])
        ->assertRedirect(reviewUrl())
        ->assertSessionHas('status', 'Approved RFQ1001-P1 of P2 — waiting on the rest of the parts.');

    $rfq->refresh();
    $part = $rfq->assigneeForPart(1)->pivot;

    expect($part->senior_ops_reviewed_at)->not->toBeNull()
        ->and($part->senior_ops_reviewed_by)->toBe($ops->id)
        ->and($rfq->assigneeForPart(2)->pivot->senior_ops_reviewed_at)->toBeNull()
        // The rest of the RFQ hasn't moved.
        ->and($rfq->stage)->toBeNull()
        ->and($rfq->senior_ops_reviewed_at)->toBeNull();

    // Nothing left for now, so it's off the page…
    test()->actingAs($ops)->get(reviewUrl())->assertDontSee('Replace exit signs');

    // …until the last part comes through Sourcing and Data Entry, and is the only row.
    $rfq->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, $dataEntry);

    test()->actingAs($ops)->get(reviewUrl())->assertOk()
        ->assertSee('RFQ1001-P2 of P2')
        ->assertDontSee('RFQ1001-P1 of P2')
        ->assertSee('<input type="hidden" name="part" value="2">', false);

    test()->actingAs($ops)->from(reviewUrl())
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 2])
        ->assertSessionHas('status', 'Approved — every part is through, escalated to Head of Business Development.');

    $rfq->refresh();

    expect($rfq->stage)->toBe('head_of_bd_review')
        ->and($rfq->senior_ops_reviewed_by)->toBe($ops->id)
        ->and($rfq->senior_ops_reviewed_at)->not->toBeNull();

    test()->actingAs($ops)->get(reviewUrl())->assertDontSee('Replace exit signs');
});

it('approves the parts still waiting when the whole RFQ is approved', function () {
    [$rfq, $ops, $dataEntry] = splitWithPartOneThrough();
    $rfq->approveSeniorOpsPart(1, $ops);
    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, $dataEntry);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.complete-senior-ops-review', $rfq->refresh()))
        ->assertSessionHas('status', 'Approved — escalated to Head of Business Development.');

    $rfq->refresh();

    expect($rfq->stage)->toBe('head_of_bd_review')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->senior_ops_reviewed_at !== null))->toBeTrue()
        // Part 1 keeps its own approval; part 2 gets this one.
        ->and($rfq->assigneeForPart(2)->pivot->senior_ops_reviewed_by)->toBe($ops->id);
});

it('lists an RFQ kept whole as one row, whose Approve moves it on', function () {
    [$ops, $dataEntry, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'One piece of work']), [1 => $riley]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    $html = test()->actingAs($ops)->get(reviewUrl())->assertOk()->assertSee('One piece of work')->getContent();

    // Its plain RFQ number, not a part's.
    expect($html)->toContain('<td class="text-nowrap">'.$rfq->rfq_number.'</td>')
        ->toContain('<input type="hidden" name="part" value="1">');

    test()->actingAs($ops)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1]);

    expect($rfq->refresh()->stage)->toBe('head_of_bd_review')
        ->and($rfq->assigneeForPart(1)->pivot->senior_ops_reviewed_at)->not->toBeNull();
});

it('still lists an RFQ that reached its review stage with no part of its own to show, as a row for the whole', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create(['stage' => 'senior_ops_review', 'subject' => 'Straight to review']);

    test()->actingAs($ops)->get(reviewUrl())
        ->assertSee('Straight to review')
        ->assertSee(route('admin.rfqs.complete-senior-ops-review', $rfq));
});

it('lets Admin approve a part from Senior Operations\' page', function () {
    [$rfq] = splitWithPartOneThrough();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(reviewUrl(['role' => 'senior-operations']))
        ->assertOk()
        ->assertSee('<input type="hidden" name="part" value="1">', false);

    test()->actingAs($admin)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1]);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_by)->toBe($admin->id);
});

it('refuses a part approval that isn\'t Senior Operations\' to give', function () {
    [$rfq, $ops, $dataEntry, $riley] = splitWithPartOneThrough();
    $approve = fn (User $user, array $data) => test()->actingAs($user)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), $data);

    // Only Senior Operations (and Admin).
    $approve($dataEntry, ['part' => 1])->assertForbidden();
    $approve($riley, ['part' => 1])->assertForbidden();

    // A part that's still with Sourcing, one that doesn't exist, none at all.
    $approve($ops, ['part' => 2])->assertStatus(422);
    $approve($ops, ['part' => 9])->assertNotFound();
    $approve($ops, [])->assertSessionHasErrors('part');

    // And a part already approved.
    $approve($ops, ['part' => 1])->assertSessionHas('status');
    $approve($ops, ['part' => 1])->assertStatus(422);

    expect($rfq->refresh()->assigneeForPart(2)->pivot->senior_ops_reviewed_at)->toBeNull();
});

it('leaves an approval alone that is repeated on the model', function () {
    [$rfq, $ops] = splitWithPartOneThrough();

    $rfq->approveSeniorOpsPart(1, $ops);
    $firstApproval = $rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_at;

    test()->travel(5)->minutes();
    $rfq->approveSeniorOpsPart(1, userWithRole('Senior Operations'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_at->equalTo($firstApproval))->toBeTrue()
        ->and($rfq->assigneeForPart(1)->pivot->senior_ops_reviewed_by)->toBe($ops->id);
});

it('won\'t escalate a split while a part is still unassigned', function () {
    [$ops, $dataEntry, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Sourcing')];

    // Three parts planned, only the first assigned — and through Sourcing and Data Entry.
    $rfq = Rfq::factory()->create();
    $rfq->planSplit(3);
    $rfq->assignSourcingParts([1 => $riley->id]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    $rfq->refresh()->approveSeniorOpsPart(1, $ops);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_at)->not->toBeNull()
        ->and($rfq->stage)->toBeNull();
});

it('counts an approval for the Review queue once a part is waiting, and not once it has moved on', function () {
    [$rfq, $ops, $dataEntry] = splitWithPartOneThrough();

    // Another that's still all with Sourcing, and one already past Senior Operations.
    splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $past = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $past->completeSourcingPart(1);
    $past->refresh()->completeDataEntryPart(1, $dataEntry);
    $past->refresh()->approveSeniorOpsPart(1, $ops);

    expect($past->refresh()->stage)->toBe('head_of_bd_review');

    // An RFQ from before the approval chain, closed with its parts unreviewed.
    $legacy = splitAmong(Rfq::factory()->create(['status' => 'Completed']), [1 => userWithRole('Sourcing')]);
    $legacy->completeSourcingPart(1);
    $legacy->refresh()->completeDataEntryPart(1, $dataEntry);

    expect(Rfq::queueCounts()['ops_review'])->toBe(1)
        ->and(Rfq::awaitingSeniorOpsReview()->pluck('id')->all())->toBe([$rfq->id]);

    $rfq->approveSeniorOpsPart(1, $ops);

    expect(Rfq::queueCounts()['ops_review'])->toBe(0);
});

it('counts each approval waiting — a row on the Review page — and shows it as a red badge on the sidebar\'s Review link', function () {
    [$ops, $dataEntry, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Sourcing')];

    // Three parts of one RFQ ready, one RFQ kept whole ready, and one at its review stage with no part to show.
    $split = splitAmong(Rfq::factory()->create(), [1 => $riley, 2 => $riley, 3 => $riley]);
    $whole = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    Rfq::factory()->create(['stage' => 'senior_ops_review']);
    foreach ([1, 2, 3] as $part) {
        $split->refresh()->completeSourcingPart($part);
        $split->refresh()->completeDataEntryPart($part, $dataEntry);
    }
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, $dataEntry);

    expect(Rfq::seniorOpsReviewCount())->toBe(5)
        ->and(Rfq::queueCounts()['ops_review'])->toBe(5);

    $badge = fn (int $count) => '<span class="nav-link-count" title="'.$count.' awaiting your approval">'.$count.'</span>';
    $sidebar = fn (string $url) => test()->actingAs($ops)->get($url)->assertOk()->getContent();

    // Five rows on the page, five on the badge — wherever Senior Operations is.
    $review = $sidebar(reviewUrl());
    expect(substr_count($review, 'data-confirm="Approve '))->toBe(5)
        ->and($review)->toContain('<span class="nav-link-label">Review</span>')
        ->toContain($badge(5))
        ->and($sidebar(route('admin.rfqs.index', ['status' => 'Pending'])))->toContain($badge(5));

    // One approved, one fewer.
    $split->refresh()->approveSeniorOpsPart(1, $ops);

    expect($sidebar(reviewUrl()))->toContain($badge(4));

    // Nothing waiting, no badge.
    foreach ([2, 3] as $part) {
        $split->refresh()->approveSeniorOpsPart($part, $ops);
    }
    $whole->refresh()->approveSeniorOpsPart(1, $ops);
    Rfq::where('stage', 'senior_ops_review')->update(['stage' => 'head_of_bd_review']);

    expect($sidebar(reviewUrl()))->toContain('<span class="nav-link-label">Review</span>')
        ->not->toContain('awaiting your approval')
        ->and(Rfq::seniorOpsReviewCount())->toBe(0);
});

it('leaves the Review badge to Senior Operations', function () {
    [$dataEntry, $riley] = [userWithRole('Data Entry'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertDontSee('awaiting your approval');
});

it('takes back a part\'s approval when it is sent back, and every part\'s when the Head of Business Development rejects', function () {
    [$rfq, $ops, $dataEntry, $riley] = splitWithPartOneThrough();
    $head = userWithRole('Head of Business Development');
    $rfq->approveSeniorOpsPart(1, $ops);

    // Sent back for rework, it has to come through Data Entry and review again.
    $rfq->refresh()->returnSourcingPart(1, 'Missing a quote', $dataEntry);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_at)->toBeNull();

    // A whole RFQ that reached the Head of Business Development, every part approved.
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);
    $rfq->refresh()->completeDataEntryPart(2, $dataEntry);
    $rfq->refresh()->completeSeniorOpsReview($ops);

    expect($rfq->refresh()->stage)->toBe('head_of_bd_review')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->senior_ops_reviewed_at !== null))->toBeTrue();

    $rfq->rejectToStage('senior_ops_review', 'Look again', $head);

    // Back on the Review page, each part up for approval again.
    expect($rfq->refresh()->stage)->toBe('senior_ops_review')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->senior_ops_reviewed_at === null))->toBeTrue();

    test()->actingAs($ops)->get(reviewUrl())
        ->assertSee('RFQ1001-P1 of P2')
        ->assertSee('RFQ1001-P2 of P2');
});

it('records each part\'s approval on a split\'s timeline, and none for an RFQ kept whole', function () {
    [$rfq, $ops] = splitWithPartOneThrough();
    $rfq->approveSeniorOpsPart(1, $ops);

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq).'?status=Pending')
        ->assertOk()
        ->assertSee('Part approved by Senior Operations')
        ->assertSee('RFQ1001-P1 of P2');

    $riley = userWithRole('Sourcing');
    $whole = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $whole->refresh()->approveSeniorOpsPart(1, $ops);

    test()->actingAs($ops)->get(route('admin.rfqs.show', $whole).'?status=Pending')
        ->assertSee('Approved by Senior Operations (2nd review)')
        ->assertDontSee('Part approved by Senior Operations');
});

it('approves the parts of RFQs approved before this was part by part', function () {
    [$rfq, $ops, $dataEntry] = splitWithPartOneThrough();
    $rfq->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, $dataEntry);
    $migration = require database_path('migrations/2026_09_20_045936_add_senior_ops_review_to_rfq_user_table.php');

    // Another, not approved, with a part through Data Entry.
    $waiting = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $waiting->completeSourcingPart(1);
    $waiting->refresh()->completeDataEntryPart(1, $dataEntry);

    // As it was: the approval on the RFQ alone.
    $migration->down();
    DB::table('rfqs')->where('id', $rfq->id)->update(['senior_ops_reviewed_at' => now()->subDay(), 'senior_ops_reviewed_by' => $ops->id]);

    $migration->up();

    $approved = DB::table('rfq_user')->where('rfq_id', $rfq->id)->get();
    $unapproved = DB::table('rfq_user')->where('rfq_id', $waiting->id)->get();

    expect($approved->pluck('senior_ops_reviewed_by')->all())->toBe([$ops->id, $ops->id])
        ->and($approved->every(fn ($part) => $part->senior_ops_reviewed_at !== null))->toBeTrue()
        ->and($unapproved->pluck('senior_ops_reviewed_at')->all())->toBe([null]);
});
