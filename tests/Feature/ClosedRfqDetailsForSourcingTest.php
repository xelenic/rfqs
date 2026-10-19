<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * RFQ8701 ("Kitchen items"), made by Bailey in Business Development, worked
 * by Riley (Sourcing) and Morgan (Data Entry) — split in $parts — and closed
 * by Bailey with quotation QT-5512.
 *
 * @return array{rfq: Rfq, riley: User, morgan: User, bailey: User}
 */
function closedRfq(int $parts = 1): array
{
    $bailey = tap(userWithRole('Business Development'))->update(['name' => 'Bailey Nguyen']);
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8701', 'subject' => 'Kitchen items', 'created_by' => $bailey->id]),
        collect(range(1, $parts))->mapWithKeys(fn (int $part) => [$part => $riley])->all());

    DB::table('rfq_user')->where('rfq_id', $rfq->id)->update([
        'completed_at' => now(), 'data_entry_completed_at' => now(), 'data_entry_completed_by' => $morgan->id,
        'finalized_at' => now(), 'bd_closed_at' => now(), 'bd_closed_by' => $bailey->id, 'bd_reference_code' => 'QT-5512',
    ]);
    $rfq->update(['status' => 'Completed', 'stage' => null, 'bd_closed_at' => now(), 'bd_closed_by' => $bailey->id, 'bd_reference_code' => 'QT-5512']);

    return ['rfq' => $rfq->refresh(), 'riley' => $riley, 'morgan' => $morgan, 'bailey' => $bailey];
}

/**
 * The Closed RFQs list's table, as $viewer sees it.
 */
function closedListTable(User $viewer): string
{
    $html = test()->actingAs($viewer)->get(route('admin.rfqs.index', ['status' => 'Completed']))->assertOk()->getContent();

    return Str::betweenFirst($html, '<thead>', '</table>');
}

it('leaves out when it was made and closed, and by whom, on Sourcing\'s and Data Entry\'s Closed RFQs', function (int $parts, string $who) {
    $cast = closedRfq($parts);

    expect(closedListTable($cast[$who]))
        ->toContain('Kitchen items')
        ->not->toContain('<th>Created</th>')
        ->not->toContain('<th>Closed</th>')
        ->not->toContain('Bailey Nguyen')
        ->not->toContain('QT-5512');
})->with([
    'kept whole' => 1,
    'split' => 2,
])->with(['riley', 'morgan']);

it('still shows them to everyone else', function (Closure $viewer) {
    closedRfq();

    expect(closedListTable($viewer()))
        ->toContain('<th>Created</th>')
        ->toContain('<th>Closed</th>')
        ->toContain('by Bailey Nguyen')
        ->toContain('QT-5512');
})->with([
    'Admin' => [fn () => userWithRole('Admin')],
    'Senior Operations' => [fn () => userWithRole('Senior Operations')],
]);

it('leaves them off a closed RFQ\'s own page for Sourcing and Data Entry', function (string $who) {
    $cast = closedRfq();

    $html = test()->actingAs($cast[$who])->get(route('admin.rfqs.show', $cast['rfq']))->assertOk()
        ->assertSee('Kitchen items')
        ->assertDontSee('<dt>Created by</dt>', false)
        ->assertDontSee('<dt>Created</dt>', false)
        ->assertDontSee('<dt>Quotation number</dt>', false)
        ->assertDontSee('QT-5512')
        ->getContent();

    // Nowhere on the page but the @mention list of all the staff.
    expect(Str::before($html, '<script type="application/json" id="rfq-mention-users">'))->not->toContain('Bailey Nguyen')
        ->and(Str::after(Str::after($html, '<script type="application/json" id="rfq-mention-users">'), '</script>'))->not->toContain('Bailey Nguyen');
})->with(['riley', 'morgan']);

it('keeps them on the page for everyone else, and on an open RFQ', function () {
    ['rfq' => $rfq, 'riley' => $riley] = closedRfq();

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('<dt>Created by</dt>', false)
        ->assertSee('<dt>Quotation number</dt>', false)
        ->assertSee('QT-5512');

    $open = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    test()->actingAs($riley)->get(route('admin.rfqs.show', $open))->assertOk()
        ->assertSee('<dt>Created</dt>', false);
});
