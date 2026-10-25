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
 * RFQ9901 split three ways — Riley holds part 2.
 *
 * @return array{rfq: Rfq, riley: User}
 */
function threeWaySplit(): array
{
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9901', 'subject' => 'Pumps']), [1 => userWithRole('Sourcing'), 2 => $riley, 3 => userWithRole('Sourcing')]);

    return ['rfq' => $rfq, 'riley' => $riley];
}

/**
 * The parts the progress chart on $response draws a branch for — the
 * Sourcing boxes' RFQ numbers.
 *
 * @return array<int, string>
 */
function chartParts(TestResponse $response): array
{
    $tree = json_decode(Str::betweenFirst($response->getContent(), '<script type="application/json" id="rfq-progress-data">', '</script>'), true)['tree'];
    $parts = [];
    $walk = function (array $node) use (&$walk, &$parts) {
        if ($node['step'] === 'sourcing' && isset($node['role'])) {
            $parts[] = $node['rfq_number'];
        }
        array_map($walk, $node['children']);
    };
    $walk($tree);

    return $parts;
}

it('opens a split part from its row on the RFQ page focused on it — the chart showing that part\'s flow alone', function () {
    ['rfq' => $rfq, 'riley' => $riley] = threeWaySplit();

    expect($rfq->detailsUrl(2, 'Pending'))->toBe(route('admin.rfqs.show', ['rfq' => $rfq, 'status' => 'Pending', 'part' => 2]));

    $response = test()->actingAs($riley)->get($rfq->detailsUrl(2, 'Pending'))->assertOk()
        ->assertSee('RFQ9901-P2 of P3 only')
        ->assertSee('data-part-flow', false)
        ->assertDontSee('Whole RFQ (3 parts)');

    expect(chartParts($response))->toBe(['RFQ9901-P2 of P3']);

    // Without a part — from a whole-RFQ row — every part.
    expect(chartParts(test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.show', $rfq))->assertOk()))
        ->toBe(['RFQ9901-P1 of P3', 'RFQ9901-P2 of P3', 'RFQ9901-P3 of P3']);
});

it('lets an Admin switch the chart between the part\'s flow and the whole RFQ\'s', function () {
    ['rfq' => $rfq] = threeWaySplit();
    $admin = userWithRole('Admin');

    $focused = test()->actingAs($admin)->get($rfq->detailsUrl(2))->assertOk()
        ->assertSee('data-part-flow', false)
        ->assertSee('This part (P2)')
        ->assertSee('Whole RFQ (3 parts)');
    expect(chartParts($focused))->toBe(['RFQ9901-P2 of P3'])
        ->and(Str::betweenFirst($focused->getContent(), 'data-part-flow', '</div>'))
        ->toContain('href="'.e(route('admin.rfqs.show', ['rfq' => $rfq, 'part' => 2, 'flow' => 'whole'])).'"');

    $whole = test()->actingAs($admin)->get(route('admin.rfqs.show', ['rfq' => $rfq, 'part' => 2, 'flow' => 'whole']))->assertOk()
        ->assertSee('Whole RFQ (3 parts)')
        ->assertDontSee('RFQ9901-P2 of P3 only');
    expect(chartParts($whole))->toBe(['RFQ9901-P1 of P3', 'RFQ9901-P2 of P3', 'RFQ9901-P3 of P3']);

    // Anyone else asking for the whole flow still sees the part they came from.
    expect(chartParts(test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.show', ['rfq' => $rfq, 'part' => 2, 'flow' => 'whole']))))
        ->toBe(['RFQ9901-P2 of P3']);
});

it('leaves an RFQ kept whole — or a part it hasn\'t — as it is', function () {
    $whole = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9902']), [1 => userWithRole('Sourcing')]);
    ['rfq' => $split] = threeWaySplit();

    expect($whole->detailsUrl(1, 'Pending'))->toBe(route('admin.rfqs.show', ['rfq' => $whole, 'status' => 'Pending']));
    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', ['rfq' => $split, 'part' => 9]))->assertOk()
        ->assertDontSee('data-part-flow', false)
        ->assertDontSee(' only</span>', false);
});

it('leads each part\'s row on the lists to that part', function () {
    ['rfq' => $rfq, 'riley' => $riley] = threeWaySplit();
    $rfq->refresh()->completeSourcingPart(2);
    $rfq->refresh()->completeDataEntryPart(2, userWithRole('Data Entry'), 'Entered', 4);
    $rfq->refresh()->finalizePart(2, 4);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('href="'.e($rfq->detailsUrl(2, 'Pending')).'"', false);
});
