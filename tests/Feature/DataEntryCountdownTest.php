<?php

use App\Models\Rfq;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * A moment in Colombo — the working hours' own time zone; 2026-10-05 is a
 * Monday: 08:30–17:00, lunch 12:30–13:30.
 */
function deCountdownClock(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * RFQ30001, High priority — an hour for Data Entry — its one part handed to
 * Data Entry by Sourcing at 09:00 Monday.
 */
function highPriorityWithDataEntry(): Rfq
{
    test()->travelTo(deCountdownClock('2026-10-05 08:30'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ30001', 'priority_level' => 'High']), [1 => userWithRole('Sourcing')]);

    test()->travelTo(deCountdownClock('2026-10-05 09:00'));
    $rfq->refresh()->completeSourcingPart(1);

    return $rfq->refresh();
}

/**
 * RFQ30001's row on Data Entry's list, as it stands now: [the row's
 * classes, its cells].
 *
 * @return array{0: string, 1: string}
 */
function dataEntryListRow(): array
{
    $html = test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    preg_match('/<tr class="([^"]*)"[^>]*>\s*<td class="fw-semibold">[^<]*<\/td>\s*<td class="text-nowrap">RFQ30001<\/td>/', $html, $match);

    return [$match[1] ?? '', Str::betweenFirst($html, '<td class="text-nowrap">RFQ30001</td>', '</tr>')];
}

// ---- The targets -----------------------------------------------------------------

it('starts with a Data Entry target per priority, in working minutes', function () {
    expect(Setting::dataEntryTargets())->toBe(['Low' => 240, 'Medium' => 120, 'High' => 60, 'Urgent' => 30]);
});

it('lets an Admin set Data Entry\'s targets in hours, to the quarter hour — apart from Sourcing\'s', function () {
    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('Data Entry Targets')
        ->assertSee('id="de-target-urgent"', false);

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.data-entry-targets'), ['targets' => ['Low' => 5, 'Medium' => 3, 'High' => 1.5, 'Urgent' => 0.75]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Data Entry targets saved.');

    expect(Setting::dataEntryTargets())->toBe(['Low' => 300, 'Medium' => 180, 'High' => 90, 'Urgent' => 45])
        ->and(Setting::sourcingTargets())->toBe(Setting::DEFAULT_SOURCING_TARGETS);
});

it('refuses a Data Entry target that isn\'t sensible, and keeps them to an Admin', function () {
    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.data-entry-targets'), ['targets' => ['Low' => 4, 'Medium' => 2, 'High' => 1, 'Urgent' => 1.1]])
        ->assertSessionHasErrorsIn('data_entry_targets', ['targets.Urgent' => 'Give the Urgent target to the quarter hour, like 1.25.']);

    test()->actingAs(userWithRole('Data Entry'))
        ->patch(route('admin.settings.data-entry-targets'), ['targets' => ['Low' => 1, 'Medium' => 1, 'High' => 1, 'Urgent' => 1]])
        ->assertForbidden();

    expect(Setting::dataEntryTargets())->toBe(Setting::DEFAULT_DATA_ENTRY_TARGETS);
});

// ---- The countdown --------------------------------------------------------------

it('shows no countdown, and nothing running, until Data Entry starts', function () {
    highPriorityWithDataEntry();
    test()->travelTo(deCountdownClock('2026-10-05 09:30'));

    [$classes, $row] = dataEntryListRow();

    expect($classes)->not->toContain('rfq-running')
        ->and($row)->toContain('Not started')
        ->not->toContain('data-countdown')
        ->not->toContain('running-pill');
});

it('counts a started part down from its priority\'s target, running and glowing', function () {
    $rfq = highPriorityWithDataEntry();
    test()->travelTo(deCountdownClock('2026-10-05 10:00'));
    startDataEntryOn($rfq, 1);

    test()->travelTo(deCountdownClock('2026-10-05 10:20'));
    [$classes, $row] = dataEntryListRow();

    expect($classes)->toContain('rfq-running js-running-row')->not->toContain('is-paused')
        ->and($row)->toContain('<span class="running-pill-label">Running</span>')
        ->toContain('data-countdown data-remaining="2400" data-target="3600"')
        ->toContain('data-running-title="Working time left to send it to finalize"')
        ->toMatch('/badge sourcing-countdown badge-soft-success/')
        ->toContain('40m left')
        ->and(test()->actingAs(userWithRole('Data Entry'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent())
        ->toContain('id="countdown-schedule"');
});

it('turns orange in its last quarter, and red once it\'s overdue', function (string $at, string $badge, string $label) {
    $rfq = highPriorityWithDataEntry();
    test()->travelTo(deCountdownClock('2026-10-05 10:00'));
    startDataEntryOn($rfq, 1);

    test()->travelTo(deCountdownClock($at));
    [, $row] = dataEntryListRow();

    expect($row)->toMatch('/badge sourcing-countdown '.$badge.'/')->toContain($label);
})->with([
    'last quarter' => ['2026-10-05 10:50', 'badge-soft-warning', '10m left'],
    'overdue' => ['2026-10-05 11:30', 'badge-soft-danger', 'Overdue by 30m'],
]);

it('only counts working time — paused, and still, at lunch', function () {
    $rfq = highPriorityWithDataEntry();
    test()->travelTo(deCountdownClock('2026-10-05 12:00'));
    startDataEntryOn($rfq, 1);

    // 12:00–12:30 counts; lunch doesn't.
    test()->travelTo(deCountdownClock('2026-10-05 13:00'));
    [$classes, $row] = dataEntryListRow();

    expect($classes)->toContain('rfq-running js-running-row is-paused')
        ->and($row)->toContain('<span class="running-pill-label">Paused</span>')
        ->toContain('data-remaining="1800"')
        ->toMatch('/sourcing-countdown badge-soft-success\s+is-paused/')
        ->toContain('title="Paused — outside working hours"');
});

it('stops the countdown once it\'s sent to finalize, and starts afresh when it comes back', function () {
    $rfq = highPriorityWithDataEntry();
    $dataEntry = userWithRole('Data Entry');
    test()->travelTo(deCountdownClock('2026-10-05 10:00'));
    startDataEntryOn($rfq, 1, $dataEntry);

    test()->travelTo(deCountdownClock('2026-10-05 10:45'));
    $rfq->refresh()->completeDataEntryPart(1, $dataEntry, 'Entered');
    expect($rfq->refresh()->dataEntryCountdown(1))->toBeNull();

    // Back from Sourcing's Finalize: nothing counts until it's started again,
    // and then from the full hour.
    test()->travelTo(deCountdownClock('2026-10-05 11:00'));
    $rfq->refresh()->returnToDataEntry(1, 'Prices are off');
    expect($rfq->refresh()->dataEntryCountdown(1))->toBeNull();

    startDataEntryOn($rfq, 1, $dataEntry);
    test()->travelTo(deCountdownClock('2026-10-05 11:10'));

    expect($rfq->refresh()->dataEntryCountdown(1))->toBe(['target' => 3600, 'remaining' => 3000]);
});
