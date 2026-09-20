<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // The list page looks up its Sourcing and Senior Operations members.
    foreach (['Sourcing', 'Senior Operations', 'Data Entry', 'Head of Business Development', 'GM Assistant', 'General Manager', 'Business Development'] as $role) {
        Role::findOrCreate($role);
    }
});

function gmPageUrl(array $query = []): string
{
    return route('admin.rfqs.index', ['status' => 'Pending'] + $query);
}

/**
 * The people in the approval chain, by the name they go by here.
 *
 * @return array{ops: User, dataEntry: User, head: User, assistant: User, gm: User, sourcing: User}
 */
function approvalChain(): array
{
    return [
        'ops' => userWithRole('Senior Operations'),
        'dataEntry' => userWithRole('Data Entry'),
        'head' => userWithRole('Head of Business Development'),
        'assistant' => userWithRole('GM Assistant'),
        'gm' => userWithRole('General Manager'),
        'sourcing' => userWithRole('Sourcing'),
    ];
}

/**
 * A two-way split, RFQ1001, both parts through Sourcing, Data Entry and Senior
 * Operations, with the Head of Business Development having approved part 1
 * only — so part 1 is with GM Assistant while the RFQ is still with the Head.
 *
 * @return array{0: Rfq, 1: array{ops: User, dataEntry: User, head: User, assistant: User, gm: User, sourcing: User}}
 */
function splitWithPartOneApprovedByHead(): array
{
    $chain = approvalChain();

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs', 'priority_level' => 'Medium']), [1 => $chain['sourcing'], 2 => $chain['sourcing']]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $chain['dataEntry']);
        $rfq->refresh()->approveSeniorOpsPart($part, $chain['ops']);
    }
    $rfq->refresh()->approveHeadOfBdPart(1, $chain['head']);

    return [$rfq->refresh(), $chain];
}

it('goes to GM Assistant as its own row as soon as the Head approves a part, and only those', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();

    // The RFQ itself is still with the Head — part 2 hasn't been approved.
    expect($rfq->stage)->toBe('head_of_bd_review');

    $html = test()->actingAs($chain['assistant'])->get(gmPageUrl())->assertOk()
        ->assertSee('Replace exit signs')
        ->assertSee('RFQ1001-P1 of P2')
        ->assertDontSee('RFQ1001-P2 of P2')
        ->assertSee('by '.e($chain['head']->name), false)
        ->getContent();

    // Adding details is for this part alone.
    expect($html)->toContain(route('admin.rfqs.gm-assistant-details', $rfq))
        ->toContain('data-part="1"')
        ->toContain('data-label="RFQ1001-P1 of P2"')
        ->not->toContain('data-part="2"');
});

it('has nothing for GM Assistant until the Head has approved a part', function () {
    $chain = approvalChain();
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'Not with the Head yet']), [1 => $chain['sourcing']]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $chain['dataEntry']);
    $rfq->refresh()->approveSeniorOpsPart(1, $chain['ops']);

    test()->actingAs($chain['assistant'])->get(gmPageUrl())->assertOk()
        ->assertDontSee('Not with the Head yet')
        ->assertSee('Nothing\'s waiting on you right now.', false);

    $rfq->refresh()->approveHeadOfBdPart(1, $chain['head']);

    test()->actingAs($chain['assistant'])->get(gmPageUrl())->assertSee('Not with the Head yet');
});

