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

/**
 * The people who take an RFQ all the way from Sourcing to the General
 * Manager's own review.
 *
 * @return array{sourcing: User, sourcing2: User, dataEntry: User, ops: User, head: User, assistant: User, gm: User}
 */
function chainPeople(): array
{
    return [
        'sourcing' => userWithRole('Sourcing'),
        'sourcing2' => userWithRole('Sourcing'),
        'dataEntry' => userWithRole('Data Entry'),
        'ops' => userWithRole('Senior Operations'),
        'head' => userWithRole('Head of Business Development'),
        'assistant' => userWithRole('GM Assistant'),
        'gm' => userWithRole('General Manager'),
    ];
}

/**
 * A two-way split, every part carried through to just before Senior
 * Operations' second review (both through Sourcing and Data Entry).
 *
 * @param  array{sourcing: User, sourcing2: User, dataEntry: User, ops: User, head: User, assistant: User, gm: User}  $people
 */
function rfqReadyForSeniorOps(array $people): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs']), [1 => $people['sourcing'], 2 => $people['sourcing2']]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $people['dataEntry']);
    }

    return $rfq->refresh();
}

/**
 * The same, carried all the way to just before the General Manager's own
 * review (every part approved by Senior Operations, the Head, and given
 * client details by GM Assistant).
 *
 * @param  array{sourcing: User, sourcing2: User, dataEntry: User, ops: User, head: User, assistant: User, gm: User}  $people
 */
function rfqReadyForGm(array $people): Rfq
{
    $rfq = rfqReadyForSeniorOps($people);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
        $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
        $rfq->refresh()->recordGmAssistantPart($part, $people['assistant'], 'Acme Ltd, Colombo', 'Net 30');
    }

    return $rfq->refresh();
}

// ---- Rfq::rejectTargetStages() --------------------------------------------

it('gives each reject-capable stage every earlier one, and nothing at or after its own', function () {
    expect(Rfq::rejectTargetStages('senior_ops_review'))->toBe(['operations', 'sourcing', 'data_entry'])
        ->and(Rfq::rejectTargetStages('head_of_bd_review'))->toBe(['operations', 'sourcing', 'data_entry', 'senior_ops_review'])
        // The General Manager's own example: Operations, Sourcing, Data
        // Entry, Senior Ops, Head of BD, GM Assistant.
        ->and(Rfq::rejectTargetStages('gm_review'))->toBe(['operations', 'sourcing', 'data_entry', 'senior_ops_review', 'head_of_bd_review', 'gm_assistant'])
        // Nothing before the very first stage, and nothing for a stage
        // that isn't part of this chain at all (GM Assistant only ever
        // forwards — see Rfq::REJECTABLE_STAGES — so it's never actually
        // asked for, but the lookup itself is just "everything earlier").
        ->and(Rfq::rejectTargetStages('operations'))->toBe([])
        ->and(Rfq::rejectTargetStages('bd_closing'))->toBe([]);
});

it('offers each review page only the stages before its own', function () {
    $people = chainPeople();

    $seniorOpsHtml = test()->actingAs($people['ops'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))
        ->assertOk()->getContent();
    $headHtml = test()->actingAs($people['head'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()->getContent();
    $gmHtml = test()->actingAs($people['gm'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()->getContent();

    expect($seniorOpsHtml)->toContain('<option value="operations" >')
        ->toContain('<option value="sourcing" >')
        ->toContain('<option value="data_entry" >')
        ->not->toContain('<option value="senior_ops_review" >');

    expect($headHtml)->toContain('<option value="senior_ops_review" >')
        ->not->toContain('<option value="head_of_bd_review" >');

    expect($gmHtml)->toContain('<option value="operations" >')
        ->toContain('<option value="sourcing" >')
        ->toContain('<option value="data_entry" >')
        ->toContain('<option value="senior_ops_review" >')
        ->toContain('<option value="head_of_bd_review" >')
        ->toContain('<option value="gm_assistant" >')
        ->not->toContain('<option value="gm_review" >');
});

// ---- Senior Operations rejects ---------------------------------------------

it('lets Senior Operations reject their own second review, back to their own assignment step', function () {
    $people = chainPeople();
    $rfq = rfqReadyForSeniorOps($people);

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['part' => 1, 'target_stage' => 'operations', 'reason' => 'Wrong Sourcing member — reassign it'])
        ->assertRedirect()
        ->assertSessionHas('status', 'Sent RFQ1001-P1 of P2 back to Senior Operations (assignment).');

    $rfq->refresh();

    // Part 1 is unassigned again — a fresh part for Senior Operations to
    // hand out, same as one nobody's ever taken.
    expect($rfq->assigneeForPart(1))->toBeNull()
        ->and($rfq->hasUnassignedParts())->toBeTrue()
        // Part 2 is exactly where it was.
        ->and($rfq->assigneeForPart(2)->pivot->data_entry_completed_at)->not->toBeNull()
        ->and($rfq->rejected_by)->toBe($people['ops']->id)
        ->and($rfq->reject_from_stage)->toBe('senior_ops_review')
        ->and($rfq->reject_target_stage)->toBe('operations')
        ->and($rfq->stage)->toBeNull();

    // Shows up on the Unassigned RFQs list, ready to be handed to someone.
    test()->actingAs($people['ops'])->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertSee('RFQ1001');
});

it('refuses Senior Operations a target that is not theirs to use', function () {
    $people = chainPeople();
    $rfq = rfqReadyForSeniorOps($people);

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['part' => 1, 'target_stage' => 'senior_ops_review', 'reason' => 'No'])
        ->assertSessionHasErrorsIn('reject', 'target_stage');

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['part' => 1, 'target_stage' => 'head_of_bd_review', 'reason' => 'No'])
        ->assertSessionHasErrorsIn('reject', 'target_stage');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->data_entry_completed_at)->not->toBeNull();
});

