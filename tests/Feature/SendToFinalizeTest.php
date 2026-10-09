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
 * RFQ1001, split between the given Sourcing members, every part completed
 * by Sourcing and waiting on Data Entry.
 */
function withDataEntry(array $holders): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs']), $holders);

    // Data Entry has started on each — Send to Finalize comes after.
    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        startDataEntryOn($rfq, $part);
    }

    return $rfq->refresh();
}

function sendToFinalize(Rfq $rfq, int $part): void
{
    test()->actingAs(userWithRole('Data Entry'))
        ->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => $part, 'comment' => 'Prices entered'])
        ->assertSessionHasNoErrors();
}

it('labels Data Entry\'s button Send to Finalize on Ready for Data Entry', function () {
    withDataEntry([1 => userWithRole('Sourcing')]);

    $html = test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    // Every button there that opens the prompt is Data Entry's (or its Return
    // to Sourcing) — no Sourcing-style Mark Complete.
    expect($html)->toContain('<i class="bi bi-send-check"></i> Send to Finalize')
        ->toContain('data-kind="data_entry"')
        ->not->toContain('data-kind="sourcing"');
});

it('sends the part back to its Sourcing member to finalize, not on to Senior Operations\' review', function () {
    $riley = userWithRole('Sourcing');
    $rfq = withDataEntry([1 => $riley]);

    sendToFinalize($rfq, 1);

    expect($rfq->refresh())
        ->stage->toBeNull()
        ->and($rfq->partAwaitsFinalize(1))->toBeTrue()
        ->and(Rfq::seniorOpsReviewCount())->toBe(0)
        ->and(Rfq::awaitingFinalizeCount($riley->id))->toBe(1);

    test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertDontSee('RFQ1001');

    // On Riley's own Pending RFQs, with a Finalize — and counted on the sidebar.
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Sent to finalize by Data Entry')
        ->assertSee('action="'.route('admin.rfqs.finalize', $rfq).'"', false)
        ->assertSee('title="1 to complete or finalize"', false);
});

it('shows Finalize only once Data Entry has sent the part to finalize', function () {
    $riley = userWithRole('Sourcing');
    $rfq = withDataEntry([1 => $riley]);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee('action="'.route('admin.rfqs.finalize', $rfq).'"', false);
    test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertDontSee('action="'.route('admin.rfqs.finalize', $rfq).'"', false);

    test()->actingAs($riley)
        ->patch(route('admin.rfqs.finalize', $rfq), ['part' => 1])
        ->assertStatus(422);

    sendToFinalize($rfq, 1);

    test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('action="'.route('admin.rfqs.finalize', $rfq).'"', false);
});

it('moves the part on to Senior Operations\' review when its member finalizes it', function () {
    $riley = userWithRole('Sourcing');
    $rfq = withDataEntry([1 => $riley]);
    sendToFinalize($rfq, 1);

    test()->actingAs($riley)
        ->patch(route('admin.rfqs.finalize', $rfq), ['part' => 1])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Finalized RFQ1001 — sent to Senior Operations\' review.');

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;

    expect($rfq)
        ->stage->toBe('senior_ops_review')
        ->finalized_by->toBe($riley->id)
        ->and($pivot->finalized_by)->toBe($riley->id)
        ->and($pivot->progressState())->toBe('finalized')
        ->and($rfq->partAwaitsSeniorOpsReview(1))->toBeTrue()
        ->and(Rfq::seniorOpsReviewCount())->toBe(1);

    test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('RFQ1001');
});

it('sends each part of a split on to review as its own member finalizes it', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = withDataEntry([1 => $riley, 2 => $sam]);
    sendToFinalize($rfq, 1);
    sendToFinalize($rfq, 2);

    test()->actingAs($riley)->patch(route('admin.rfqs.finalize', $rfq), ['part' => 1])->assertSessionHasNoErrors();

    expect($rfq->refresh()->stage)->toBeNull()
        ->and($rfq->partAwaitsSeniorOpsReview(1))->toBeTrue()
        ->and($rfq->partAwaitsSeniorOpsReview(2))->toBeFalse();

    test()->actingAs($sam)->patch(route('admin.rfqs.finalize', $rfq), ['part' => 2])->assertSessionHasNoErrors();

    expect($rfq->refresh()->stage)->toBe('senior_ops_review')
        ->and($rfq->finalized_by)->toBe($sam->id);
});

it('lets only the part\'s own member, or Admin as them, finalize it', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = withDataEntry([1 => $riley, 2 => $sam]);
    sendToFinalize($rfq, 1);

    foreach ([$sam, userWithRole('Data Entry'), userWithRole('Senior Operations'), userWithRole('Business Development')] as $someoneElse) {
        test()->actingAs($someoneElse)->patch(route('admin.rfqs.finalize', $rfq), ['part' => 1])->assertForbidden();
    }

    expect($rfq->refresh()->partAwaitsFinalize(1))->toBeTrue();

    test()->actingAs(userWithRole('Admin'))->patch(route('admin.rfqs.finalize', $rfq), ['part' => 1])->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->finalized_by)->toBe($riley->id);
});

it('needs finalizing again after a reviewer sends it back to Data Entry', function () {
    $riley = userWithRole('Sourcing');
    $ops = userWithRole('Senior Operations');
    $rfq = withDataEntry([1 => $riley]);
    sendToFinalize($rfq, 1);
    $rfq->refresh()->finalizePart(1);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'data_entry', 'reason' => 'Totals are off'])
        ->assertSessionHasNoErrors();

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($pivot->data_entry_completed_at)->toBeNull()
        ->and($pivot->finalized_at)->toBeNull()
        ->and($rfq->finalized_at)->toBeNull()
        // Back with Data Entry, to start on again.
        ->and($pivot->data_entry_started_at)->toBeNull();

    startDataEntryOn($rfq, 1);
    sendToFinalize($rfq, 1);
    expect($rfq->refresh()->stage)->toBeNull()
        ->and($rfq->partAwaitsFinalize(1))->toBeTrue();
});

it('shows the Finalize step on the RFQ\'s page and timeline', function () {
    $riley = userWithRole('Sourcing');
    $rfq = withDataEntry([1 => $riley]);
    sendToFinalize($rfq, 1);

    $admin = userWithRole('Admin');
    test()->actingAs($admin)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('id="step-tab-finalize"', false)
        ->assertSee('Awaiting Finalize')
        ->assertSee('Sent to Finalize by Data Entry');

    $rfq->refresh()->finalizePart(1);

    test()->actingAs($admin)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('Finalized by Sourcing');
});