it('takes a part\'s details on its own, and the RFQ goes on to the General Manager once every part has them', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();

    test()->actingAs($chain['assistant'])->from(gmPageUrl())
        ->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 1, 'client_details' => 'Acme Ltd, Colombo', 'payment_terms' => 'Net 30'])
        ->assertRedirect(gmPageUrl())
        ->assertSessionHas('status', 'Details added for RFQ1001-P1 of P2 — forwarded to General Manager.');

    $rfq->refresh();
    $part = $rfq->assigneeForPart(1)->pivot;

    expect($part->gm_assistant_completed_at)->not->toBeNull()
        ->and($part->gm_assistant_completed_by)->toBe($chain['assistant']->id)
        ->and($rfq->assigneeForPart(2)->pivot->gm_assistant_completed_at)->toBeNull()
        // The details are the RFQ's; the RFQ hasn't moved.
        ->and($rfq->client_details)->toBe('Acme Ltd, Colombo')
        ->and($rfq->payment_terms)->toBe('Net 30')
        ->and($rfq->stage)->toBe('head_of_bd_review')
        ->and($rfq->gm_assistant_completed_at)->toBeNull();

    // It's with the General Manager already, ahead of part 2.
    test()->actingAs($chain['gm'])->get(gmPageUrl())->assertOk()
        ->assertSee('RFQ1001-P1 of P2')
        ->assertDontSee('RFQ1001-P2 of P2');

    // The Head approves part 2: it's the only row for GM Assistant, with what was given already there to confirm.
    $rfq->approveHeadOfBdPart(2, $chain['head']);

    expect($rfq->refresh()->stage)->toBe('gm_assistant');

    test()->actingAs($chain['assistant'])->get(gmPageUrl())->assertOk()
        ->assertSee('RFQ1001-P2 of P2')
        ->assertDontSee('RFQ1001-P1 of P2')
        ->assertSee('data-client-details="Acme Ltd, Colombo"', false)
        ->assertSee('data-payment-terms="Net 30"', false);

    test()->actingAs($chain['assistant'])->from(gmPageUrl())
        ->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 2, 'client_details' => 'Acme Ltd, Colombo', 'payment_terms' => 'Net 45'])
        ->assertSessionHas('status', 'Details added — every part is through, forwarded to General Manager.');

    $rfq->refresh();

    // The latest terms stand for the RFQ.
    expect($rfq->stage)->toBe('gm_review')
        ->and($rfq->gm_assistant_completed_by)->toBe($chain['assistant']->id)
        ->and($rfq->gm_assistant_completed_at)->not->toBeNull()
        ->and($rfq->payment_terms)->toBe('Net 45');

    test()->actingAs($chain['assistant'])->get(gmPageUrl())->assertDontSee('Replace exit signs');
});

it('lets the General Manager approve a part on its own, and readies the RFQ to close once every part is approved', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $rfq->approveHeadOfBdPart(2, $chain['head']);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->recordGmAssistantPart($part, $chain['assistant'], 'Acme Ltd', null);
    }

    expect($rfq->refresh()->stage)->toBe('gm_review');

    test()->actingAs($chain['gm'])->from(gmPageUrl())
        ->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 1])
        ->assertRedirect(gmPageUrl())
        ->assertSessionHas('status', 'Approved RFQ1001-P1 of P2 — waiting on the rest of the parts.');

    $rfq->refresh();
    $part = $rfq->assigneeForPart(1)->pivot;

    expect($part->gm_approved_at)->not->toBeNull()
        ->and($part->gm_approved_by)->toBe($chain['gm']->id)
        ->and($rfq->assigneeForPart(2)->pivot->gm_approved_at)->toBeNull()
        ->and($rfq->stage)->toBe('gm_review')
        ->and($rfq->gm_approved_at)->toBeNull();

    // Part 2 is the only row left (part 1 is in the flash message above it, so look at the rows).
    test()->actingAs($chain['gm'])->get(gmPageUrl())->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ1001-P2 of P2</td>', false)
        ->assertDontSee('<td class="text-nowrap">RFQ1001-P1 of P2</td>', false);

    test()->actingAs($chain['gm'])->from(gmPageUrl())
        ->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 2])
        ->assertSessionHas('status', 'Approved — every part is through, ready for Business Development to close.');

    $rfq->refresh();

    expect($rfq->stage)->toBe('bd_closing')
        ->and($rfq->gm_approved_by)->toBe($chain['gm']->id)
        ->and($rfq->gm_approved_at)->not->toBeNull();

    // Business Development closes it as a whole, from their own list.
    test()->actingAs(userWithRole('Business Development'))->get(gmPageUrl(['view' => 'closing']))
        ->assertOk()
        ->assertSee('Replace exit signs');
    test()->actingAs($chain['gm'])->get(gmPageUrl())->assertDontSee('Replace exit signs');
});

