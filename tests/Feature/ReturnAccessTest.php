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

/**
 * Takes $part of $rfq through Sourcing, Data Entry, its Finalize and Senior
 * Operations' and the Head's approval — on to GM Assistant.
 */
function partToGmAssistant(Rfq $rfq, int $part): void
{
    $rfq->refresh()->completeSourcingPart($part);
    $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart($part);
    $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
    $rfq->refresh()->approveHeadOfBdPart($part, userWithRole('Head of Business Development'));
}

/**
 * Takes $part of $rfq on through GM Assistant and the General Manager —
 * ready for Business Development to close.
 */
function partToBdClosing(Rfq $rfq, int $part): void
{
    partToGmAssistant($rfq, $part);
    $rfq->refresh()->recordGmAssistantPart($part, userWithRole('GM Assistant'), 'Acme Ltd, Colombo', 'Net 30');
    $rfq->refresh()->approveGmPart($part, userWithRole('General Manager'));
}

// ---- Sourcing and Data Entry → Senior Operations ----------------------------

it('lets Sourcing send their own part back to Senior Operations, freeing it to assign again', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ15001']), [1 => $riley, 2 => userWithRole('Sourcing')]);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-action="'.route('admin.rfqs.return-senior-ops', $rfq).'"', false)
        ->assertSee('Return to Senior Operations');

    test()->actingAs($riley)
        ->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Electrical, not plumbing — not mine', 'redirect_status' => 'Pending'])
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertSessionHas('status', 'Sent RFQ15001-P1 of P2 back to Senior Operations.');

    expect($rfq->refresh())
        ->reject_target_stage->toBe('operations')
        ->reject_from_stage->toBe('sourcing')
        ->rejected_by->toBe($riley->id)
        ->and($rfq->assigneeForPart(1))->toBeNull()
        ->and($rfq->assigneeForPart(2))->not->toBeNull()
        ->and(Rfq::seniorOpsReturnsCount())->toBe(1);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Electrical, not plumbing — not mine');
});

it('lets only the part\'s own Sourcing member send it back, and only while it\'s with them', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley, 2 => $sam]);

    test()->actingAs($sam)->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Not mine'])
        ->assertForbidden();

    // With Data Entry now — theirs to send back, not Riley's.
    $rfq->refresh()->completeSourcingPart(1);
    test()->actingAs($riley)->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Not mine'])
        ->assertForbidden();

    expect($rfq->refresh()->assigneeForPart(1))->not->toBeNull();
});

it('lets Data Entry send a part with them back to Senior Operations', function () {
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ15002']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);

    test()->actingAs($dataEntry)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-action="'.route('admin.rfqs.return-senior-ops', $rfq).'"', false);

    test()->actingAs($dataEntry)->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Quoted for the wrong building'])
        ->assertSessionHas('status', 'Sent RFQ15002-P1 of P2 back to Senior Operations.');

    expect($rfq->refresh())
        ->reject_from_stage->toBe('data_entry')
        ->rejected_by->toBe($dataEntry->id)
        ->and($rfq->assigneeForPart(1))->toBeNull();

    // Once Data Entry has sent a part to finalize, it's past them.
    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, $dataEntry);
    test()->actingAs($dataEntry)->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 2, 'reason' => 'Too late'])
        ->assertStatus(422);
});

// ---- GM Assistant -----------------------------------------------------------

