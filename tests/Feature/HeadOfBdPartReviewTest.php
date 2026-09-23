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

function headReviewUrl(array $query = []): string
{
    return route('admin.rfqs.index', ['status' => 'Pending'] + $query);
}

/**
 * A two-way split, RFQ1001, both parts through Sourcing and Data Entry, with
 * Senior Operations having approved part 1 only. Returns the RFQ and the
 * people: Senior Operations, Data Entry, the Head, Sourcing.
 *
 * @return array{0: Rfq, 1: User, 2: User, 3: User, 4: User}
 */
function splitWithPartOneApprovedBySeniorOps(): array
{
    [$ops, $dataEntry, $head, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Head of Business Development'), userWithRole('Sourcing')];

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs', 'priority_level' => 'Medium']), [1 => $riley, 2 => $riley]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry);
    }
    $rfq->refresh()->approveSeniorOpsPart(1, $ops);

    return [$rfq->refresh(), $ops, $dataEntry, $head, $riley];
}

it('lists a part as its own row once Senior Operations has approved it, and only those', function () {
    [$rfq, $ops, , $head] = splitWithPartOneApprovedBySeniorOps();

    $html = test()->actingAs($head)->get(headReviewUrl())->assertOk()
        ->assertSee('Replace exit signs')
        ->assertSee('RFQ1001-P1 of P2')
        // Part 2 is through Data Entry but not yet approved by Senior Operations.
        ->assertDontSee('RFQ1001-P2 of P2')
        ->assertSee('by '.e($ops->name), false)
        ->getContent();

    expect($html)->toContain('<input type="hidden" name="part" value="1">')
        ->toContain(route('admin.rfqs.approve-head-of-bd-part', $rfq))
        // Reject sends just this part back, and says which.
        ->toContain('data-part="1"')
        ->toContain('data-label="RFQ1001-P1 of P2"')
        ->not->toContain('<input type="hidden" name="part" value="2">')
        // Nothing for the RFQ as a whole yet.
        ->not->toContain('action="'.route('admin.rfqs.approve-head-of-bd', $rfq).'"');
});

it('has nothing for the Head until Senior Operations has approved a part', function () {
    [$dataEntry, $head, $riley] = [userWithRole('Data Entry'), userWithRole('Head of Business Development'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'Not approved yet']), [1 => $riley]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    test()->actingAs($head)->get(headReviewUrl())->assertOk()
        ->assertDontSee('Not approved yet')
        ->assertSee('Nothing\'s waiting on your review right now.', false);

    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));

    test()->actingAs($head)->get(headReviewUrl())->assertSee('Not approved yet');
});

it('approves a part on its own, and the RFQ moves on once every part has been approved', function () {
    [$rfq, $ops, , $head] = splitWithPartOneApprovedBySeniorOps();

    test()->actingAs($head)->from(headReviewUrl())
        ->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1])
        ->assertRedirect(headReviewUrl())
        ->assertSessionHas('status', 'Approved RFQ1001-P1 of P2 — waiting on the rest of the parts.');

    $rfq->refresh();
    $part = $rfq->assigneeForPart(1)->pivot;

    expect($part->head_of_bd_approved_at)->not->toBeNull()
        ->and($part->head_of_bd_approved_by)->toBe($head->id)
        ->and($rfq->assigneeForPart(2)->pivot->head_of_bd_approved_at)->toBeNull()
        // The RFQ itself hasn't moved: part 2 is still with Senior Operations.
        ->and($rfq->stage)->toBe('senior_ops_review')
        ->and($rfq->head_of_bd_approved_at)->toBeNull();

    test()->actingAs($head)->get(headReviewUrl())->assertDontSee('Replace exit signs');

    // Senior Operations approves the last part: it's the only row.
    $rfq->approveSeniorOpsPart(2, $ops);

    expect($rfq->refresh()->stage)->toBe('head_of_bd_review');

    test()->actingAs($head)->get(headReviewUrl())->assertOk()
        ->assertSee('RFQ1001-P2 of P2')
        ->assertDontSee('RFQ1001-P1 of P2');

    test()->actingAs($head)->from(headReviewUrl())
        ->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 2])
        ->assertSessionHas('status', 'Approved — every part is through, escalated to GM Assistant.');

    $rfq->refresh();

    expect($rfq->stage)->toBe('gm_assistant')
        ->and($rfq->head_of_bd_approved_by)->toBe($head->id)
        ->and($rfq->head_of_bd_approved_at)->not->toBeNull();

    test()->actingAs($head)->get(headReviewUrl())->assertDontSee('Replace exit signs');
});