it('sends the whole RFQ on from GM Assistant, completing the parts not yet completed', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $rfq->approveHeadOfBdPart(2, $chain['head']);
    $rfq->refresh()->recordGmAssistantPart(1, $chain['assistant'], 'First details', null);

    test()->actingAs($chain['assistant'])
        ->patch(route('admin.rfqs.gm-assistant-details', $rfq->refresh()), ['client_details' => 'Whole RFQ details', 'payment_terms' => ''])
        ->assertSessionHas('status', 'Forwarded to General Manager.');

    $rfq->refresh();

    expect($rfq->stage)->toBe('gm_review')
        ->and($rfq->client_details)->toBe('Whole RFQ details')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->gm_assistant_completed_at !== null))->toBeTrue()
        // Part 1 keeps its own; part 2 gets this one.
        ->and($rfq->assigneeForPart(2)->pivot->gm_assistant_completed_by)->toBe($chain['assistant']->id);
});

it('approves the parts still waiting when the General Manager approves the whole RFQ', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $rfq->approveHeadOfBdPart(2, $chain['head']);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->recordGmAssistantPart($part, $chain['assistant'], 'Acme Ltd', null);
    }
    $rfq->refresh()->approveGmPart(1, $chain['gm']);

    test()->actingAs($chain['gm'])
        ->patch(route('admin.rfqs.approve-gm', $rfq->refresh()))
        ->assertSessionHas('status', 'Approved — ready for Business Development to close.');

    $rfq->refresh();

    expect($rfq->stage)->toBe('bd_closing')
        ->and($rfq->assignees->every(fn (User $assignee) => $assignee->pivot->gm_approved_at !== null))->toBeTrue()
        ->and($rfq->assigneeForPart(2)->pivot->gm_approved_by)->toBe($chain['gm']->id);
});

it('takes an RFQ kept whole through GM Assistant and the General Manager as one row each', function () {
    $chain = approvalChain();
    $rfq = splitAmong(Rfq::factory()->create(['subject' => 'One piece of work']), [1 => $chain['sourcing']]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $chain['dataEntry']);
    $rfq->refresh()->approveSeniorOpsPart(1, $chain['ops']);
    $rfq->refresh()->approveHeadOfBdPart(1, $chain['head']);

    // Its plain RFQ number, not a part's.
    test()->actingAs($chain['assistant'])->get(gmPageUrl())->assertOk()
        ->assertSee('<td class="text-nowrap">'.$rfq->rfq_number.'</td>', false)
        ->assertSee('data-part="1"', false);

    test()->actingAs($chain['assistant'])
        ->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 1, 'client_details' => 'Acme Ltd']);

    expect($rfq->refresh()->stage)->toBe('gm_review');

    test()->actingAs($chain['gm'])->get(gmPageUrl())->assertOk()
        ->assertSee('<td class="text-nowrap">'.$rfq->rfq_number.'</td>', false)
        ->assertSee('<input type="hidden" name="part" value="1">', false);

    test()->actingAs($chain['gm'])->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 1]);

    expect($rfq->refresh()->stage)->toBe('bd_closing');
});

it('still lists an RFQ at either step with no part of its own to show, as a row for the whole', function () {
    $chain = approvalChain();
    $atAssistant = Rfq::factory()->create(['stage' => 'gm_assistant', 'subject' => 'Straight to GM Assistant']);
    $atGm = Rfq::factory()->create(['stage' => 'gm_review', 'subject' => 'Straight to the GM']);

    test()->actingAs($chain['assistant'])->get(gmPageUrl())
        ->assertSee('Straight to GM Assistant')
        ->assertSee(route('admin.rfqs.gm-assistant-details', $atAssistant))
        ->assertDontSee('Straight to the GM');

    test()->actingAs($chain['gm'])->get(gmPageUrl())
        ->assertSee('Straight to the GM')
        ->assertSee(route('admin.rfqs.approve-gm', $atGm))
        ->assertDontSee('Straight to GM Assistant');
});

