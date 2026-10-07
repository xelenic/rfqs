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
 * Takes $part of $rfq all the way through to closed by Business Development.
 */
function closePartThroughEveryStep(Rfq $rfq, int $part): void
{
    $rfq->refresh()->completeSourcingPart($part);
    $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart($part);
    $rfq->refresh()->approveSeniorOpsPart($part, userWithRole('Senior Operations'));
    $rfq->refresh()->approveHeadOfBdPart($part, userWithRole('Head of Business Development'));
    $rfq->refresh()->recordGmAssistantPart($part, userWithRole('GM Assistant'), 'Acme Ltd', 'Net 30');
    $rfq->refresh()->approveGmPart($part, userWithRole('General Manager'));
    $rfq->refresh()->closePart($part, userWithRole('Business Development'), 'REF-'.$part);
}

it('shows a Sourcing member only the closed RFQs — and closed parts — they were assigned', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];

    // A split: Riley's part 1 closed, Sam's part 2 still going.
    $split = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ17001']), [1 => $riley, 2 => $sam]);
    closePartThroughEveryStep($split, 1);

    // Closed whole, Sam's alone.
    $samsOwn = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ17002']), [1 => $sam]);
    closePartThroughEveryStep($samsOwn, 1);

    // Closed, and nobody of theirs on it.
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ17003']), [1 => userWithRole('Sourcing')]);
    closePartThroughEveryStep(Rfq::query()->where('rfq_number', 'RFQ17003')->sole(), 1);

    $closedUrl = route('admin.rfqs.index', ['status' => 'Completed']);

    test()->actingAs($riley)->get($closedUrl)->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ17001-P1 of P2</td>', false)
        ->assertDontSee('RFQ17001-P2 of P2')
        ->assertDontSee('RFQ17002')
        ->assertDontSee('RFQ17003');

    test()->actingAs($sam)->get($closedUrl)->assertOk()
        ->assertSee('RFQ17002')
        // Their part of the split isn't closed yet.
        ->assertDontSee('RFQ17001')
        ->assertDontSee('RFQ17003');

    // Once the split's closed whole, it's Sam's too — their own part of it.
    closePartThroughEveryStep($split, 2);
    test()->actingAs($sam)->get($closedUrl)->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ17001-P2 of P2</td>', false)
        ->assertDontSee('RFQ17001-P1 of P2');
});

it('leaves everyone else\'s Closed RFQs, and Admin\'s view of Sourcing\'s, company-wide', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ17004']), [1 => $riley]);
    closePartThroughEveryStep($rfq, 1);

    foreach (['Business Development', 'Senior Operations', 'Data Entry', 'General Manager'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.rfqs.index', ['status' => 'Completed']))->assertOk()
            ->assertSee('RFQ17004');
    }

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['status' => 'Completed', 'role' => 'sourcing']))->assertOk()
        ->assertSee('RFQ17004');

    test()->actingAs(userWithRole('Sourcing'))->get(route('admin.rfqs.index', ['status' => 'Completed']))->assertOk()
        ->assertDontSee('RFQ17004')
        ->assertSee('No completed RFQs found.');
});
