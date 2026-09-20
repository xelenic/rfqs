<?php

use App\Models\Rfq;
use App\Models\RfqAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // The list page looks up its Sourcing and Senior Operations members.
    Role::findOrCreate('Sourcing');
    Role::findOrCreate('Senior Operations');
});

it('groups each assigned RFQ with a line per part, naming who holds it and where it stands', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $dataEntry = userWithRole('Data Entry');

    // Split three ways: Riley finishes P1, Sam's P2 is sent back, P3 is still going.
    $split = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Lobby lighting']), [1 => $riley, 2 => $sam, 3 => $riley]);
    $split->completeSourcingPart(1);
    $split->refresh()->returnSourcingPart(2, 'Missing prices for items 4-7', $dataEntry);

    // Kept whole, all the way through Data Entry.
    $whole = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1002', 'subject' => 'Chiller contract']), [1 => $sam]);
    $whole->completeSourcingPart(1);
    $whole->refresh()->completeDataEntryPart(1, $dataEntry);

    [, $assigned] = operationsTabs();

    // One group per RFQ.
    expect(substr_count($assigned, '<tbody class="rfq-group" data-rfq-id="'))->toBe(2);

    foreach (['RFQ1001-P1 of P3', 'RFQ1001-P2 of P3', 'RFQ1001-P3 of P3', 'Whole task'] as $line) {
        expect($assigned)->toContain($line);
    }

    // Who, with their details, and where each part stands.
    expect($assigned)
        ->toContain('<span class="rfq-part-user">'.e($riley->name).'</span>')
        ->toContain('<span class="rfq-part-user">'.e($sam->name).'</span>')
        ->toContain(e($riley->email))
        ->toContain('With Data Entry')
        ->toContain('Returned')
        ->toContain('Missing prices for items 4-7')
        ->toContain('In progress')
        ->toContain('Data Entry done')
        // And how far along each RFQ is.
        ->toContain('1 of 3 parts done by Sourcing')
        ->toContain('1 of 1 task done by Sourcing');
});

it('keeps a partly assigned RFQ out of the Assigned tab, and the Unassigned tab ungrouped', function () {
    $sam = userWithRole('Sourcing');

    splitAmong(Rfq::factory()->create(['subject' => 'Fully assigned job']), [1 => $sam, 2 => $sam]);

    $partly = Rfq::factory()->create(['rfq_number' => 'RFQ1009', 'subject' => 'Partly assigned job']);
    $partly->planSplit(3);
    $partly->assignSourcingParts([1 => $sam->id]);

    [$unassigned, $assigned] = operationsTabs();

    expect($assigned)->toContain('Fully assigned job')
        ->not->toContain('Partly assigned job')
        ->not->toContain('RFQ1009-P1 of P3')
        ->and($unassigned)->toContain('Partly assigned job')
        ->not->toContain('rfq-part-row')
        ->not->toContain('js-toggle-parts');
});

it('lets each group, or all of them, be folded away', function () {
    splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);

    [, $assigned] = operationsTabs();

    expect($assigned)->toContain('js-toggle-parts')
        ->toContain('js-toggle-all-parts')
        ->toContain('aria-expanded="true"');
});

it('says so, without the fold-all button, when nothing has been assigned', function () {
    [, $assigned] = operationsTabs();

    expect($assigned)->toContain("Nothing's been assigned to Sourcing yet.")
        ->not->toContain('js-toggle-all-parts');
});

it('shows Admin the same groups on Senior Operations\' page', function () {
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);

    [, $assigned] = operationsTabs([], 'senior-operations');

    expect($assigned)->toContain('RFQ1001-P1 of P2')->toContain('RFQ1001-P2 of P2');
});

it('reads where a part stands from its dates', function (array $dates, string $state, string $label, string $badge) {
    $assignment = (new RfqAssignment)->forceFill($dates);

    expect($assignment->progressState())->toBe($state)
        ->and($assignment->progressLabel())->toBe($label)
        ->and($assignment->progressBadgeClass())->toBe($badge);
})->with([
    'just assigned' => [[], 'in_progress', 'In progress', 'badge-soft-warning'],
    'Sourcing done' => [['completed_at' => '2026-09-19 10:00:00'], 'with_data_entry', 'With Data Entry', 'badge-soft-primary'],
    'sent back' => [['returned_at' => '2026-09-19 10:00:00'], 'returned', 'Returned', 'badge-soft-danger'],
    'Data Entry done' => [['completed_at' => '2026-09-19 10:00:00', 'data_entry_completed_at' => '2026-09-19 11:00:00'], 'data_entry_done', 'Data Entry done', 'badge-soft-success'],
]);
