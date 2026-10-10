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
 * $rfqNumber's one part, done by Sourcing and sent to finalize by $dataEntry.
 */
function sentToFinalizeBy(User $dataEntry, string $rfqNumber, string $subject = 'Replace exit signs'): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $rfqNumber, 'subject' => $subject]), [1 => userWithRole('Sourcing')]);
    $rfq->completeSourcingPart(1);
    startDataEntryOn($rfq, 1, $dataEntry);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    return $rfq->refresh();
}

/**
 * $user's Ready for Data Entry page: [the tabs, the Ready tab, the Completed tab].
 *
 * @return array{0: string, 1: string, 2: string}
 */
function dataEntryTabs(User $user, array $query = []): array
{
    $html = test()->actingAs($user)->get(route('admin.rfqs.index', ['status' => 'Pending', ...$query]))->assertOk()->getContent();

    return [
        Str::betweenFirst($html, 'rfq-view-toggle', '</ul>'),
        Str::betweenFirst($html, 'id="rfq-de-ready"', 'id="rfq-de-completed"'),
        Str::betweenFirst(Str::after($html, 'id="rfq-de-completed"'), '<tbody>', '</tbody>'),
    ];
}

it('gives Data Entry a Ready tab and a Completed tab, each counting its parts', function () {
    $morgan = userWithRole('Data Entry');
    sentToFinalizeBy($morgan, 'RFQ51001');
    sentToFinalizeBy($morgan, 'RFQ51002');
    $waiting = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ51003']), [1 => userWithRole('Sourcing')]);
    $waiting->completeSourcingPart(1);

    [$tabs, $ready, $completed] = dataEntryTabs($morgan);

    expect($tabs)->toContain('data-de-tab="ready"')
        ->toContain('Ready <span class="rfq-tab-count">1</span>')
        ->toContain('Completed <span class="rfq-tab-count">2</span>')
        ->and($ready)->toContain('RFQ51003')->not->toContain('RFQ51001')
        ->and($completed)->toContain('RFQ51001')->toContain('RFQ51002')->not->toContain('RFQ51003')
        ->toContain('With Sourcing to finalize');
});

it('lists only what they completed themselves — everyone\'s for Admin, with who', function () {
    $morgan = userWithRole('Data Entry');
    $casey = userWithRole('Data Entry');
    $casey->update(['name' => 'Casey Fernando']);
    sentToFinalizeBy($morgan, 'RFQ52001');
    sentToFinalizeBy($casey, 'RFQ52002');

    [, , $completed] = dataEntryTabs($morgan);
    expect($completed)->toContain('RFQ52001')->not->toContain('RFQ52002');

    [$tabs, , $completed] = dataEntryTabs(userWithRole('Admin'), ['role' => 'data-entry']);
    expect($tabs)->toContain('Completed <span class="rfq-tab-count">2</span>')
        ->and($completed)->toContain('RFQ52001')->toContain('RFQ52002')->toContain('Casey Fernando');
});

it('follows a completed part on — to closed — latest first', function () {
    $morgan = userWithRole('Data Entry');
    test()->travelTo(now()->subDay());
    $older = sentToFinalizeBy($morgan, 'RFQ53001');
    test()->travelBack();
    sentToFinalizeBy($morgan, 'RFQ53002');

    $older->finalizePart(1);
    $older->refresh()->approveSeniorOpsPart(1, userWithRole('Senior Operations'));

    [, , $completed] = dataEntryTabs($morgan);

    expect(strpos($completed, 'RFQ53002'))->toBeLessThan(strpos($completed, 'RFQ53001'))
        ->and(Str::betweenFirst($completed, 'RFQ53001', '</tr>'))->toContain('With Head of Business Development');
});

it('opens on the tab in the address, and the search narrows both', function () {
    $morgan = userWithRole('Data Entry');
    sentToFinalizeBy($morgan, 'RFQ54001', 'Lobby lighting');
    sentToFinalizeBy($morgan, 'RFQ54002', 'Exit signs');

    $html = test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'completed', 'search' => 'Lobby']))->getContent();

    expect($html)->toContain('<div class="tab-pane fade show active" id="rfq-de-completed"')
        ->toContain('<input type="hidden" name="tab" id="deTabInput" value="completed" >')
        ->and(Str::betweenFirst($html, 'rfq-view-toggle', '</ul>'))->toContain('Completed <span class="rfq-tab-count">1</span>')
        ->and(Str::after($html, 'id="rfq-de-completed"'))->toContain('RFQ54001')->not->toContain('RFQ54002');
});
