<?php

use App\Models\Rfq;
use App\Models\RfqStep;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * $by puts $part of $rfq on hold, cancels it, or sets it going again.
 */
function changeRfqPartStatus(User $by, Rfq $rfq, int $part, string $status, ?string $reason = null): TestResponse
{
    return test()->actingAs($by)->patch(route('admin.rfqs.change-status', $rfq), array_filter([
        'part' => $part,
        'status' => $status,
        'reason' => $reason,
    ]));
}

/**
 * Takes $part of $rfq through Sourcing, Data Entry and its Finalize.
 */
function finalizeRfqPart(Rfq $rfq, int $part): void
{
    $rfq->refresh()->completeSourcingPart($part);
    $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart($part);
}

/**
 * An RFQ split in two, a Sourcing member on each part.
 */
function splitInTwo(array $attributes = []): Rfq
{
    return splitAmong(Rfq::factory()->create($attributes), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
}

it('gives each part of a split on the Assigned tab its own Status options, and the RFQ\'s own row none', function () {
    $split = splitInTwo(['rfq_number' => 'RFQ13001']);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ13002', 'subject' => 'Lift motor']), [1 => userWithRole('Sourcing')]);

    [, $assignedTab] = operationsTabs(['tab' => 'assigned']);

    // Each part's Put on hold and Cancel (its Reassign has the same label).
    $statusOptionsFor = fn (string $label) => preg_match_all('/data-status="[^"]+"[^>]*data-label="'.preg_quote($label, '/').'"/', $assignedTab);

    expect($statusOptionsFor('RFQ13001-P1 of P2'))->toBe(2)
        ->and($statusOptionsFor('RFQ13001-P2 of P2'))->toBe(2)
        ->and($assignedTab)->toContain('Put part on hold')
        ->and($assignedTab)->toContain('Cancel part')
        // The split as a whole only through its parts…
        ->and($assignedTab)->not->toContain('data-label="'.$split->rfq_number.' — '.e($split->subject).'"')
        // …the one kept whole through its one task line, the RFQ with it.
        ->and(substr_count($assignedTab, 'data-label="RFQ13002 — Lift motor"'))->toBe(2);

    // No group's own row has any.
    preg_match_all('/<tr class="rfq-group-head">.*?<\/tr>/s', $assignedTab, $rows);
    expect($rows[0])->toHaveCount(2)
        ->each->not->toContain('js-rfq-status');
});

it('puts one part on hold — out of its member\'s queue, its time stopped — while the rest carries on', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ13001']), [1 => $riley, 2 => $sam]);

    changeRfqPartStatus($ops, $rfq, 2, 'On Hold', 'Waiting on the client for the annex drawings')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'RFQ13001-P2 of P2 is on hold.');

    expect($rfq->refresh()->assigneeForPart(2)->pivot)
        ->status->toBe('On Hold')
        ->status_reason->toBe('Waiting on the client for the annex drawings')
        ->status_changed_by->toBe($ops->id)
        ->and($rfq->status)->toBe('Pending')
        ->and($rfq->assigneeForPart(1)->pivot->status)->toBeNull()
        ->and($rfq->comments->last())->toMatchArray(['action' => 'put_on_hold', 'body' => 'Waiting on the client for the annex drawings'])
        ->and($rfq->comments->last()->meta)->toMatchArray(['part' => 2]);

    // Its time stops; part 1's runs on.
    expect(RfqStep::query()->whereNull('ended_at')->pluck('part_number')->all())->toBe([1]);

    // Off Sam's list, and nothing for them to do on it; Riley's is as it was.
    test()->actingAs($sam)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee('<td class="text-nowrap">RFQ13001-P2 of P2</td>', false);
    test()->actingAs($sam)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 2, 'comment' => 'Done'])
        ->assertStatus(422);
    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ13001-P1 of P2</td>', false);

    // On Senior Operations' On Hold page, with a Resume for the part.
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'On Hold']))->assertOk()
        ->assertSee('RFQ13001-P2 of P2 on hold: Waiting on the client for the annex drawings')
        ->assertSee('Resume P2');

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('RFQ13001-P2 of P2')
        ->assertSee('Resume P2');

    expect(Rfq::queueCounts())->toMatchArray(['on_hold' => 1, 'cancelled' => 0, 'sourcing_pending' => 1]);
});

