<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
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
 * Monday 5 October 2026 at $time, in Colombo.
 */
function historyAt(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ9501's one part, from 08:30: Riley sources it and gives Dana 24 line
 * items to add at 09:30; Dana starts at 10:00 and sends it to finalize at
 * 11:00 with 22; Riley returns it at 11:30 — two are missing; Dana starts
 * again at 13:30 and sends it at 14:00 with 24; Riley finalizes it at 14:15.
 *
 * @return array{rfq: Rfq, riley: User, dana: User, ops: User}
 */
function lineItemsStory(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $dana = tap(userWithRole('Data Entry'))->update(['name' => 'Dana Perera']);
    $ops = tap(userWithRole('Senior Operations'))->update(['name' => 'Olivia Park']);

    historyAt('08:30');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9501', 'subject' => 'Life jackets', 'priority_level' => 'High']), [1 => $riley]);
    historyAt('09:30');
    $rfq->refresh()->completeSourcingPart(1, 'Prices are in the sheet', $dana, 24);
    historyAt('10:00');
    startDataEntryOn($rfq, 1, $dana);
    historyAt('11:00');
    $rfq->refresh()->completeDataEntryPart(1, $dana, 'All entered', 22);
    historyAt('11:30');
    $rfq->refresh()->returnToDataEntry(1, 'Two items are missing');
    historyAt('13:30');
    startDataEntryOn($rfq, 1, $dana);
    historyAt('14:00');
    $rfq->refresh()->completeDataEntryPart(1, $dana, 'Added the two', 24);
    historyAt('14:15');
    $rfq->refresh()->finalizePart(1);

    return ['rfq' => $rfq->refresh(), 'riley' => $riley, 'dana' => $dana, 'ops' => $ops];
}

it('tells the story of a part\'s line items — each count, who gave it in which role, when, and what changed', function () {
    ['rfq' => $rfq] = lineItemsStory();

    $history = collect($rfq->lineItemsHistory(1));

    expect($history->map(fn (array $row) => "{$row['role']} · {$row['title']}")->all())->toBe([
        'Sourcing · Worked on sourcing',
        'Sourcing · Line items to add — assigned to Dana Perera',
        'Data Entry · Worked on the data entry',
        'Data Entry · Line items added — sent to finalize',
        'Sourcing · Checked it to finalize',
        'Sourcing · Returned to Data Entry',
        'Data Entry · Worked on the data entry',
        'Data Entry · Line items added — sent to finalize',
        'Sourcing · Checked it to finalize',
    ]);

    expect($history[0])->toMatchArray(['who' => 'Riley Chen', 'at' => 'Oct 05, 2026 8:30 AM', 'until' => 'Oct 05, 2026 9:30 AM', 'duration' => '1h working time'])
        ->and($history[1])->toMatchArray(['who' => 'Riley Chen', 'count' => 24, 'change' => null, 'note' => 'Prices are in the sheet', 'at' => 'Oct 05, 2026 9:30 AM'])
        ->and($history[2])->toMatchArray(['who' => 'Dana Perera', 'at' => 'Oct 05, 2026 10:00 AM', 'until' => 'Oct 05, 2026 11:00 AM', 'duration' => '1h working time'])
        ->and($history[3])->toMatchArray(['who' => 'Dana Perera', 'count' => 22, 'change' => '2 fewer than the 24 requested', 'tone' => 'off', 'note' => 'All entered'])
        ->and($history[5])->toMatchArray(['who' => 'Riley Chen', 'count' => null, 'note' => 'Two items are missing', 'at' => 'Oct 05, 2026 11:30 AM'])
        // 13:30–14:00: half an hour, after lunch.
        ->and($history[6])->toMatchArray(['until' => 'Oct 05, 2026 2:00 PM', 'duration' => '30m working time'])
        ->and($history[7])->toMatchArray(['count' => 24, 'change' => 'Matches the 24 requested · Was 22 — +2', 'tone' => 'off'])
        ->and($history[8])->toMatchArray(['until' => 'Oct 05, 2026 2:15 PM', 'duration' => '15m working time']);
});

it('adds Senior Operations\' verified count once they\'ve approved it, and what it\'s against', function () {
    ['rfq' => $rfq, 'ops' => $ops] = lineItemsStory();
    historyAt('15:00');
    $rfq->approveSeniorOpsPart(1, $ops, 23);

    expect(collect($rfq->refresh()->lineItemsHistory(1))->last())->toMatchArray([
        'kind' => 'verified',
        'role' => 'Senior Operations',
        'who' => 'Olivia Park',
        'title' => 'Actual line items — verified',
        'count' => 23,
        'change' => '1 fewer than Data Entry added',
        'tone' => 'off',
        'at' => 'Oct 05, 2026 3:00 PM',
    ]);
});

it('keeps the line items Data Entry had added when they sent it back to Sourcing', function () {
    $riley = userWithRole('Sourcing');
    $dana = tap(userWithRole('Data Entry'))->update(['name' => 'Dana Perera']);
    historyAt('08:30');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1, 'Over to you', $dana, 10);
    historyAt('09:00');
    $rfq->refresh()->returnSourcingPart(1, 'The supplier sheet is wrong', $dana, 4);

    expect(collect($rfq->refresh()->lineItemsHistory(1))->firstWhere('kind', 'returned_to_sourcing'))->toMatchArray([
        'role' => 'Data Entry',
        'title' => 'Line items added so far — returned to Sourcing',
        'count' => 4,
        'change' => '6 fewer than the 10 requested',
        'note' => 'The supplier sheet is wrong',
    ]);
});

it('shows the history in Senior Operations\' Verify line items popup — and the Head of Business Development\'s', function () {
    ['rfq' => $rfq, 'ops' => $ops] = lineItemsStory();

    $html = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('Line items history')
        ->getContent();
    preg_match('/class="btn btn-sm btn-success js-senior-ops-approve".*?data-parts="([^"]*)"/s', $html, $match);
    $parts = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);

    expect($parts[0]['history'])->toHaveCount(9)
        ->and($parts[0]['history'][3]['change'])->toBe('2 fewer than the 24 requested');

    $rfq->approveSeniorOpsPart(1, $ops, 24);

    $head = test()->actingAs(userWithRole('Head of Business Development'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    preg_match('/class="btn btn-sm btn-success js-head-of-bd-approve".*?data-parts="([^"]*)"/s', $head, $match);
    $headParts = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);

    expect(collect($headParts[0]['history'])->last())->toMatchArray(['kind' => 'verified', 'count' => 24, 'change' => 'Matches what Data Entry added', 'tone' => 'ok'])
        ->and(Str::contains($head, 'Line items history'))->toBeTrue();
});