it('approves the parts still waiting when the whole RFQ is approved', function () {
    [$rfq, $ops, , $head] = splitWithPartOneApprovedBySeniorOps();
    $rfq->approveHeadOfBdPart(1, $head);
    $rfq->refresh()->approveSeniorOpsPart(2, $ops);

    test()->actingAs($head)
        ->patch(route('admin.rfqs.approve-head-of-bd', $rfq->refresh()))
        ->assertSessionHas('status', 'Approved — escalated to GM Assistant.');

    $rfq->refresh();

    expect($rfq->stage)->toBe('gm_assistant')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->head_of_bd_approved_at !== null))->toBeTrue()
        ->and($rfq->assigneeForPart(2)->pivot->head_of_bd_approved_by)->toBe($head->id);
});

it('lists an RFQ kept whole as one row, whose Approve moves it on', function () {
    [$ops, $dataEntry, $head, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Head of Business Development'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'One piece of work']), [1 => $riley]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);
    $rfq->refresh()->approveSeniorOpsPart(1, $ops);

    $html = test()->actingAs($head)->get(headReviewUrl())->assertOk()->assertSee('One piece of work')->getContent();

    expect($html)->toContain('<td class="text-nowrap">'.$rfq->rfq_number.'</td>')
        ->toContain('<input type="hidden" name="part" value="1">');

    test()->actingAs($head)->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1]);

    expect($rfq->refresh()->stage)->toBe('gm_assistant');
});

it('still lists an RFQ at its review stage with no part of its own to show, as a row for the whole', function () {
    $head = userWithRole('Head of Business Development');
    $rfq = Rfq::factory()->create(['stage' => 'head_of_bd_review', 'subject' => 'Straight to the Head']);

    test()->actingAs($head)->get(headReviewUrl())
        ->assertSee('Straight to the Head')
        ->assertSee(route('admin.rfqs.approve-head-of-bd', $rfq))
        ->assertSee(route('admin.rfqs.reject-head-of-bd', $rfq));
});

it('lets Admin work the Head\'s page', function () {
    [$rfq] = splitWithPartOneApprovedBySeniorOps();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(headReviewUrl(['role' => 'head-of-business-development']))
        ->assertOk()
        ->assertSee('<input type="hidden" name="part" value="1">', false);

    test()->actingAs($admin)->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1]);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->head_of_bd_approved_by)->toBe($admin->id);
});

it('refuses an approval that isn\'t the Head\'s to give', function () {
    [$rfq, $ops, $dataEntry, $head] = splitWithPartOneApprovedBySeniorOps();
    $approve = fn (User $user, array $data) => test()->actingAs($user)->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), $data);

    // Only the Head of Business Development (and Admin).
    $approve($ops, ['part' => 1])->assertForbidden();
    $approve($dataEntry, ['part' => 1])->assertForbidden();

    // A part Senior Operations hasn't approved, one that doesn't exist, none at all.
    $approve($head, ['part' => 2])->assertStatus(422);
    $approve($head, ['part' => 9])->assertNotFound();
    $approve($head, [])->assertSessionHasErrors('part');

    // And a part already approved.
    $approve($head, ['part' => 1])->assertSessionHas('status');
    $approve($head, ['part' => 1])->assertStatus(422);

    expect($rfq->refresh()->assigneeForPart(2)->pivot->head_of_bd_approved_at)->toBeNull();
});

it('leaves an approval alone that is repeated on the model', function () {
    [$rfq, , , $head] = splitWithPartOneApprovedBySeniorOps();

    $rfq->approveHeadOfBdPart(1, $head);
    $first = $rfq->refresh()->assigneeForPart(1)->pivot->head_of_bd_approved_at;

    test()->travel(5)->minutes();
    $rfq->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->head_of_bd_approved_at->equalTo($first))->toBeTrue()
        ->and($rfq->assigneeForPart(1)->pivot->head_of_bd_approved_by)->toBe($head->id);
});