it('refuses the Senior Operations reject to anyone but Senior Operations', function (string $role) {
    $people = chainPeople();
    $rfq = rfqReadyForSeniorOps($people);

    test()->actingAs(userWithRole($role))
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'No'])
        ->assertForbidden();
})->with(['Head of Business Development', 'General Manager', 'Sourcing', 'Data Entry']);

it('sends the whole RFQ back to Sourcing from Senior Operations\' review, reopening every part', function () {
    $people = chainPeople();
    // Every part through Data Entry already parks the RFQ at
    // stage=senior_ops_review, ready for their whole-RFQ review — see
    // Rfq::completeDataEntryPart().
    $rfq = rfqReadyForSeniorOps($people);
    expect($rfq->stage)->toBe('senior_ops_review');

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'sourcing', 'reason' => 'Every quote needs redoing'])
        ->assertSessionHas('status', 'Sent back to Sourcing.');

    $rfq->refresh();

    expect($rfq->assigneeForPart(1)->pivot->completed_at)->toBeNull()
        ->and($rfq->assigneeForPart(1)->pivot->returned_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->completed_at)->toBeNull()
        ->and($rfq->stage)->toBeNull()
        ->and($rfq->reject_from_stage)->toBe('senior_ops_review');
});

// ---- General Manager rejects ------------------------------------------------

it('lets the General Manager reject all the way back to GM Assistant, leaving everything before it standing', function () {
    $people = chainPeople();
    $rfq = rfqReadyForGm($people);

    test()->actingAs($people['gm'])
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'gm_assistant', 'reason' => 'Payment terms are wrong'])
        ->assertSessionHas('status', 'Sent RFQ1001-P1 of P2 back to GM Assistant.');

    $one = $rfq->refresh()->assigneeForPart(1)->pivot;

    expect($one->gm_assistant_completed_at)->toBeNull()
        // Everything before GM Assistant still stands — a General Manager
        // reject to a stage close to their own doesn't undo Senior
        // Operations' or the Head's earlier approval.
        ->and($one->senior_ops_reviewed_at)->not->toBeNull()
        ->and($one->head_of_bd_approved_at)->not->toBeNull()
        ->and($rfq->reject_from_stage)->toBe('gm_review')
        ->and($rfq->reject_target_stage)->toBe('gm_assistant');
});

it('lets the General Manager reject back to the Head, undoing the Head\'s approval but not Senior Operations\'', function () {
    $people = chainPeople();
    $rfq = rfqReadyForGm($people);

    test()->actingAs($people['gm'])
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 2, 'target_stage' => 'head_of_bd_review', 'reason' => 'Wants a second look']);

    $two = $rfq->refresh()->assigneeForPart(2)->pivot;

    expect($two->head_of_bd_approved_at)->toBeNull()
        ->and($two->gm_assistant_completed_at)->toBeNull()
        ->and($two->senior_ops_reviewed_at)->not->toBeNull();
});