it('lets GM Assistant send a part back to Senior Operations, Sourcing or Data Entry — and nowhere else', function () {
    $assistant = userWithRole('GM Assistant');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ15003']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    partToGmAssistant($rfq, 1);

    $html = test()->actingAs($assistant)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    expect($html)->toContain('data-action="'.route('admin.rfqs.reject-gm-assistant', $rfq).'"')
        ->toContain('<option value="operations" >')
        ->toContain('<option value="sourcing" >')
        ->toContain('<option value="data_entry" >')
        ->toContain('<option value="senior_ops_review" >')
        ->not->toContain('<option value="business_development" >')
        ->not->toContain('<option value="head_of_bd_review" >')
        ->not->toContain('<option value="gm_review" >');

    foreach (['business_development', 'head_of_bd_review', 'gm_review'] as $target) {
        test()->actingAs($assistant)->patch(route('admin.rfqs.reject-gm-assistant', $rfq), ['part' => 1, 'target_stage' => $target, 'reason' => 'No'])
            ->assertSessionHasErrorsIn('reject', 'target_stage');
    }

    test()->actingAs($assistant)->patch(route('admin.rfqs.reject-gm-assistant', $rfq), ['part' => 1, 'target_stage' => 'data_entry', 'reason' => 'Totals don\'t match the quotes'])
        ->assertSessionHas('status', 'Sent RFQ15003-P1 of P2 back to Data Entry.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot)
        ->data_entry_completed_at->toBeNull()
        ->head_of_bd_approved_at->toBeNull()
        ->and($rfq->reject_from_stage)->toBe('gm_assistant');
});

it('refuses GM Assistant\'s Reject to anyone else', function () {
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    partToGmAssistant($rfq, 1);

    test()->actingAs(userWithRole('General Manager'))
        ->patch(route('admin.rfqs.reject-gm-assistant', $rfq), ['target_stage' => 'sourcing', 'reason' => 'No'])
        ->assertForbidden();
});

// ---- Business Development, from Ready to Close ------------------------------

it('lets Business Development send what\'s ready to close back to the General Manager, part by part', function () {
    $bd = userWithRole('Business Development');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ15004']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    partToBdClosing($rfq, 1);
    partToBdClosing($rfq, 2);
    expect($rfq->refresh()->stage)->toBe('bd_closing');

    $html = test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']))->assertOk()->getContent();
    expect($html)->toContain('data-action="'.route('admin.rfqs.reject-bd', $rfq).'"')
        ->toContain('<option value="head_of_bd_review" >')
        ->toContain('<option value="gm_review" >')
        ->not->toContain('<option value="gm_assistant" >')
        ->not->toContain('<option value="sourcing" >');

    test()->actingAs($bd)->patch(route('admin.rfqs.reject-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'No'])
        ->assertSessionHasErrorsIn('reject', 'target_stage');

    test()->actingAs($bd)->patch(route('admin.rfqs.reject-bd', $rfq), ['part' => 1, 'target_stage' => 'gm_review', 'reason' => 'Client asked for a lower price'])
        ->assertSessionHas('status', 'Sent RFQ15004-P1 of P2 back to General Manager.');

    expect($rfq->refresh())
        ->stage->toBe('gm_review')
        ->reject_from_stage->toBe('bd_closing')
        ->and($rfq->assigneeForPart(1)->pivot->gm_approved_at)->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->gm_approved_at)->not->toBeNull()
        ->and(Rfq::gmReviewCount())->toBe(1)
        ->and(Rfq::bdClosingCount())->toBe(1);
});

it('lets Business Development send a whole RFQ ready to close back to the Head\'s Returns page', function () {
    $bd = userWithRole('Business Development');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ15005']), [1 => userWithRole('Sourcing')]);
    partToBdClosing($rfq, 1);

    test()->actingAs($bd)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee(route('admin.rfqs.reject-bd', $rfq));

    test()->actingAs($bd)->patch(route('admin.rfqs.reject-bd', $rfq), ['target_stage' => 'head_of_bd_review', 'reason' => 'Margin too thin'])
        ->assertSessionHas('status', 'Sent back to Head of Business Development.');

    expect($rfq->refresh())
        ->stage->toBe('head_of_bd_review')
        ->reject_target_stage->toBe('head_of_bd_review')
        ->and(Rfq::headOfBdReturnsCount())->toBe(1);
});

// ---- Senior Operations passes a freed part straight back -------------------

it('lets Senior Operations pass a part sent back to them straight back to its Sourcing member, in one click', function () {
    $ops = userWithRole('Senior Operations');
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ15006']), [1 => $riley, 2 => userWithRole('Sourcing')]);

    test()->actingAs($riley)->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Which site is this for?']);

    $returnsUrl = route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']);
    test()->actingAs($ops)->get($returnsUrl)->assertOk()
        ->assertSee('action="'.route('admin.rfqs.pass-back-sourcing', $rfq).'"', false)
        ->assertSee('Pass back to '.e($riley->name), false)
        // Assigning someone else is still there.
        ->assertSee('Assign Sourcing');

    test()->actingAs($ops)->from($returnsUrl)->patch(route('admin.rfqs.pass-back-sourcing', $rfq))
        ->assertRedirect($returnsUrl)
        ->assertSessionHas('status', "Passed RFQ15006-P1 of P2 back to {$riley->name}.");

    expect($rfq->refresh()->assigneeForPart(1)?->id)->toBe($riley->id)
        ->and($rfq->hasUnassignedParts())->toBeFalse()
        ->and(Rfq::seniorOpsReturnsCount())->toBe(0);

    // Back on Riley's list, the clock running again.
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ15006-P1 of P2</td>', false);
});

it('passes every part of an RFQ sent back whole to the member who had each', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ15007']), [1 => $riley, 2 => $sam]);
    $rfq->rejectToStage('operations', 'Re-check the split', userWithRole('Head of Business Development'));

    expect($rfq->refresh()->previousHoldersOfOpenParts())->toHaveKeys([1, 2]);

    test()->actingAs($ops)->patch(route('admin.rfqs.pass-back-sourcing', $rfq))
        ->assertSessionHas('status', "Passed RFQ15007-P1 & P2 of P2 back to {$riley->name} and {$sam->name}.");

    expect($rfq->refresh()->assigneeForPart(1)?->id)->toBe($riley->id)
        ->and($rfq->assigneeForPart(2)?->id)->toBe($sam->id);
});

it('offers no pass back for a part nobody has held, or whose member is no longer in Sourcing', function () {
    $ops = userWithRole('Senior Operations');
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    test()->actingAs($riley)->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Not mine']);

    $riley->removeRole('Sourcing');

    expect($rfq->refresh()->previousHoldersOfOpenParts())->toBe([]);
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertDontSee(route('admin.rfqs.pass-back-sourcing', $rfq));
    test()->actingAs($ops)->patch(route('admin.rfqs.pass-back-sourcing', $rfq))->assertStatus(422);

    test()->actingAs($ops)->patch(route('admin.rfqs.pass-back-sourcing', Rfq::factory()->create()))->assertStatus(422);
});

it('lets only Senior Operations and Admin pass a part back', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    test()->actingAs($riley)->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Not mine']);

    foreach (['Sourcing', 'Business Development', 'Data Entry'] as $role) {
        test()->actingAs(userWithRole($role))->patch(route('admin.rfqs.pass-back-sourcing', $rfq))->assertForbidden();
    }

    $ops = userWithRole('Senior Operations');
    test()->actingAs(userWithRole('Admin'))->patch(route('admin.rfqs.pass-back-sourcing', $rfq), ['acting_user_id' => $ops->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)?->id)->toBe($riley->id);
});
