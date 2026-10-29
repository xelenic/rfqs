<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * $member says why $part of $rfq is still pending, from their Pending list.
 */
function saysWhyPending(User $member, Rfq $rfq, string $reason, int $part = 1): TestResponse
{
    return test()->actingAs($member)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.pending-reason', $rfq), ['part' => $part, 'pending_reason' => $reason]);
}

/**
 * The popup behind a Pending status — its <template> — on $html.
 */
function pendingCard(string $html, string $id): string
{
    return Str::betweenFirst($html, '<template id="'.$id.'">', '</template>');
}

it('lets a Sourcing member say why their part is still pending, from their Pending list', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7001', 'subject' => 'Valves']), [1 => $riley]);

    $row = fn () => Str::betweenFirst(test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent(), '<td class="text-nowrap">RFQ7001</td>', '</tr>');

    expect($row())->toContain('data-action="'.route('admin.rfqs.pending-reason', $rfq).'"')
        ->toContain('data-reason=""')
        ->not->toContain('data-pending-reason-note');

    saysWhyPending($riley, $rfq, '  Waiting for the supplier\'s quote  ')
        ->assertRedirect(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertSessionHas('status', 'Saved why RFQ7001 is pending — it shows when someone hovers its Pending status.');

    $pivot = $rfq->refresh()->assigneeForPart(1)->pivot;

    expect($pivot->pendingReason())->toBe('Waiting for the supplier\'s quote')
        ->and($pivot->pending_reason_by)->toBe($riley->id)
        ->and($pivot->pending_reason_at)->not->toBeNull()
        // On the RFQ's thread, as theirs.
        ->and($rfq->comments->sole())->toMatchArray(['action' => 'pending_reason', 'body' => 'Waiting for the supplier\'s quote', 'user_id' => $riley->id])
        // Shown under the row, and filled in to change it.
        ->and($row())->toContain('data-pending-reason-note')
        ->toContain('Pending: Waiting for the supplier&#039;s quote')
        ->toContain('data-reason="Waiting for the supplier&#039;s quote"');

    // The same reason sent again (a double click) is saved once.
    saysWhyPending($riley, $rfq, 'Waiting for the supplier\'s quote')->assertSessionHas('status');
    expect($rfq->comments()->count())->toBe(1);

    // A new one replaces it; the thread keeps both.
    saysWhyPending($riley, $rfq, 'Quote in, waiting on the delivery date');
    expect($rfq->refresh()->assigneeForPart(1)->pivot->pendingReason())->toBe('Quote in, waiting on the delivery date')
        ->and($rfq->comments()->count())->toBe(2);
});

it('asks for a reason', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    saysWhyPending($riley, $rfq, '   ')->assertSessionHas('error', 'Say why it\'s still pending.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->pending_reason)->toBeNull()
        ->and($rfq->comments()->count())->toBe(0);
});

it('shows Senior Operations why it\'s pending — the reason, who said it and their role — on hovering its status', function () {
    $ops = userWithRole('Senior Operations');
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $sam = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7002']), [1 => $riley, 2 => $sam]);

    saysWhyPending($riley, $rfq, 'Waiting for the customer\'s drawings');

    // All RFQs: the Pending status hovers with the reason.
    $allRfqs = test()->actingAs($ops)->get(route('admin.rfqs.index'))->assertOk()->getContent();
    $card = pendingCard($allRfqs, 'pending-reason-'.$rfq->id);

    expect($allRfqs)->toContain('data-hover-card="pending-reason-'.$rfq->id.'"')
        ->and($card)->toContain('Why it\'s pending')
        ->toContain('RFQ7002-P1 of P2')
        ->toContain('Waiting for the customer&#039;s drawings')
        ->toContain('<strong>Riley Chen</strong>')
        ->toContain('<span class="badge badge-soft-secondary">Sourcing</span>')
        // Only the part they said it for.
        ->not->toContain('RFQ7002-P2 of P2');

    // The Assigned tab: on that part's own line, not the other's.
    [, $assignedTab] = operationsTabs(['tab' => 'assigned']);
    $partLine = fn (int $part) => Str::betweenFirst($assignedTab, '<span class="rfq-part-name">RFQ7002-P'.$part.' of P2</span>', '</tr>');

    expect($partLine(1))->toContain('data-hover-card="pending-reason-'.$rfq->id.'-p1"')
        ->and(pendingCard($partLine(1), 'pending-reason-'.$rfq->id.'-p1'))->toContain('Waiting for the customer&#039;s drawings')
        ->and($partLine(2))->not->toContain('data-hover-card');

    // And the RFQ's own page.
    expect(pendingCard(test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent(), 'pending-reason-'.$rfq->id))
        ->toContain('Riley Chen');
});