it('lets Admin work both pages', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(gmPageUrl(['role' => 'gm-assistant']))->assertOk()->assertSee('RFQ1001-P1 of P2');

    test()->actingAs($admin)->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 1, 'client_details' => 'Acme Ltd']);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->gm_assistant_completed_by)->toBe($admin->id);

    test()->actingAs($admin)->get(gmPageUrl(['role' => 'general-manager']))->assertOk()->assertSee('RFQ1001-P1 of P2');

    test()->actingAs($admin)->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 1]);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->gm_approved_by)->toBe($admin->id);
});

it('refuses details that aren\'t GM Assistant\'s to give', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $give = fn (User $user, array $data) => test()->actingAs($user)->patch(route('admin.rfqs.gm-assistant-details', $rfq), $data);

    // Only GM Assistant (and Admin).
    $give($chain['head'], ['part' => 1, 'client_details' => 'Acme'])->assertForbidden();
    $give($chain['gm'], ['part' => 1, 'client_details' => 'Acme'])->assertForbidden();

    // A part the Head hasn't approved, one that doesn't exist, no client details.
    $give($chain['assistant'], ['part' => 2, 'client_details' => 'Acme'])->assertStatus(422);
    $give($chain['assistant'], ['part' => 9, 'client_details' => 'Acme'])->assertNotFound();
    $give($chain['assistant'], ['part' => 1, 'client_details' => ''])->assertSessionHasErrors('client_details', null, 'gm_assistant');
    // Nor the whole RFQ, until it has reached them.
    $give($chain['assistant'], ['client_details' => 'Acme'])->assertStatus(422);

    expect($rfq->refresh()->client_details)->toBeNull()
        ->and($rfq->assigneeForPart(1)->pivot->gm_assistant_completed_at)->toBeNull();

    // A part already completed keeps its details, and what a second try says is refused.
    $give($chain['assistant'], ['part' => 1, 'client_details' => 'Acme'])->assertSessionHas('status');
    $give($chain['assistant'], ['part' => 1, 'client_details' => 'Something else'])->assertStatus(422);

    expect($rfq->refresh()->client_details)->toBe('Acme');
});

it('refuses an approval that isn\'t the General Manager\'s to give', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $rfq->refresh()->recordGmAssistantPart(1, $chain['assistant'], 'Acme Ltd', null);
    $approve = fn (User $user, array $data) => test()->actingAs($user)->patch(route('admin.rfqs.approve-gm-part', $rfq), $data);

    // Only the General Manager (and Admin).
    $approve($chain['assistant'], ['part' => 1])->assertForbidden();
    $approve($chain['head'], ['part' => 1])->assertForbidden();

    // A part GM Assistant hasn't completed, one that doesn't exist, none at all.
    $approve($chain['gm'], ['part' => 2])->assertStatus(422);
    $approve($chain['gm'], ['part' => 9])->assertNotFound();
    $approve($chain['gm'], [])->assertSessionHasErrors('part');

    // And a part already approved.
    $approve($chain['gm'], ['part' => 1])->assertSessionHas('status');
    $approve($chain['gm'], ['part' => 1])->assertStatus(422);

    expect($rfq->refresh()->assigneeForPart(2)->pivot->gm_approved_at)->toBeNull();
});

it('leaves a part alone that is repeated on the model', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();

    $rfq->recordGmAssistantPart(1, $chain['assistant'], 'First', 'Net 30');
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'), 'Second', 'Net 60');
    $rfq->refresh()->approveGmPart(1, $chain['gm']);
    $first = $rfq->refresh()->assigneeForPart(1)->pivot->gm_approved_at;

    test()->travel(5)->minutes();
    $rfq->approveGmPart(1, userWithRole('General Manager'));

    expect($rfq->refresh()->client_details)->toBe('First')
        ->and($rfq->payment_terms)->toBe('Net 30')
        ->and($rfq->assigneeForPart(1)->pivot->gm_assistant_completed_by)->toBe($chain['assistant']->id)
        ->and($rfq->assigneeForPart(1)->pivot->gm_approved_at->equalTo($first))->toBeTrue()
        ->and($rfq->assigneeForPart(1)->pivot->gm_approved_by)->toBe($chain['gm']->id);
});

