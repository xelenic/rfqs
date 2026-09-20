<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (['Sourcing', 'Senior Operations', 'Data Entry', 'Head of Business Development', 'GM Assistant', 'General Manager', 'Business Development'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * An RFQ split among $holders (or kept whole, with one), every part all the way
 * through the General Manager and ready for Business Development to close.
 *
 * @param  array<int, User>  $holders
 */
function rfqReadyToCelebrate(array $holders = []): Rfq
{
    $ops = userWithRole('Senior Operations');
    $dataEntry = userWithRole('Data Entry');
    $head = userWithRole('Head of Business Development');
    $assistant = userWithRole('GM Assistant');
    $gm = userWithRole('General Manager');
    $holders = $holders ?: [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')];

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001']), $holders);

    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry);
        $rfq->refresh()->approveSeniorOpsPart($part, $ops);
        $rfq->refresh()->approveHeadOfBdPart($part, $head);
        $rfq->refresh()->recordGmAssistantPart($part, $assistant, 'Acme Ltd', null);
        $rfq->refresh()->approveGmPart($part, $gm);
    }

    return $rfq->refresh();
}

function celebrationUrl(): string
{
    return route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'closing']);
}

it('sets off a modest show when a part is closed and the RFQ carries on', function () {
    $rfq = rfqReadyToCelebrate();
    $closer = userWithRole('Business Development');

    test()->actingAs($closer)->from(celebrationUrl())
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1])
        ->assertSessionHas('celebrate', [
            'title' => 'Closed!',
            'label' => 'RFQ1001-P1 of P2',
            'message' => 'It\'s now in Closed RFQs — 1 of 2 parts closed.',
            'grand' => false,
            'url' => route('admin.rfqs.index', ['status' => 'Completed']),
        ]);

    // On the page it lands on: the overlay, with what was closed and where to see it.
    test()->actingAs($closer)->get(celebrationUrl())->assertOk()
        ->assertSee('id="celebration"', false)
        ->assertSee('data-grand="0"', false)
        ->assertSee('<h2 class="celebration-title">Closed!</h2>', false)
        ->assertSee('<div class="celebration-label">RFQ1001-P1 of P2</div>', false)
        ->assertSee('1 of 2 parts closed.')
        ->assertSee('href="'.route('admin.rfqs.index', ['status' => 'Completed']).'" class="btn btn-sm btn-success"', false)
        ->assertSee('js/fireworks.js');
});

it('sets off the grand show when the last part is closed and with it the RFQ', function () {
    $rfq = rfqReadyToCelebrate();
    $closer = userWithRole('Business Development');
    $rfq->closePart(1, $closer);

    test()->actingAs($closer)->from(celebrationUrl())
        ->patch(route('admin.rfqs.close-part', $rfq->refresh()), ['part' => 2])
        ->assertSessionHas('celebrate', fn (array $celebrate) => $celebrate['grand'] === true
            && $celebrate['title'] === 'RFQ closed!'
            && $celebrate['label'] === 'RFQ1001'
            && $celebrate['message'] === 'All 2 parts are closed — the whole RFQ is done.');

    test()->actingAs($closer)->get(celebrationUrl())->assertOk()
        ->assertSee('data-grand="1"', false)
        ->assertSee('<h2 class="celebration-title">RFQ closed!</h2>', false);
});

it('sets off the grand show for an RFQ kept whole, from either way of closing it', function () {
    $closer = userWithRole('Business Development');
    $whole = rfqReadyToCelebrate([1 => userWithRole('Sourcing')]);

    test()->actingAs($closer)->from(celebrationUrl())
        ->patch(route('admin.rfqs.close-part', $whole), ['part' => 1])
        ->assertSessionHas('celebrate', fn (array $celebrate) => $celebrate['grand'] === true && $celebrate['label'] === 'RFQ1001' && $celebrate['message'] === 'It\'s now in Closed RFQs.');

    // The whole RFQ, from its own Close.
    $another = rfqReadyToCelebrate([1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    $another->update(['rfq_number' => 'RFQ2002']);

    test()->actingAs($closer)->from(celebrationUrl())
        ->patch(route('admin.rfqs.close', $another->refresh()))
        ->assertSessionHas('celebrate', fn (array $celebrate) => $celebrate['grand'] === true && $celebrate['label'] === 'RFQ2002');
});

it('shows it wherever the close was made from', function () {
    $rfq = rfqReadyToCelebrate();
    $closer = userWithRole('Business Development');

    // From the dashboard: back to the dashboard, with the show.
    test()->actingAs($closer)->from(route('admin.dashboard'))
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1])
        ->assertRedirect(route('admin.dashboard'));

    test()->actingAs($closer)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('id="celebration"', false)
        ->assertSee('RFQ1001-P1 of P2');
});

it('goes off once, not on the next page too', function () {
    $rfq = rfqReadyToCelebrate();
    $closer = userWithRole('Business Development');

    test()->actingAs($closer)->from(celebrationUrl())->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1]);

    test()->actingAs($closer)->get(celebrationUrl())->assertSee('id="celebration"', false);
    test()->actingAs($closer)->get(celebrationUrl())->assertDontSee('id="celebration"', false)->assertDontSee('js/fireworks.js');
});

it('stays quiet unless something was closed', function () {
    $rfq = rfqReadyToCelebrate();
    $closer = userWithRole('Business Development');

    // An ordinary page, and a closing that isn't allowed.
    test()->actingAs($closer)->get(celebrationUrl())->assertOk()->assertDontSee('id="celebration"', false);

    test()->actingAs(userWithRole('General Manager'))->from(celebrationUrl())
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1])
        ->assertForbidden()
        ->assertSessionMissing('celebrate');

    // A part that isn't ready to close, and one closed already.
    test()->actingAs($closer)->patch(route('admin.rfqs.close-part', $rfq), ['part' => 9])->assertNotFound()->assertSessionMissing('celebrate');

    test()->actingAs($closer)->from(celebrationUrl())->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1]);
    test()->actingAs($closer)->get(celebrationUrl());
    test()->actingAs($closer)->from(celebrationUrl())
        ->patch(route('admin.rfqs.close-part', $rfq), ['part' => 1])
        ->assertStatus(422)
        ->assertSessionMissing('celebrate');
});

it('is not part of the other approvals\' flash messages', function () {
    $ops = userWithRole('Senior Operations');
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $rfq->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry);

    test()->actingAs($ops)->from(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1])
        ->assertSessionHas('status')
        ->assertSessionMissing('celebrate');
});