it('keeps an RFQ with no reason to its plain Pending status', function () {
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7003']), [1 => userWithRole('Sourcing')]);

    expect(test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index'))->assertOk()->getContent())
        ->not->toContain('pending-reason-'.$rfq->id);
});

it('lets only the part\'s own member say why, and only while it\'s pending with them', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7004']), [1 => $riley, 2 => userWithRole('Sourcing')]);

    // Not someone else's part, nor someone outside Sourcing.
    saysWhyPending($riley, $rfq, 'Not mine', part: 2)->assertForbidden();
    saysWhyPending(userWithRole('Senior Operations'), $rfq, 'Not theirs')->assertForbidden();

    // Assigned to Data Entry: off their Pending list — the reason with it.
    saysWhyPending($riley, $rfq, 'Waiting for the supplier');
    $rfq->refresh()->completeSourcingPart(1);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->pending_reason)->toBeNull()
        ->and($rfq->pendingReasons())->toBeEmpty();
    saysWhyPending($riley, $rfq, 'Too late')->assertForbidden();

    // Sent to them to finalize: theirs to say again, till they've finalized it.
    $rfq->completeDataEntryPart(1, userWithRole('Data Entry'));
    saysWhyPending($riley, $rfq->refresh(), 'Checking the line items with the supplier')->assertSessionHas('status');
    expect($rfq->refresh()->pendingReasons()->pluck('reason')->all())->toBe(['Checking the line items with the supplier']);

    $rfq->finalizePart(1);
    expect($rfq->refresh()->pendingReasons())->toBeEmpty();
});

it('clears the reason when the part is given to someone else, or sent back to Sourcing', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    saysWhyPending($riley, $rfq, 'Waiting for the supplier');
    $rfq->refresh()->replaceSourcingPart(1, $sam, userWithRole('Senior Operations'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->pending_reason)->toBeNull();

    saysWhyPending($sam, $rfq, 'Starting over with a new supplier');
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->returnSourcingPart(1, 'Wrong part numbers', userWithRole('Data Entry'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->pending_reason)->toBeNull();
});

it('hides who said it from Business Development, and the other members\' parts from Sourcing', function () {
    $bd = userWithRole('Business Development');
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $sam = tap(userWithRole('Sourcing'))->update(['name' => 'Sam Rivera']);
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7005', 'created_by' => $bd->id]), [1 => $riley, 2 => $sam]);

    saysWhyPending($riley, $rfq, 'Waiting for the customer\'s drawings');
    saysWhyPending($sam, $rfq, 'Waiting for the supplier', part: 2);

    // Business Development: the reasons and the role, not the names.
    $bdCard = pendingCard(test()->actingAs($bd)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent(), 'pending-reason-'.$rfq->id);

    expect($bdCard)->toContain('Waiting for the customer&#039;s drawings')
        ->toContain('Waiting for the supplier')
        ->toContain('<span class="badge badge-soft-secondary">Sourcing</span>')
        ->not->toContain('Riley Chen')
        ->not->toContain('Sam Rivera');

    // Sourcing: their own part's alone.
    $rileyCard = pendingCard(test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent(), 'pending-reason-'.$rfq->id);

    expect($rileyCard)->toContain('Waiting for the customer&#039;s drawings')
        ->not->toContain('Waiting for the supplier')
        ->not->toContain('Sam Rivera');
});

it('offers Pending reason on the RFQ page for the viewer\'s own part', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('data-action="'.route('admin.rfqs.pending-reason', $rfq).'"', false)
        ->assertSee('id="pendingReasonModal"', false);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertDontSee('id="pendingReasonModal"', false);
});