it('sends a part back to Senior Operations\' review, leaving the rest as it was', function () {
    [$rfq, $ops, , $head] = splitWithPartOneApprovedBySeniorOps();
    $rfq->approveSeniorOpsPart(2, $ops);
    $rfq->refresh()->approveHeadOfBdPart(2, $head);

    // Everything is with the Head now, and part 2 is already approved.
    expect($rfq->refresh()->stage)->toBe('head_of_bd_review');

    test()->actingAs($head)->from(headReviewUrl())
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'senior_ops_review', 'reason' => 'Look again'])
        ->assertRedirect(headReviewUrl())
        ->assertSessionHas('status', 'Sent RFQ1001-P1 of P2 back to Senior Operations (2nd review).');

    $rfq->refresh();
    $one = $rfq->assigneeForPart(1)->pivot;
    $two = $rfq->assigneeForPart(2)->pivot;
    $comment = $rfq->comments()->where('action', 'rejected')->sole();

    // Part 1 is up for Senior Operations again; part 2 keeps both its approvals.
    expect($one->senior_ops_reviewed_at)->toBeNull()
        ->and($one->head_of_bd_approved_at)->toBeNull()
        ->and($one->data_entry_completed_at)->not->toBeNull()
        ->and($two->senior_ops_reviewed_at)->not->toBeNull()
        ->and($two->head_of_bd_approved_at)->not->toBeNull()
        // The RFQ isn't approved as a whole by Senior Operations any more.
        ->and($rfq->stage)->toBe('senior_ops_review')
        ->and($rfq->senior_ops_reviewed_at)->toBeNull()
        ->and($rfq->rejected_by)->toBe($head->id)
        ->and($rfq->reject_from_stage)->toBe('head_of_bd_review')
        ->and($rfq->reject_target_stage)->toBe('senior_ops_review')
        // The rejection says which part it was about.
        ->and($comment->body)->toBe('Look again')
        ->and($comment->meta)->toBe(['stage' => 'Senior Operations (2nd review)', 'part' => 1, 'label' => 'RFQ1001-P1 of P2'])
        ->and($comment->actionContext())->toBe('RFQ1001-P1 of P2 · Returned to Senior Operations (2nd review)');

    // It's on Senior Operations' Review page again, as a row of its own.
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))
        ->assertSee('RFQ1001-P1 of P2')
        ->assertDontSee('RFQ1001-P2 of P2');
});

it('sends a part back to Data Entry', function () {
    [$rfq, , $dataEntry, $head] = splitWithPartOneApprovedBySeniorOps();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'data_entry', 'reason' => 'Prices mistyped']);

    $rfq->refresh();
    $one = $rfq->assigneeForPart(1)->pivot;

    // Sourcing's work stands; Data Entry has it to do again, and so the RFQ isn't through Data Entry.
    expect($one->completed_at)->not->toBeNull()
        ->and($one->data_entry_completed_at)->toBeNull()
        ->and($one->senior_ops_reviewed_at)->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->data_entry_completed_at)->not->toBeNull()
        ->and($rfq->stage)->toBeNull()
        ->and($rfq->data_entry_completed_at)->toBeNull();

    test()->actingAs($dataEntry)->get(headReviewUrl())
        ->assertSee('RFQ1001-P1 of P2')
        ->assertDontSee('RFQ1001-P2 of P2');
});

it('sends a part back to Sourcing', function () {
    [$rfq, , , $head, $riley] = splitWithPartOneApprovedBySeniorOps();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'Get another quote']);

    $rfq->refresh();
    $one = $rfq->assigneeForPart(1)->pivot;

    expect($one->completed_at)->toBeNull()
        ->and($one->returned_at)->not->toBeNull()
        ->and($one->return_reason)->toBe('Get another quote')
        ->and($one->data_entry_completed_at)->toBeNull()
        ->and($one->senior_ops_reviewed_at)->toBeNull()
        // The other part is exactly where it was.
        ->and($rfq->assigneeForPart(2)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->data_entry_completed_at)->not->toBeNull()
        ->and($rfq->stage)->toBeNull()
        ->and($rfq->comments()->pluck('action')->filter()->sort()->values()->all())->toBe(['rejected', 'returned_to_sourcing']);

    // With Sourcing again, for the one who holds it.
    test()->actingAs($riley)->get(headReviewUrl(['view' => 'returns']))->assertSee('RFQ1001-P1 of P2');
});

it('asks for where it goes and why when a part is sent back', function () {
    [$rfq, , , $head] = splitWithPartOneApprovedBySeniorOps();

    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'nowhere', 'reason' => ''])
        ->assertSessionHasErrors(['target_stage', 'reason'], null, 'reject');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_at)->not->toBeNull();

    // A part that isn't the Head's yet can't be sent back either.
    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 2, 'target_stage' => 'sourcing', 'reason' => 'No'])
        ->assertStatus(422);
});

it('still sends the whole RFQ back from its review stage', function () {
    [$rfq, $ops, , $head] = splitWithPartOneApprovedBySeniorOps();
    $rfq->approveSeniorOpsPart(2, $ops);
    $rfq->refresh()->approveHeadOfBdPart(1, $head);

    // Not at the review stage until Senior Operations has approved every part, and it is now.
    test()->actingAs($head)
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'senior_ops_review', 'reason' => 'All of it, again'])
        ->assertSessionHas('status', 'Sent back to Senior Operations (2nd review).');

    $rfq->refresh();

    // Every part's approvals go, the Head's included.
    expect($rfq->stage)->toBe('senior_ops_review')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->senior_ops_reviewed_at === null && $assignee->pivot->head_of_bd_approved_at === null))->toBeTrue();
});

it('takes back a part\'s approval by the Head when it is sent back for rework', function () {
    [$rfq, , $dataEntry, $head] = splitWithPartOneApprovedBySeniorOps();
    $rfq->approveHeadOfBdPart(1, $head);

    $rfq->refresh()->returnSourcingPart(1, 'Missing a quote', $dataEntry);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->head_of_bd_approved_at)->toBeNull();
});