it('keeps the RFQ from moving on past a part on hold, until it\'s resumed', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitInTwo();
    finalizeRfqPart($rfq, 1);
    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, userWithRole('Data Entry'));

    changeRfqPartStatus($ops, $rfq, 2, 'On Hold', 'Paused');

    expect($rfq->refresh()->partAwaitsFinalize(2))->toBeFalse()
        ->and(Rfq::awaitingFinalizeCount())->toBe(0);

    changeRfqPartStatus($ops, $rfq, 2, 'Pending')
        ->assertSessionHas('status', "{$rfq->partNumberLabel(2)} is back in progress.");
    $rfq->refresh()->finalizePart(2);

    expect($rfq->refresh()->stage)->toBe('senior_ops_review')
        ->and($rfq->comments->last()->action)->toBe('resumed');
});

it('refuses approving the RFQ as a whole while a part is on hold, leaving the other parts to approve one by one', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitInTwo(['rfq_number' => 'RFQ13003']);
    finalizeRfqPart($rfq, 1);
    finalizeRfqPart($rfq, 2);
    expect($rfq->refresh()->stage)->toBe('senior_ops_review');

    changeRfqPartStatus($ops, $rfq, 2, 'On Hold', 'Paused');

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertDontSee(route('admin.rfqs.complete-senior-ops-review', $rfq));
    test()->actingAs($ops)->patch(route('admin.rfqs.complete-senior-ops-review', $rfq))
        ->assertStatus(422);

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('RFQ13003-P1 of P2')
        ->assertDontSee('RFQ13003-P2 of P2');
    test()->actingAs($ops)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 2])
        ->assertStatus(422);
    test()->actingAs($ops)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1])
        ->assertSessionHasNoErrors();

    // Part 2 still to come: the RFQ stays at the review, off its page meanwhile.
    expect($rfq->refresh()->stage)->toBe('senior_ops_review')
        ->and(Rfq::seniorOpsReviewCount())->toBe(0);
});

it('lets the rest of the RFQ carry on without a cancelled part — through to closing', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitInTwo(['rfq_number' => 'RFQ13004']);
    finalizeRfqPart($rfq, 1);

    // Part 2's still with Sourcing: once it's cancelled, part 1 is all there is.
    changeRfqPartStatus($ops, $rfq, 2, 'Cancelled', 'Client dropped the second building')
        ->assertSessionHas('status', 'RFQ13004-P2 of P2 is cancelled — the rest of the RFQ carries on without it.');

    expect($rfq->refresh())
        ->stage->toBe('senior_ops_review')
        ->sourcing_completed_at->not->toBeNull()
        ->data_entry_completed_at->not->toBeNull()
        ->finalized_at->not->toBeNull()
        ->and(RfqStep::query()->whereNull('ended_at')->count())->toBe(0)
        ->and(Rfq::queueCounts()['cancelled'])->toBe(1);

    $rfq->approveSeniorOpsPart(1, $ops);
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'), 'Client: ACME', null);
    $rfq->refresh()->approveGmPart(1, userWithRole('General Manager'));
    $rfq->refresh()->closePart(1, userWithRole('Business Development'), 'REF-1');

    expect($rfq->refresh())
        ->status->toBe('Completed')
        ->stage->toBe('closed')
        ->and($rfq->assigneeForPart(2)->pivot->bd_closed_at)->toBeNull();
});

it('moves the RFQ straight on to where its other parts are, once the part it waited on is cancelled', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitInTwo();
    finalizeRfqPart($rfq, 1);
    $rfq->refresh()->approveSeniorOpsPart(1, $ops);
    $rfq->refresh()->approveHeadOfBdPart(1, userWithRole('Head of Business Development'));
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'), 'Client: ACME', null);
    $rfq->refresh()->approveGmPart(1, $gm = userWithRole('General Manager'));
    expect($rfq->refresh()->stage)->toBeNull();

    changeRfqPartStatus($ops, $rfq, 2, 'Cancelled', 'Not needed');

    expect($rfq->refresh())
        ->stage->toBe('bd_closing')
        ->senior_ops_reviewed_by->toBe($ops->id)
        ->gm_approved_by->toBe($gm->id)
        ->and(Rfq::bdClosingCount())->toBe(1);
});

