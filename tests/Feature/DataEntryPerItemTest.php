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
        ->assertSee("Set Data Entry's target per item", false);

    test()->actingAs($admin)->patch(route('admin.settings.data-entry-per-item'), ['enabled' => '1', 'minutes' => '2.5'])
        ->assertRedirect(route('admin.settings.edit'))
        ->assertSessionHas('status', 'Data Entry\'s target is now worked out per item.')
        ->assertSessionHas('settings_tab', 'data-entry-targets');

    expect(Setting::dataEntryPerItem())->toBe(['enabled' => true, 'seconds' => 150]);

    test()->actingAs($admin)->patch(route('admin.settings.data-entry-per-item'), ['enabled' => '0', 'minutes' => '2.5'])
        ->assertSessionHas('status', 'Data Entry\'s target is back to its priority\'s.');

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
        ->and($whole->dataEntryTargetBreakdown(1))->toBe(['items' => 40, 'perItem' => 180, 'seconds' => 7200])
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

    expect($row('RFQ9201'))
        ->toContain('data-remaining="5400" data-target="7200"')
        ->toMatch('/40 items × 3 min = 2h\s*<\/div>/')
        ->and($row('RFQ9202'))
        ->toContain('<i class="bi bi-stopwatch"></i> 30m')
        ->toMatch('/10 items × 3 min = 30m\s*· from Start/')
        ->and($row('RFQ9203'))
        ->toContain('<i class="bi bi-stopwatch"></i> 2h')
        ->toContain("No number of items — Medium's target");
});
