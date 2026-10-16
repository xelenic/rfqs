<?php

use App\Models\Rfq;
use App\Models\Setting;
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
 * Travels to $time ("H:i") on Monday 5 October in Colombo.
 */
function perItemAt(string $time): void
{
    test()->travelTo(CarbonImmutable::parse("2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ $number with $items items, at Medium — two hours by priority — its
 * one part (or $parts parts) handed to Data Entry at 09:00.
 */
function perItemRfq(string $number, ?int $items, int $parts = 1): Rfq
{
    perItemAt('08:30');
    $holders = collect(range(1, $parts))->mapWithKeys(fn (int $part) => [$part => userWithRole('Sourcing')])->all();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => 'Medium', 'number_of_items' => $items]), $holders);

    perItemAt('09:00');
    foreach (array_keys($holders) as $part) {
        $rfq->refresh()->completeSourcingPart($part);
    }

    return $rfq->refresh();
}

/**
 * Data Entry's target per item: on, $seconds an item.
 */
function perItemOn(int $seconds = 180): void
{
    Setting::putDataEntryPerItem(['enabled' => true, 'seconds' => $seconds]);
}

// ---- the setting -----------------------------------------------------------

it('starts off — by priority — at two minutes an item', function () {
    expect(Setting::dataEntryPerItem())->toBe(['enabled' => false, 'seconds' => 120]);
});

