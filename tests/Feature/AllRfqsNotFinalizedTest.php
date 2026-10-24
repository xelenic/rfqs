<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * $rfq's part $part through Sourcing, Data Entry and Sourcing's Finalize.
 */
function finalizedPart(Rfq $rfq, int $part): void
{
    $rfq->refresh()->completeSourcingPart($part);
    $rfq->refresh()->completeDataEntryPart($part, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart($part);
}

/**
 * RFQs at every point of Sourcing: RFQ9101 nobody's assigned; RFQ9102 with
 * Riley; RFQ9103 split, its part 1 finalized and part 2 with Data Entry for
 * Sam; RFQ9104 split three ways, part 1 finalized and two nobody holds;
 * RFQ9105 finalized; RFQ9106 split, part 1 finalized and part 2 cancelled;
 * RFQ9107 on hold with Riley; RFQ9108 cancelled with Riley; RFQ9109 closed.
 *
 * @return array{riley: User, sam: User}
 */
function sourcingAtEveryPoint(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $sam = tap(userWithRole('Sourcing'))->update(['name' => 'Sam Rivera']);
    $rfq = fn (string $number, array $attributes = []) => Rfq::factory()->create(['rfq_number' => $number] + $attributes);

    $rfq('RFQ9101');
    splitAmong($rfq('RFQ9102'), [1 => $riley]);

    $split = splitAmong($rfq('RFQ9103'), [1 => $riley, 2 => $sam]);
    finalizedPart($split, 1);
    $split->refresh()->completeSourcingPart(2);

    $partlyAssigned = $rfq('RFQ9104');
    $partlyAssigned->planSplit(3);
    $partlyAssigned->assignSourcingParts([1 => $riley->id]);
    finalizedPart($partlyAssigned, 1);

    finalizedPart(splitAmong($rfq('RFQ9105'), [1 => $riley]), 1);

    $cancelledPart = splitAmong($rfq('RFQ9106'), [1 => $riley, 2 => $sam]);
    finalizedPart($cancelledPart, 1);
    $cancelledPart->refresh()->changePartStatus(2, Rfq::CANCELLED, userWithRole('Senior Operations'), 'Customer dropped it');

    splitAmong($rfq('RFQ9107'), [1 => $riley])->changeStatus(Rfq::ON_HOLD, userWithRole('Senior Operations'), 'Waiting on drawings');
    splitAmong($rfq('RFQ9108'), [1 => $riley])->changeStatus(Rfq::CANCELLED, userWithRole('Senior Operations'), 'Lost the job');
    $closed = splitAmong($rfq('RFQ9109'), [1 => $sam]);
    finalizedPart($closed, 1);
    $closed->forceFill(['status' => 'Completed', 'stage' => 'closed'])->save();

    return ['riley' => $riley, 'sam' => $sam];
}

/**
 * All RFQs, as $user sees it with ?show=not_finalized.
 */
function notFinalizedPage(User $user): string
{
    return test()->actingAs($user)->get(route('admin.rfqs.index', ['show' => 'not_finalized']))->assertOk()->getContent();
}

/**
 * The text of the row listing $number on $html's list.
 */
function listRowText(string $html, string $number): string
{
    $rows = collect(explode('<tr', Str::after($html, '<tbody>')));

    return Str::squish(strip_tags('<tr'.$rows->first(fn (string $row) => str_contains($row, $number))));
}

it('narrows All RFQs to the open RFQs Sourcing hasn\'t finalized — on hold too, a part nobody holds too', function () {
    sourcingAtEveryPoint();
    $admin = userWithRole('Admin');

    $listed = test()->actingAs($admin)->get(route('admin.rfqs.index', ['show' => 'not_finalized']))->assertOk()
        ->viewData('rfqs')->pluck('rfq_number')->sort()->values()->all();

    expect($listed)->toBe(['RFQ9101', 'RFQ9102', 'RFQ9103', 'RFQ9104', 'RFQ9107']);
});

it('offers it in the Show filter, for Admin and the General Manager, and says what\'s shown', function () {
    sourcingAtEveryPoint();

    foreach ([userWithRole('Admin'), userWithRole('General Manager')] as $person) {
        test()->actingAs($person)->get(route('admin.rfqs.index'))->assertOk()
            ->assertSee('<option value="not_finalized" >Show: Not finalized by Sourcing</option>', false);

        expect(notFinalizedPage($person))
            ->toContain('<option value="not_finalized" selected>Show: Not finalized by Sourcing</option>')
            ->toContain('Showing <strong>Not finalized by Sourcing</strong> — 5 RFQs.');
    }
});

it('notes on each row the parts still to finalize — who has them and where they stand, or that nobody does', function () {
    sourcingAtEveryPoint();
    $html = notFinalizedPage(userWithRole('Admin'));

    expect(listRowText($html, 'RFQ9101'))->toContain('Not assigned to Sourcing yet')->not->toContain('Not finalized —')
        ->and(listRowText($html, 'RFQ9102'))->toContain('Not finalized — Riley Chen · In progress')
        ->and(listRowText($html, 'RFQ9103'))->toContain('RFQ9103-P2 of P2 — Sam Rivera · With Data Entry')->not->toContain('RFQ9103-P1 of P2')
        ->and(listRowText($html, 'RFQ9104'))->toContain('RFQ9104-P2 of P3 — Not assigned to Sourcing yet')->toContain('RFQ9104-P3 of P3 — Not assigned to Sourcing yet')
        ->and(listRowText($html, 'RFQ9107'))->toContain('On Hold')->toContain('Not finalized — Riley Chen · In progress');

    // Not on All RFQs as a whole.
    expect(test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index'))->getContent())->not->toContain('data-to-finalize');
});

it('counts a split with a part nobody holds as not finalized on the RFQ tracking sheet too', function () {
    sourcingAtEveryPoint();

    $listed = fn (array $query) => test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.rfq-tracking', $query))->assertOk()
        ->viewData('rfqs')->pluck('rfq_number')->sort()->values()->all();

    expect($listed(['finalized' => 'not_finalized']))->toBe(['RFQ9101', 'RFQ9102', 'RFQ9103', 'RFQ9104', 'RFQ9107', 'RFQ9108'])
        ->and($listed(['finalized' => 'finalized']))->toBe(['RFQ9105', 'RFQ9106', 'RFQ9109']);
});
