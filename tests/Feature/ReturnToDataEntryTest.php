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
 * RFQ1001, split between the given Sourcing members, every part through
 * Sourcing and sent to finalize by Data Entry.
 */
function sentToFinalize(array $holders): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs']), $holders);
    $dataEntry = userWithRole('Data Entry');

    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry);
    }

    return $rfq->refresh();
}

function returnsToDataEntry($user, Rfq $rfq, array $payload)
{
    return test()->actingAs($user)->patch(route('admin.rfqs.return-data-entry', $rfq), $payload);
}

it('puts Return to Data Entry beside Finalize, only once Data Entry has sent the part to finalize', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $returnButton = 'data-action="'.route('admin.rfqs.return-data-entry', $rfq).'"';

    $rfq->completeSourcingPart(1);
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee($returnButton, false);

    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));

    foreach ([route('admin.rfqs.index', ['status' => 'Pending']), route('admin.rfqs.show', $rfq), route('admin.dashboard')] as $page) {
        test()->actingAs($riley)->get($page)->assertOk()
            ->assertSee($returnButton, false)
            ->assertSee('data-kind="return_data_entry"', false)
            ->assertSee('action="'.route('admin.rfqs.finalize', $rfq).'"', false)
            ->assertSee('id="completeModal"', false);
    }
});

it('sends the part back to Data Entry\'s queue with the reason, and posts it to the thread', function () {
    $riley = userWithRole('Sourcing');
    $rfq = sentToFinalize([1 => $riley]);
    expect($rfq->data_entry_completed_at)->not->toBeNull();

    returnsToDataEntry($riley, $rfq, ['part' => 1, 'reason' => 'Unit prices are from the old quote'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Sent RFQ1001 back to Data Entry.');

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($pivot->data_entry_completed_at)->toBeNull()
        ->and($pivot->completed_at)->not->toBeNull()
        ->and($pivot->progressState())->toBe('with_data_entry')
        ->and($rfq->partAwaitsFinalize(1))->toBeFalse()
        ->and($rfq->data_entry_completed_at)->toBeNull()
        ->and(Rfq::awaitingFinalizeCount($riley->id))->toBe(0);

    $comment = $rfq->comments()->sole();
    expect($comment->user_id)->toBe($riley->id)
        ->and($comment->action)->toBe('returned_to_data_entry')
        ->and($comment->body)->toBe('Unit prices are from the old quote');

    // Back with Data Entry, to start on again — Send to Finalize after.
    expect($pivot->data_entry_started_at)->toBeNull();

    test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('RFQ1001')
        ->assertSee('Returned by Sourcing: Unit prices are from the old quote')
        ->assertSee('action="'.route('admin.rfqs.start-data-entry', $rfq).'"', false)
        ->assertDontSee('data-action="'.route('admin.rfqs.complete-data-entry', $rfq).'"', false);
});

it('can go round again — Data Entry sends it to finalize, and it can be finalized on to review', function () {
    $riley = userWithRole('Sourcing');
    $rfq = sentToFinalize([1 => $riley]);
    returnsToDataEntry($riley, $rfq, ['part' => 1, 'reason' => 'Wrong currency']);
    startDataEntryOn($rfq, 1);

    test()->actingAs(userWithRole('Data Entry'))
        ->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'comment' => 'Fixed the currency'])
        ->assertSessionHasNoErrors();

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($pivot->data_entry_returned_at)->toBeNull()
        ->and($pivot->data_entry_return_reason)->toBeNull()
        ->and($rfq->partAwaitsFinalize(1))->toBeTrue();

    test()->actingAs($riley)->patch(route('admin.rfqs.finalize', $rfq), ['part' => 1])->assertSessionHasNoErrors();

    expect($rfq->refresh()->stage)->toBe('senior_ops_review');
});

it('only sends back the one part on a split', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = sentToFinalize([1 => $riley, 2 => $sam]);

    returnsToDataEntry($sam, $rfq, ['part' => 2, 'reason' => 'Missing the delivery charge'])->assertSessionHasNoErrors();

    expect($rfq->refresh()->partAwaitsFinalize(1))->toBeTrue()
        ->and($rfq->partAwaitsFinalize(2))->toBeFalse()
        ->and($rfq->comments()->sole()->meta['label'])->toBe('RFQ1001-P2 of P2');
});

it('asks for a reason, changing nothing without one', function () {
    $riley = userWithRole('Sourcing');
    $rfq = sentToFinalize([1 => $riley]);

    returnsToDataEntry($riley, $rfq, ['part' => 1, 'reason' => ''])
        ->assertSessionHas('error', 'Add a reason to send this part back to Data Entry.');

    expect($rfq->refresh()->partAwaitsFinalize(1))->toBeTrue()
        ->and($rfq->comments()->count())->toBe(0);
});

it('lets only the part\'s own member, or Admin as them, send it back', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = sentToFinalize([1 => $riley, 2 => $sam]);

    foreach ([$sam, userWithRole('Data Entry'), userWithRole('Senior Operations')] as $someoneElse) {
        returnsToDataEntry($someoneElse, $rfq, ['part' => 1, 'reason' => 'Not mine'])->assertForbidden();
    }

    expect($rfq->refresh()->partAwaitsFinalize(1))->toBeTrue();

    returnsToDataEntry(userWithRole('Admin'), $rfq, ['part' => 1, 'reason' => 'On Riley\'s behalf', 'acting_user_id' => $riley->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->partAwaitsFinalize(1))->toBeFalse()
        ->and($rfq->comments()->sole()->user_id)->toBe($riley->id);
});

it('refuses a part that isn\'t waiting to be finalized', function () {
    $riley = userWithRole('Sourcing');
    $rfq = sentToFinalize([1 => $riley]);
    $rfq->finalizePart(1);

    returnsToDataEntry($riley, $rfq, ['part' => 1, 'reason' => 'Too late'])->assertStatus(422);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->finalized_at)->not->toBeNull();
});