it('counts each approval waiting — a row on the Review page — and shows it as a red badge on the sidebar\'s Review link', function () {
    [$ops, $dataEntry, $head, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Head of Business Development'), userWithRole('Sourcing')];

    // Three parts of one RFQ approved by Senior Operations, one RFQ kept whole, and one at its review stage with no part to show.
    $split = splitAmong(Rfq::factory()->create(), [1 => $riley, 2 => $riley, 3 => $riley]);
    $whole = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    Rfq::factory()->create(['stage' => 'head_of_bd_review']);
    foreach ([1, 2, 3] as $part) {
        $split->refresh()->completeSourcingPart($part);
        $split->refresh()->completeDataEntryPart($part, $dataEntry);
        $split->refresh()->approveSeniorOpsPart($part, $ops);
    }
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, $dataEntry);
    $whole->refresh()->approveSeniorOpsPart(1, $ops);

    // And one Senior Operations hasn't got to yet, which isn't the Head's.
    $notYet = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $notYet->completeSourcingPart(1);
    $notYet->refresh()->completeDataEntryPart(1, $dataEntry);

    expect(Rfq::headOfBdReviewCount())->toBe(5)
        ->and(Rfq::queueCounts()['head_of_bd'])->toBe(5);

    $badge = fn (int $count) => '<span class="nav-link-count" title="'.$count.' awaiting your review">'.$count.'</span>';
    $page = fn () => test()->actingAs($head)->get(headReviewUrl())->assertOk()->getContent();

    // Five rows on the page, five on the badge.
    $html = $page();
    expect(substr_count($html, 'data-confirm="Approve '))->toBe(5)
        ->and($html)->toContain($badge(5));

    $split->refresh()->approveHeadOfBdPart(1, $head);

    expect($page())->toContain($badge(4));

    // Nothing waiting, no badge.
    foreach ([2, 3] as $part) {
        $split->refresh()->approveHeadOfBdPart($part, $head);
    }
    $whole->refresh()->approveHeadOfBdPart(1, $head);
    Rfq::where('stage', 'head_of_bd_review')->update(['stage' => 'gm_assistant']);

    expect($page())->not->toContain('awaiting your review')
        ->and(Rfq::headOfBdReviewCount())->toBe(0);
});

it('records each part\'s approval on a split\'s timeline, and none for an RFQ kept whole', function () {
    [$rfq, , , $head] = splitWithPartOneApprovedBySeniorOps();
    $rfq->approveHeadOfBdPart(1, $head);

    test()->actingAs($head)->get(route('admin.rfqs.show', $rfq).'?status=Pending')
        ->assertOk()
        ->assertSee('Part approved by Head of Business Development')
        ->assertSee('RFQ1001-P1 of P2');

    [$ops, $dataEntry, $riley] = [userWithRole('Senior Operations'), userWithRole('Data Entry'), userWithRole('Sourcing')];
    $whole = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, $dataEntry);
    $whole->refresh()->approveSeniorOpsPart(1, $ops);
    $whole->refresh()->approveHeadOfBdPart(1, $head);

    test()->actingAs($head)->get(route('admin.rfqs.show', $whole).'?status=Pending')
        ->assertSee('Approved by Head of Business Development')
        ->assertDontSee('Part approved by Head of Business Development');
});

it('approves the parts of RFQs approved by the Head before this was part by part', function () {
    [$rfq, $ops, , $head] = splitWithPartOneApprovedBySeniorOps();
    $rfq->approveSeniorOpsPart(2, $ops);
    $migration = require database_path('migrations/2026_09_20_052837_add_head_of_bd_approval_to_rfq_user_table.php');

    // Another, waiting on the Head, with a part Senior Operations has approved.
    $waiting = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $waiting->completeSourcingPart(1);
    $waiting->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $waiting->refresh()->approveSeniorOpsPart(1, $ops);

    // As it was: the Head's approval on the RFQ alone.
    $migration->down();
    DB::table('rfqs')->where('id', $rfq->id)->update(['head_of_bd_approved_at' => now()->subDay(), 'head_of_bd_approved_by' => $head->id]);

    $migration->up();

    $approved = DB::table('rfq_user')->where('rfq_id', $rfq->id)->get();
    $unapproved = DB::table('rfq_user')->where('rfq_id', $waiting->id)->get();

    expect($approved->pluck('head_of_bd_approved_by')->all())->toBe([$head->id, $head->id])
        ->and($approved->every(fn ($part) => $part->head_of_bd_approved_at !== null))->toBeTrue()
        ->and($unapproved->pluck('head_of_bd_approved_at')->all())->toBe([null]);
});