it('lets an Admin set Data Entry\'s target per item, on Settings → Data Entry Targets', function () {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.settings.edit', ['tab' => 'data-entry-targets']))->assertOk()
        ->assertSee('action="'.route('admin.settings.data-entry-per-item').'"', false)
        ->assertSee('Also per item for parts without line items', false);

    test()->actingAs($admin)->patch(route('admin.settings.data-entry-per-item'), ['enabled' => '1', 'minutes' => '2.5'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Saved — line items to add times the time per item, and parts without line items worked out per item too.')
        ->assertSessionHas('settings_tab', 'data-entry-targets');

    expect(Setting::dataEntryPerItem())->toBe(['enabled' => true, 'seconds' => 150]);

    test()->actingAs($admin)->patch(route('admin.settings.data-entry-per-item'), ['enabled' => '0', 'minutes' => '2.5'])
        ->assertSessionHas('status', 'Saved — line items to add times the time per item; parts without line items keep their priority\'s target.');

    expect(Setting::dataEntryPerItem()['enabled'])->toBeFalse();
});

it('refuses a time per item that isn\'t sensible, back on its own tab', function (mixed $minutes, string $message) {
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->from(route('admin.settings.edit'))
        ->patch(route('admin.settings.data-entry-per-item'), ['enabled' => '1', 'minutes' => $minutes])
        ->assertSessionHasErrorsIn('data_entry_per_item', ['minutes' => $message]);

    test()->actingAs($admin)->from(route('admin.settings.edit'))->followingRedirects()
        ->patch(route('admin.settings.data-entry-per-item'), ['enabled' => '1', 'minutes' => $minutes])
        ->assertSee('class="tab-pane fade show active" id="settings-pane-data-entry-targets"', false);

    expect(Setting::dataEntryPerItem()['enabled'])->toBeFalse();
})->with([
    'nothing' => ['', 'Give the minutes an item takes.'],
    'too little' => ['0.2', 'Keep it between 0.5 and 240 minutes an item.'],
    'too much' => ['300', 'Keep it between 0.5 and 240 minutes an item.'],
    'not to the half minute' => ['2.3', 'Give it to the half minute, like 2.5.'],
]);

it('keeps it to an Admin', function () {
    test()->actingAs(userWithRole('Data Entry'))->patch(route('admin.settings.data-entry-per-item'), ['enabled' => '1', 'minutes' => '3'])->assertForbidden();

    expect(Setting::dataEntryPerItem()['enabled'])->toBeFalse();
});

// ---- the target ------------------------------------------------------------

it('works a part\'s target out as the time per item times its items', function () {
    $whole = perItemRfq('RFQ9001', 40);
    $split = perItemRfq('RFQ9002', 41, parts: 2);
    $noItems = perItemRfq('RFQ9003', null);

    // Off: by priority — Medium's two hours.
    expect($whole->dataEntryTargetSeconds(1))->toBe(7200)
        ->and($whole->dataEntryTargetBreakdown(1))->toBeNull();

    perItemOn(180);

    expect($whole->dataEntryTargetSeconds(1))->toBe(40 * 180)
        ->and($whole->dataEntryTargetBreakdown(1))->toBe(['items' => 40, 'perItem' => 180, 'seconds' => 7200, 'source' => 'items'])
        // A split's parts share its items, rounded up: 21 each.
        ->and($split->dataEntryTargetSeconds(1))->toBe(21 * 180)
        ->and($split->dataEntryTargetSeconds(2))->toBe(21 * 180)
        // No item count: its priority's.
        ->and($noItems->dataEntryTargetSeconds(1))->toBe(7200)
        ->and($noItems->dataEntryTargetBreakdown(1))->toBeNull();
});

it('counts a started part down from its items\' time', function () {
    perItemOn(60);
    $rfq = perItemRfq('RFQ9101', 90);
    startDataEntryOn($rfq, 1);
    perItemAt('09:30');

    // 90 items at a minute: 1h 30m, half an hour of it gone.
    expect($rfq->refresh()->dataEntryCountdown(1))->toBe(['target' => 5400, 'remaining' => 3600]);
});

// ---- Ready for Data Entry --------------------------------------------------

it('shows each part\'s time left on Ready for Data Entry — items × time per item — before and after Start', function () {
    perItemOn(180);
    $started = perItemRfq('RFQ9201', 40);
    perItemRfq('RFQ9202', 10);
    perItemRfq('RFQ9203', null);
    startDataEntryOn($started, 1);
    perItemAt('09:30');

    $html = test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    $row = fn (string $number) => Str::betweenFirst($html, '<td class="text-nowrap">'.$number.'</td>', '</tr>');

    // Just the time in the column; how it's made up on hover.
    expect($row('RFQ9201'))
        ->toContain('data-remaining="5400" data-target="7200"')
        ->toContain('title="Working time left to send it to finalize · 40 items × 3 min = 2h"')
        ->not->toContain('de-target-formula')
        ->and($row('RFQ9202'))
        ->toContain('<i class="bi bi-stopwatch"></i> 30m')
        ->toContain('title="Counts down from Start, in working hours · 10 items × 3 min = 30m"')
        ->and($row('RFQ9203'))
        ->toContain('<i class="bi bi-stopwatch"></i> 2h')
        ->toContain(e("No number of items — Medium's target"));
});

// ---- Line items to be added -----------------------------------------------

it('works a part given line items out as those times the time per item — the switch on or off', function (bool $switchOn) {
    Setting::putDataEntryPerItem(['enabled' => $switchOn, 'seconds' => 180]);
    perItemAt('08:30');
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9901', 'priority_level' => 'Medium', 'number_of_items' => 40]), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1, 'Done', null, 25);

    // 25 line items at 3 minutes: 1h 15m — not the RFQ's 40 items, nor Medium's 2 hours.
    expect($rfq->refresh()->dataEntryTargetSeconds(1))->toBe(25 * 180)
        ->and($rfq->dataEntryTargetBreakdown(1))->toBe(['items' => 25, 'perItem' => 180, 'seconds' => 4500, 'source' => 'line_items']);

    $row = Str::betweenFirst(test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), '<td class="text-nowrap">RFQ9901</td>', '</tr>');
    expect($row)->toContain('<i class="bi bi-stopwatch"></i> 1h 15m')
        ->toMatch('/25 line items × 3 min = 1h 15m/');
})->with(['switch on' => true, 'switch off' => false]);

it('counts a started part down from its line items\' time', function () {
    Setting::putDataEntryPerItem(['enabled' => false, 'seconds' => 120]);
    perItemAt('08:30');
    $rfq = splitAmong(Rfq::factory()->create(['priority_level' => 'Urgent']), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1, null, null, 30);
    perItemAt('09:00');
    startDataEntryOn($rfq, 1);
    perItemAt('09:20');

    // 30 line items at 2 minutes: an hour — Urgent's own 30 minutes aside.
    expect($rfq->refresh()->dataEntryCountdown(1))->toBe(['target' => 3600, 'remaining' => 2400]);
});
