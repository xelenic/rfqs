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

function bdReturnsUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']);
}

it('shows the empty state until a reviewer sends something back, then lists it with who, from where, and why', function () {
    $people = [
        'sourcing' => userWithRole('Sourcing'),
        'dataEntry' => userWithRole('Data Entry'),
        'ops' => userWithRole('Senior Operations'),
        'bd' => userWithRole('Business Development'),
    ];

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'subject' => 'Server room chillers']), [1 => $people['sourcing']]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $people['dataEntry']);
    $rfq->refresh()->finalizePart(1);
    expect($rfq->refresh()->stage)->toBe('senior_ops_review');

    test()->actingAs($people['bd'])->get(bdReturnsUrl())->assertOk()
        ->assertDontSee('RFQ2001')
        ->assertSee('Nothing\'s been sent back to Business Development.', false);

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'business_development', 'reason' => 'Wrong building entirely']);

    $html = test()->actingAs($people['bd'])->get(bdReturnsUrl())->assertOk()->getContent();

    expect($html)->toContain('RFQ2001')
        ->toContain('Server room chillers')
        ->toContain('Sent back by Senior Operations (2nd review)')
        ->toContain(e($people['ops']->name))
        ->toContain('Wrong building entirely');

    // Not on Business Development's own Pending or Ready to Close lists —
    // Returns is its own lens, same as Sourcing's.
    test()->actingAs($people['bd'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']))
        ->assertOk()->assertDontSee('RFQ2001');

    // Sourcing has nothing to do with it any more — it's unassigned again.
    test()->actingAs($people['sourcing'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()->assertDontSee('RFQ2001');
});

it('counts Business Development\'s Returns badge, on their own sidebar and Admin\'s grouped one', function () {
    $ops = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $admin = userWithRole('Admin');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'stage' => 'senior_ops_review']);

    expect(Rfq::bdReturnsCount())->toBe(0);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'business_development', 'reason' => 'Start over']);

    expect(Rfq::bdReturnsCount())->toBe(1);

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertSee(e(bdReturnsUrl()), false)
        ->assertSee('1 sent back by a reviewer', false);

    test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertSee(e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns', 'role' => 'business-development'])), false)
        ->assertSee('1 sent back by a reviewer', false);
});

it('leaves the Returns badge at zero, with the link present but no count, when nothing has been sent back', function () {
    $bd = userWithRole('Business Development');

    expect(Rfq::bdReturnsCount())->toBe(0);

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertSee(e(bdReturnsUrl()), false)
        ->assertDontSee('sent back by a reviewer', false);
});