it('lets the General Manager reject the whole RFQ all the way back to Senior Operations\' own assignment step', function () {
    $people = chainPeople();
    $rfq = rfqReadyForGm($people);
    $rfq->refresh();
    $rfq->refresh()->approveByGm(userWithRole('Admin'));
    expect($rfq->refresh()->stage)->toBe('bd_closing'); // sanity: fully through before the reject

    // Force it back onto the General Manager's own queue for this test, as
    // if the approval above hadn't happened yet.
    $rfq->update(['stage' => 'gm_review', 'gm_approved_at' => null, 'gm_approved_by' => null]);

    test()->actingAs($people['gm'])
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'operations', 'reason' => 'Wrong category entirely — start over'])
        ->assertSessionHas('status', 'Sent back to Senior Operations (assignment).');

    $rfq->refresh();

    expect($rfq->assignees)->toHaveCount(0)
        ->and($rfq->hasUnassignedParts())->toBeTrue()
        ->and($rfq->sourcing_completed_at)->toBeNull()
        ->and($rfq->data_entry_completed_at)->toBeNull()
        ->and($rfq->senior_ops_reviewed_at)->toBeNull()
        ->and($rfq->head_of_bd_approved_at)->toBeNull()
        ->and($rfq->gm_assistant_completed_at)->toBeNull()
        ->and($rfq->gm_approved_at)->toBeNull()
        ->and($rfq->stage)->toBeNull()
        ->and($rfq->reject_from_stage)->toBe('gm_review')
        ->and($rfq->reject_target_stage)->toBe('operations');

    // Back on Senior Operations' Unassigned list, split intact (still 2 parts).
    expect($rfq->splitTotal())->toBe(2);
});

it('refuses the General Manager reject to anyone but the General Manager', function (string $role) {
    $people = chainPeople();
    $rfq = rfqReadyForGm($people);

    test()->actingAs(userWithRole($role))
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'gm_assistant', 'reason' => 'No'])
        ->assertForbidden();
})->with(['GM Assistant', 'Head of Business Development', 'Sourcing']);

it('refuses a part that is not the General Manager\'s to reject yet', function () {
    $people = chainPeople();
    $rfq = rfqReadyForSeniorOps($people); // not through to the General Manager at all

    test()->actingAs($people['gm'])
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'gm_assistant', 'reason' => 'No'])
        ->assertStatus(422);
});

// ---- the timeline -----------------------------------------------------------

it('records a Senior Operations rejection on the timeline, naming the role that sent it back', function () {
    $people = chainPeople();
    $rfq = rfqReadyForSeniorOps($people);

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'sourcing', 'reason' => 'Redo every quote']);

    test()->actingAs($people['ops'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')
        ->assertOk()
        ->assertSee('Rejected')
        ->assertSee('By Senior Operations (2nd review) — returned to Sourcing: Redo every quote', false);
});

it('records a General Manager rejection on the timeline the same way', function () {
    $people = chainPeople();
    $rfq = rfqReadyForGm($people);

    test()->actingAs($people['gm'])
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'gm_assistant', 'reason' => 'Fix the payment terms']);

    test()->actingAs($people['gm'])->get(route('admin.rfqs.show', $rfq->refresh()).'?status=Pending')
        ->assertOk()
        ->assertSee('By General Manager — returned to GM Assistant: Fix the payment terms', false);
});

it('clears a pending reject record once the RFQ is approved past it again, whoever rejected it', function () {
    $people = chainPeople();
    $rfq = rfqReadyForSeniorOps($people);

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['part' => 1, 'target_stage' => 'data_entry', 'reason' => 'Redo it']);

    expect($rfq->refresh()->rejected_at)->not->toBeNull();

    $rfq->refresh()->completeDataEntryPart(1, $people['dataEntry']);
    $rfq->refresh()->approveSeniorOpsPart(1, $people['ops']);
    $rfq->refresh()->approveSeniorOpsPart(2, $people['ops']);

    expect($rfq->refresh())
        ->rejected_by->toBeNull()
        ->rejected_at->toBeNull()
        ->reject_reason->toBeNull()
        ->reject_from_stage->toBeNull()
        ->reject_target_stage->toBeNull();
});

// ---- end to end: rejected all the way back, then carried through again -----

it('runs an RFQ the General Manager sent all the way back to Senior Operations through to closed again', function () {
    $people = chainPeople();
    $rfq = rfqReadyForGm($people);
    $rfq->refresh()->approveByGm(userWithRole('Admin'));
    $rfq->update(['stage' => 'gm_review', 'gm_approved_at' => null, 'gm_approved_by' => null]);

    test()->actingAs($people['gm'])
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'operations', 'reason' => 'Start over']);

    $rfq->refresh();
    expect($rfq->assignees)->toHaveCount(0);

    // Senior Operations reassigns both parts (to a single Sourcing member
    // this time) and it runs the whole way through again.
    $rfq->assignSourcingParts([1 => $people['sourcing']->id, 2 => $people['sourcing']->id]);
    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $people['dataEntry']);
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
        $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
        $rfq->refresh()->recordGmAssistantPart($part, $people['assistant'], 'Acme Ltd', null);
        $rfq->refresh()->approveGmPart($part, $people['gm']);
        $rfq->refresh()->closePart($part, userWithRole('Business Development'));
    }

    expect($rfq->refresh())
        ->stage->toBe('closed')
        ->status->toBe('Completed')
        ->reject_from_stage->toBeNull();
});