it('takes back everything a part had been through when the Head sends the RFQ back, or it is sent back for rework', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $rfq->approveHeadOfBdPart(2, $chain['head']);
    $rfq->refresh()->recordGmAssistantPart(1, $chain['assistant'], 'Acme Ltd', null);
    $rfq->refresh()->approveGmPart(1, $chain['gm']);

    // Sent back for rework: part 1 has to come all the way through again.
    $rfq->refresh()->returnSourcingPart(1, 'Missing a quote', $chain['dataEntry']);

    $one = $rfq->refresh()->assigneeForPart(1)->pivot;

    expect($one->senior_ops_reviewed_at)->toBeNull()
        ->and($one->head_of_bd_approved_at)->toBeNull()
        ->and($one->gm_assistant_completed_at)->toBeNull()
        ->and($one->gm_approved_at)->toBeNull()
        // Part 2 is where it was.
        ->and($rfq->assigneeForPart(2)->pivot->head_of_bd_approved_at)->not->toBeNull();

    // And the Head sending the whole RFQ back takes every part's.
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $chain['dataEntry']);
    $rfq->refresh()->approveSeniorOpsPart(1, $chain['ops']);
    $rfq->refresh()->approveHeadOfBdPart(1, $chain['head']);
    $rfq->refresh()->recordGmAssistantPart(2, $chain['assistant'], 'Acme Ltd', null);
    $rfq->refresh()->approveGmPart(2, $chain['gm']);
    // Every part approved by the Head takes it on, so send it back from where it was.
    $rfq->update(['stage' => 'head_of_bd_review']);

    $rfq->refresh()->rejectToStage('senior_ops_review', 'Look again', $chain['head']);

    expect($rfq->refresh()->assignees->every(fn (User $assignee) => $assignee->pivot->senior_ops_reviewed_at === null
        && $assignee->pivot->head_of_bd_approved_at === null
        && $assignee->pivot->gm_assistant_completed_at === null
        && $assignee->pivot->gm_approved_at === null))->toBeTrue();
});

it('counts each approval waiting — a row on each page — and shows it as a red badge on the sidebar', function () {
    $chain = approvalChain();

    // Three parts of one RFQ, one kept whole, and one at each step with no part to show.
    $split = splitAmong(Rfq::factory()->create(), [1 => $chain['sourcing'], 2 => $chain['sourcing'], 3 => $chain['sourcing']]);
    $whole = splitAmong(Rfq::factory()->create(), [1 => $chain['sourcing']]);
    Rfq::factory()->create(['stage' => 'gm_assistant']);
    Rfq::factory()->create(['stage' => 'gm_review']);
    foreach ([1, 2, 3] as $part) {
        $split->refresh()->completeSourcingPart($part);
        $split->refresh()->completeDataEntryPart($part, $chain['dataEntry']);
        $split->refresh()->approveSeniorOpsPart($part, $chain['ops']);
        $split->refresh()->approveHeadOfBdPart($part, $chain['head']);
    }
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, $chain['dataEntry']);
    $whole->refresh()->approveSeniorOpsPart(1, $chain['ops']);
    $whole->refresh()->approveHeadOfBdPart(1, $chain['head']);

    // Parts 1 and 2 of the split have been through GM Assistant, so are the General Manager's now.
    foreach ([1, 2] as $part) {
        $split->refresh()->recordGmAssistantPart($part, $chain['assistant'], 'Acme Ltd', null);
    }

    // GM Assistant: part 3, the whole one, and the RFQ at their step. General Manager: parts 1 and 2, and the RFQ at theirs.
    expect(Rfq::gmAssistantReviewCount())->toBe(3)
        ->and(Rfq::gmReviewCount())->toBe(3)
        ->and(Rfq::queueCounts()['gm_assistant'])->toBe(3)
        ->and(Rfq::queueCounts()['gm_review'])->toBe(3);

    $badge = fn (int $count, string $what) => '<span class="nav-link-count" title="'.$count.' '.$what.'">'.$count.'</span>';

    $assistantPage = test()->actingAs($chain['assistant'])->get(gmPageUrl())->assertOk()->getContent();
    $gmPage = test()->actingAs($chain['gm'])->get(gmPageUrl())->assertOk()->getContent();

    // Three rows on each page, three on each badge.
    expect(substr_count($assistantPage, 'js-gm-assistant-rfq'))->toBe(3)
        ->and($assistantPage)->toContain($badge(3, 'awaiting client details'))
        ->and(substr_count($gmPage, 'data-confirm="Approve '))->toBe(3)
        ->and($gmPage)->toContain($badge(3, 'awaiting your approval'));

    // One approved, one fewer.
    $split->refresh()->approveGmPart(1, $chain['gm']);

    expect(Rfq::gmReviewCount())->toBe(2)
        ->and(test()->actingAs($chain['gm'])->get(gmPageUrl())->getContent())->toContain($badge(2, 'awaiting your approval'));
});

