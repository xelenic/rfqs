<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }

    // Monday 5 October, 09:00 in Colombo — working time.
    test()->travelTo(CarbonImmutable::parse('2026-10-05 09:00', Setting::DEFAULT_TIMEZONE));
});

/**
 * RFQ $number, its one part with Sourcing — handed to Data Entry when
 * $handTo is given: to that person by name, or (true) to anyone there.
 */
function rfqForDe(string $number, User|bool $handTo = false): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'wc_number' => 'WC'.substr($number, 3)]), [1 => userWithRole('Sourcing')]);

    if ($handTo !== false) {
        $rfq->refresh()->completeSourcingPart(1, null, $handTo instanceof User ? $handTo : null);
    }

    return $rfq->refresh();
}

it('shows Data Entry only the RFQs given to them — by name, or to anyone while it\'s with Data Entry', function () {
    $morgan = userWithRole('Data Entry');
    $sam = userWithRole('Data Entry');

    $mine = rfqForDe('RFQ8001', $morgan);
    $samsOwn = rfqForDe('RFQ8002', $sam);
    $anyone = rfqForDe('RFQ8003', true);
    $stillWithSourcing = rfqForDe('RFQ8004');

    foreach ([$mine, $anyone] as $rfq) {
        expect($rfq->isVisibleTo($morgan))->toBeTrue();
        test()->actingAs($morgan)->get(route('admin.rfqs.show', $rfq))->assertOk();
    }

    foreach ([$samsOwn, $stillWithSourcing] as $rfq) {
        expect($rfq->isVisibleTo($morgan))->toBeFalse();
        test()->actingAs($morgan)->get(route('admin.rfqs.show', $rfq))
            ->assertForbidden()
            ->assertSee('This RFQ isn&#039;t one of yours — you can only open the RFQs Sourcing assigned to you.', false);
    }

    // The WC search too.
    test()->actingAs($morgan)->getJson(route('admin.rfqs.suggest', ['q' => 'WC800']))->assertOk()
        ->assertJsonPath('total', 2)
        ->assertJsonPath('results.*.rfq', fn (array $numbers) => collect($numbers)->sort()->values()->all() === ['RFQ8001', 'RFQ8003']);
});

it('keeps one left with anyone shared while it\'s with Data Entry — then with whoever worked it', function () {
    $morgan = userWithRole('Data Entry');
    $sam = userWithRole('Data Entry');
    $rfq = rfqForDe('RFQ8101', true);

    // Morgan starts it: still the team's, while it's with Data Entry.
    startDataEntryOn($rfq, 1, $morgan);
    expect($rfq->refresh()->isVisibleTo($sam))->toBeTrue();

    // Sent to finalize, it's Morgan's alone.
    $rfq->refresh()->completeDataEntryPart(1, $morgan, 'Entered', 4);

    expect($rfq->refresh()->isVisibleTo($morgan))->toBeTrue()
        ->and($rfq->isVisibleTo($sam))->toBeFalse();
});

it('keeps an RFQ with whoever worked a round of it, however it\'s gone since', function () {
    $morgan = userWithRole('Data Entry');
    $sam = userWithRole('Data Entry');
    $rfq = rfqForDe('RFQ8201', $morgan);

    startDataEntryOn($rfq, 1, $morgan);
    // Sent back to Sourcing, and on to Sam this time.
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $morgan);
    $rfq->refresh()->completeSourcingPart(1, null, $sam);

    expect($rfq->refresh()->isVisibleTo($morgan))->toBeTrue()
        ->and($rfq->isVisibleTo($sam))->toBeTrue();
});

it('lists on Closed RFQs only what they worked on', function () {
    $morgan = userWithRole('Data Entry');

    foreach (['RFQ8301' => $morgan, 'RFQ8302' => userWithRole('Data Entry')] as $number => $worker) {
        $rfq = rfqForDe($number, $worker);
        startDataEntryOn($rfq, 1, $worker);
        $rfq->refresh()->completeDataEntryPart(1, $worker, 'Entered', 1);
        $rfq->refresh()->update(['status' => 'Completed', 'stage' => 'closed', 'bd_closed_at' => now()]);
    }

    test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Completed']))->assertOk()
        ->assertSee('RFQ8301')
        ->assertDontSee('RFQ8302');
});

it('says whose a part is when the RFQ is theirs but that part isn\'t', function () {
    $morgan = userWithRole('Data Entry');
    $sam = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8401']), [1 => userWithRole('Sourcing'), 2 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1, null, $morgan);
    $rfq->refresh()->completeSourcingPart(2, null, $sam);

    test()->actingAs($sam)->patch(route('admin.rfqs.start-data-entry', $rfq), ['part' => 1])
        ->assertSessionHas('error', "This part is assigned to {$morgan->name} in Data Entry — it's theirs to start.");
});

it('leaves Admin, and Data Entry holding another workflow role, seeing every RFQ', function (Closure $viewer) {
    $rfq = rfqForDe('RFQ8501');

    test()->actingAs($viewer())->get(route('admin.rfqs.show', $rfq))->assertOk();
})->with([
    'Admin' => [fn () => userWithRole('Admin')],
    'Data Entry and Sourcing' => [fn () => userWithRole('Data Entry')->assignRole('Sourcing')],
]);
