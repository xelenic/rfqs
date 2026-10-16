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
 * $member's My Pending RFQs, by tab: [Pending for Sourcing, With Data
 * Entry, the whole page, Finalized], each the tab's own markup.
 *
 * @return array{0: string, 1: string, 2: string, 3: string}
 */
function sourcingTabs(User $member, array $query = []): array
{
    $html = test()->actingAs($member)->get(route('admin.rfqs.index', ['status' => 'Pending'] + $query))->assertOk()->getContent();

    return [
        Str::betweenFirst($html, 'id="rfq-sourcing-todo"', 'id="rfq-sourcing-with-de"'),
        Str::betweenFirst(Str::after($html, 'id="rfq-sourcing-with-de"'), '<tbody>', '</tbody>'),
        $html,
        Str::betweenFirst(Str::after($html, 'id="rfq-sourcing-finalized"'), '<tbody>', '</tbody>'),
    ];
}

it('moves a part from Pending for Sourcing to With Data Entry once it\'s assigned, back to finalize, then to Finalized', function () {
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7701']), [1 => $riley]);
    $row = '<td class="text-nowrap">RFQ7701</td>';

    [$todo, $withDataEntry, $html] = sourcingTabs($riley);
    expect($todo)->toContain($row)
        ->and($withDataEntry)->not->toContain($row)
        ->and($html)->toContain('Pending for Sourcing <span class="rfq-tab-count">1</span>')
        ->toContain('With Data Entry <span class="rfq-tab-count">0</span>')
        ->toContain('Finalized <span class="rfq-tab-count">0</span>');

    // Assigned to Morgan in Data Entry: on With Data Entry, saying so.
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'line_items' => 5, 'comment' => 'Prices in', 'data_entry_user_id' => $morgan->id]);
    [$todo, $withDataEntry, $html] = sourcingTabs($riley);
    expect($todo)->not->toContain($row)
        ->and($withDataEntry)->toContain($row)
        ->toContain('Not started yet')
        ->toContain('Assigned to '.e($morgan->name))
        ->and($html)->toContain('With Data Entry <span class="rfq-tab-count">1</span>');

    // Started: in progress — not saying who.
    startDataEntryOn($rfq, 1, $morgan);
    expect(sourcingTabs($riley)[1])->toContain('In progress');

    // Sent to finalize: back on To do, to finalize.
    $rfq->refresh()->completeDataEntryPart(1, $morgan, 'Entered', 2);
    [$todo, $withDataEntry] = sourcingTabs($riley);
    expect($todo)->toContain($row)->toContain('Sent to finalize by Data Entry')
        ->and($withDataEntry)->not->toContain($row);

    // Finalized: on Finalized, in Senior Operations' review — and on through the reviews.
    $rfq->refresh()->finalizePart(1);
    [$todo, $withDataEntry, $html, $finalized] = sourcingTabs($riley);
    expect($todo)->not->toContain($row)
        ->and($withDataEntry)->not->toContain($row)
        ->and($finalized)->toContain($row)->toContain('In Senior Operations review')
        ->and($html)->toContain('Finalized <span class="rfq-tab-count">1</span>');

    $rfq->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));
    expect(sourcingTabs($riley)[3])->toContain('With Head of Business Development');
});

it('keeps each part of a split on its own tab', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7801']), [1 => $riley, 2 => $riley]);
    $rfq->refresh()->completeSourcingPart(1);

    [$todo, $withDataEntry] = sourcingTabs($riley);

    expect($todo)->toContain('<td class="text-nowrap">RFQ7801-P2 of P2</td>')
        ->not->toContain('<td class="text-nowrap">RFQ7801-P1 of P2</td>')
        ->and($withDataEntry)->toContain('<td class="text-nowrap">RFQ7801-P1 of P2</td>')
        ->not->toContain('<td class="text-nowrap">RFQ7801-P2 of P2</td>');
});

it('opens on the tab asked for, and searches within it', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ7901', 'subject' => 'Lobby chairs']), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1);

    [, , $html] = sourcingTabs($riley, ['tab' => 'with-data-entry']);
    expect($html)->toContain('class="tab-pane fade show active" id="rfq-sourcing-with-de"')
        ->toContain('<input type="hidden" name="tab" id="deTabInput" value="with-data-entry" >');

    [, $withDataEntry] = sourcingTabs($riley, ['tab' => 'with-data-entry', 'search' => 'nothing like it']);
    expect($withDataEntry)->not->toContain('RFQ7901');
});

it('opens on Finalized when asked, keeping the tab for the search', function () {
    $riley = userWithRole('Sourcing');

    [, , $html] = sourcingTabs($riley, ['tab' => 'finalized']);

    expect($html)->toContain('class="tab-pane fade show active" id="rfq-sourcing-finalized"')
        ->toContain('<input type="hidden" name="tab" id="deTabInput" value="finalized" >')
        ->toContain('Nothing finalized yet');
});