it('puts the RFQ back to where a reopened part leaves it', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitInTwo();
    finalizeRfqPart($rfq, 1);
    changeRfqPartStatus($ops, $rfq, 2, 'Cancelled', 'Not needed');
    expect($rfq->refresh()->stage)->toBe('senior_ops_review');

    changeRfqPartStatus($ops, $rfq, 2, 'Pending')
        ->assertSessionHas('status', "{$rfq->partNumberLabel(2)} is back in progress.");

    expect($rfq->refresh())
        ->stage->toBeNull()
        ->sourcing_completed_at->toBeNull()
        ->finalized_at->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->status)->toBeNull()
        ->and($rfq->comments->last()->action)->toBe('reopened')
        ->and(RfqStep::query()->whereNull('ended_at')->where('part_number', 2)->value('step'))->toBe('sourcing');
});

it('refuses a part change that isn\'t one', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitInTwo();

    changeRfqPartStatus($ops, $rfq, 1, 'On Hold')->assertSessionHasErrorsIn('rfq_status', ['reason' => 'Say why.']);
    changeRfqPartStatus($ops, $rfq, 1, 'Pending')->assertSessionHasErrorsIn('rfq_status', ['status']);
    changeRfqPartStatus($ops, $rfq, 3, 'On Hold', 'x')->assertNotFound();

    // The last part going ahead can't be cancelled — that's cancelling the RFQ.
    changeRfqPartStatus($ops, $rfq, 2, 'Cancelled', 'Gone');
    changeRfqPartStatus($ops, $rfq, 1, 'Cancelled', 'Gone too')
        ->assertSessionHasErrorsIn('rfq_status', ['status' => 'It\'s the last part still going ahead — cancel the whole RFQ instead.']);

    // A cancelled part can only be reopened.
    changeRfqPartStatus($ops, $rfq, 2, 'On Hold', 'x')->assertSessionHasErrorsIn('rfq_status', ['status']);

    // Nor while the RFQ itself is stopped.
    test()->actingAs($ops)->patch(route('admin.rfqs.change-status', $rfq), ['status' => 'On Hold', 'reason' => 'All paused']);
    changeRfqPartStatus($ops, $rfq, 1, 'On Hold', 'x')->assertStatus(422);

    // An RFQ kept whole has no parts to stop on their own.
    $whole = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    changeRfqPartStatus($ops, $whole, 1, 'On Hold', 'x')->assertNotFound();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->status)->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->status)->toBe('Cancelled')
        ->and($whole->refresh()->assigneeForPart(1)->pivot->status)->toBeNull();
});

it('lets only Senior Operations and Admin stop a part', function () {
    $rfq = splitInTwo();

    foreach (['Business Development', 'Sourcing', 'Data Entry', 'General Manager'] as $role) {
        changeRfqPartStatus(userWithRole($role), $rfq, 1, 'On Hold', 'x')->assertForbidden();
    }

    $ops = userWithRole('Senior Operations');
    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.rfqs.change-status', $rfq), ['part' => 1, 'status' => 'On Hold', 'reason' => 'x', 'acting_user_id' => $ops->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->status_changed_by)->toBe($ops->id);
});

it('carries a part\'s round on once it\'s resumed — its countdown too', function () {
    $ops = userWithRole('Senior Operations');
    $colombo = fn (string $time) => CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);

    test()->travelTo($colombo('2026-10-05 09:00'));
    $rfq = splitInTwo(['priority_level' => 'Urgent']);

    test()->travelTo($colombo('2026-10-05 10:00'));
    changeRfqPartStatus($ops, $rfq, 2, 'On Hold', 'Waiting on drawings');
    expect($rfq->refresh()->sourcingCountdown(2))->toBeNull();

    test()->travelTo($colombo('2026-10-05 15:00'));
    changeRfqPartStatus($ops, $rfq, 2, 'Pending');

    test()->travelTo($colombo('2026-10-05 16:00'));

    // 09:00–10:00 and 15:00–16:00 for part 2: one round, not two.
    expect($rfq->refresh()->sourcingCountdown(2)['remaining'])->toBe(4 * 3600 - 2 * 3600)
        ->and($rfq->timeSpent()['roles']['Sourcing'])->toMatchArray(['rounds' => 2, 'reworks' => 0]);
});
