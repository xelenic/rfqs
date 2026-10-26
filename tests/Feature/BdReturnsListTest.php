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

function bdRejectedUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']);
}

it('shows the empty state until a reviewer rejects something back, then lists it on Rejected with who, from where, and why', function () {
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

    test()->actingAs($people['bd'])->get(bdRejectedUrl())->assertOk()
        ->assertSee('<title>Rejected', false)
        ->assertDontSee('RFQ2001')
        ->assertSee('Nothing\'s been rejected back to Business Development.', false);

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'business_development', 'reason' => 'Wrong building entirely']);

    $html = test()->actingAs($people['bd'])->get(bdRejectedUrl())->assertOk()->getContent();

    expect($html)->toContain('RFQ2001')
        ->toContain('Server room chillers')
        ->toContain('Rejected by Senior Operations (2nd review)')
        ->toContain(e($people['ops']->name))
        ->toContain('Wrong building entirely');

    // Not on Business Development's Returns, Pending or Ready to Close lists —
    // Rejected is its own lens, same as Sourcing's.
    expect(pageMain(test()->actingAs($people['bd'])->get(bdReturnsUrl())->assertOk()))->not->toContain('RFQ2001');
    expect(pageMain(test()->actingAs($people['bd'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']))
        ->assertOk()))->not->toContain('RFQ2001');

    // Sourcing has nothing to do with it any more — it's unassigned again.
    expect(pageMain(test()->actingAs($people['sourcing'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()))->not->toContain('RFQ2001');
});

it('counts Business Development\'s Rejected badge, on their own sidebar and Admin\'s grouped one', function () {
    $ops = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $admin = userWithRole('Admin');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'stage' => 'senior_ops_review']);

    expect(Rfq::bdReturnsCount(rejected: true))->toBe(0);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'business_development', 'reason' => 'Start over']);

    expect(Rfq::bdReturnsCount(rejected: true))->toBe(1)
        ->and(Rfq::bdReturnsCount())->toBe(0);

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertSee(e(bdRejectedUrl()), false)
        ->assertSee('1 rejected back by a reviewer', false);

    test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertSee(e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected', 'role' => 'business-development'])), false)
        ->assertSee('1 rejected back by a reviewer', false);
});

it('keeps Senior Operations\' Get Details Again on Business Development\'s Returns, not Rejected', function () {
    $ops = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2002', 'status' => 'Pending']);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Which floors?'])
        ->assertSessionHasNoErrors();

    expect(Rfq::bdReturnsCount())->toBe(1)
        ->and(Rfq::bdReturnsCount(rejected: true))->toBe(0)
        ->and(test()->actingAs($bd)->get(bdReturnsUrl())->viewData('rfqs')->pluck('rfq_number')->all())->toBe(['RFQ2002'])
        ->and(test()->actingAs($bd)->get(bdRejectedUrl())->viewData('rfqs')->pluck('rfq_number')->all())->toBe([]);

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('1 sent back by Senior Operations', false);
});

it('leaves the Returns badge at zero, with the link present but no count, when nothing has been sent back', function () {
    $bd = userWithRole('Business Development');

    expect(Rfq::bdReturnsCount())->toBe(0);

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertOk()
        ->assertSee(e(bdReturnsUrl()), false)
        ->assertSee(e(bdRejectedUrl()), false)
        ->assertDontSee('sent back by Senior Operations', false)
        ->assertDontSee('rejected back by a reviewer', false);
});