it('records each part\'s step on a split\'s timeline, and none for an RFQ kept whole', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $rfq->recordGmAssistantPart(1, $chain['assistant'], 'Acme Ltd', null);
    $rfq->refresh()->approveGmPart(1, $chain['gm']);

    test()->actingAs($chain['gm'])->get(route('admin.rfqs.show', $rfq).'?status=Pending')
        ->assertOk()
        ->assertSee('Part details added by GM Assistant')
        ->assertSee('Part approved by General Manager')
        ->assertSee('RFQ1001-P1 of P2');

    $whole = splitAmong(Rfq::factory()->create(), [1 => $chain['sourcing']]);
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, $chain['dataEntry']);
    $whole->refresh()->approveSeniorOpsPart(1, $chain['ops']);
    $whole->refresh()->approveHeadOfBdPart(1, $chain['head']);
    $whole->refresh()->recordGmAssistantPart(1, $chain['assistant'], 'Acme Ltd', null);
    $whole->refresh()->approveGmPart(1, $chain['gm']);

    test()->actingAs($chain['gm'])->get(route('admin.rfqs.show', $whole).'?status=Pending')
        ->assertOk()
        ->assertSee('Approved by General Manager')
        ->assertDontSee('Part details added by GM Assistant')
        ->assertDontSee('Part approved by General Manager');
});

it('sends the parts of RFQs that got this far before it was part by part through along with them', function () {
    [$rfq, $chain] = splitWithPartOneApprovedByHead();
    $rfq->approveHeadOfBdPart(2, $chain['head']);
    $migration = require database_path('migrations/2026_09_20_053951_add_gm_review_to_rfq_user_table.php');

    // Another, waiting on GM Assistant, with a part the Head has approved.
    $waiting = splitAmong(Rfq::factory()->create(), [1 => $chain['sourcing']]);
    $waiting->completeSourcingPart(1);
    $waiting->refresh()->completeDataEntryPart(1, $chain['dataEntry']);
    $waiting->refresh()->approveSeniorOpsPart(1, $chain['ops']);
    $waiting->refresh()->approveHeadOfBdPart(1, $chain['head']);

    // As it was: GM Assistant's and the General Manager's steps on the RFQ alone.
    $migration->down();
    DB::table('rfqs')->where('id', $rfq->id)->update([
        'gm_assistant_completed_at' => now()->subDays(2), 'gm_assistant_completed_by' => $chain['assistant']->id,
        'gm_approved_at' => now()->subDay(), 'gm_approved_by' => $chain['gm']->id,
    ]);

    $migration->up();

    $done = DB::table('rfq_user')->where('rfq_id', $rfq->id)->get();
    $notYet = DB::table('rfq_user')->where('rfq_id', $waiting->id)->get();

    expect($done->pluck('gm_assistant_completed_by')->all())->toBe([$chain['assistant']->id, $chain['assistant']->id])
        ->and($done->pluck('gm_approved_by')->all())->toBe([$chain['gm']->id, $chain['gm']->id])
        ->and($notYet->pluck('gm_assistant_completed_at')->all())->toBe([null])
        ->and($notYet->pluck('gm_approved_at')->all())->toBe([null]);
});
