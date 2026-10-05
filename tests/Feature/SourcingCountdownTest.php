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
 * 2026-10-05 is a Monday; times in the working hours' own zone.
 */
function inColombo(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, Setting::DEFAULT_TIMEZONE);
}

/**
 * The countdown badge on $member's Pending list for $rfqNumber's row, or null.
 */
function countdownBadge(string $html, string $rfqNumber): ?string
{
    $row = Str::betweenFirst($html, '<td class="text-nowrap">'.$rfqNumber.'</td>', '</tr>');

    return preg_match('/<span class="badge sourcing-countdown[^"]*"[^>]*>.*?<\/span>\s*<\/span>/s', $row, $match) ? $match[0] : null;
}

// ---- The targets -----------------------------------------------------------------

it('starts with a target per priority, in working minutes', function () {
    expect(Setting::sourcingTargets())->toBe(['Low' => 1440, 'Medium' => 960, 'High' => 480, 'Urgent' => 240]);
});

it('lets an Admin set the targets in hours, to the quarter hour', function () {
    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('Sourcing Targets')
        ->assertSee('name="targets[Urgent]"', false);

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.sourcing-targets'), ['targets' => ['Low' => 30, 'Medium' => 12, 'High' => 6.5, 'Urgent' => 2.25]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Sourcing targets saved.');

    expect(Setting::sourcingTargets())->toBe(['Low' => 1800, 'Medium' => 720, 'High' => 390, 'Urgent' => 135]);
});

it('refuses a target that isn\'t sensible', function (mixed $urgent, string $message) {
    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.sourcing-targets'), ['targets' => ['Low' => 24, 'Medium' => 16, 'High' => 8, 'Urgent' => $urgent]])
        ->assertSessionHasErrorsIn('sourcing_targets', ['targets.Urgent' => $message]);

    expect(Setting::sourcingTargets()['Urgent'])->toBe(240);
})->with([
    'missing' => [null, 'Give the Urgent target.'],
    'not a number' => ['soon', 'Give the Urgent target in hours, like 4 or 1.5.'],
    'too short' => [0, 'Keep the Urgent target between 0.25 and 1000 hours.'],
    'not a quarter hour' => [1.1, 'Give the Urgent target to the quarter hour, like 1.25.'],
]);

it('keeps the targets to an Admin', function () {
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.settings.edit'))->assertOk()
        ->assertDontSee('Sourcing Targets');

    test()->actingAs(userWithRole('Sourcing'))
        ->patch(route('admin.settings.sourcing-targets'), ['targets' => ['Low' => 1, 'Medium' => 1, 'High' => 1, 'Urgent' => 1]])
        ->assertForbidden();
});

// ---- The countdown --------------------------------------------------------------

it('counts each of a member\'s parts down from its priority\'s target, on their Pending list', function () {
    $riley = userWithRole('Sourcing');

    test()->travelTo(inColombo('2026-10-05 09:00'));
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8001', 'priority_level' => 'Urgent']), [1 => $riley]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8002', 'priority_level' => 'High']), [1 => $riley]);

    test()->travelTo(inColombo('2026-10-05 10:00'));
    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Time left')
        ->assertSee('id="countdown-schedule"', false)
        ->getContent();

    expect(countdownBadge($html, 'RFQ8001'))
        ->toContain('badge-soft-success')
        ->toContain('data-remaining="10800" data-target="14400"')
        ->toContain('3h left')
        ->not->toContain('is-paused')
        ->and(countdownBadge($html, 'RFQ8002'))->toContain('7h left');
});

it('turns amber in its last quarter, and red once it\'s overdue', function () {
    $riley = userWithRole('Sourcing');

    test()->travelTo(inColombo('2026-10-05 09:00'));
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8001', 'priority_level' => 'Urgent']), [1 => $riley]);

    // 09:00–12:30 is 3h 30m of the 4h; lunch doesn't count.
    test()->travelTo(inColombo('2026-10-05 13:30'));
    expect(countdownBadge(test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), 'RFQ8001'))
        ->toContain('badge-soft-warning')
        ->toContain('30m left');

    test()->travelTo(inColombo('2026-10-05 15:30'));
    expect(countdownBadge(test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), 'RFQ8001'))
        ->toContain('badge-soft-danger')
        ->toContain('Overdue by 1h 30m');
});

it('pauses outside working hours', function () {
    $riley = userWithRole('Sourcing');

    test()->travelTo(inColombo('2026-10-05 16:00'));
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8001', 'priority_level' => 'High']), [1 => $riley]);

    // The evening and the night don't count: still 7h left the next morning.
    test()->travelTo(inColombo('2026-10-06 07:00'));
    expect(countdownBadge(test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), 'RFQ8001'))
        ->toContain('is-paused')
        ->toContain('bi-pause-circle')
        ->toContain('7h left');
});

it('follows the targets as set', function () {
    $riley = userWithRole('Sourcing');
    Setting::put('sourcing_targets', json_encode(['Low' => 1440, 'Medium' => 120, 'High' => 480, 'Urgent' => 240]));

    test()->travelTo(inColombo('2026-10-05 09:00'));
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8001', 'priority_level' => 'Medium']), [1 => $riley]);
    test()->travelTo(inColombo('2026-10-05 10:15'));

    expect(countdownBadge(test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), 'RFQ8001'))
        ->toContain('45m left');
});

it('shows no countdown once the part\'s with Data Entry, or waiting on Finalize', function () {
    $riley = userWithRole('Sourcing');

    test()->travelTo(inColombo('2026-10-05 09:00'));
    $withDataEntry = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8001']), [1 => $riley]);
    $toFinalize = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8002']), [1 => $riley]);

    test()->travelTo(inColombo('2026-10-05 10:00'));
    $withDataEntry->refresh()->completeSourcingPart(1);
    $toFinalize->refresh()->completeSourcingPart(1);
    $toFinalize->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    expect(countdownBadge($html, 'RFQ8001'))->toBeNull()
        ->and(countdownBadge($html, 'RFQ8002'))->toBeNull();
});

it('gives the browser the working periods ahead to tick against', function () {
    $riley = userWithRole('Sourcing');
    test()->travelTo(inColombo('2026-10-05 10:00'));
    splitAmong(Rfq::factory()->create(), [1 => $riley]);

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent();
    $schedule = json_decode(Str::betweenFirst($html, '<script type="application/json" id="countdown-schedule">', '</script>'), true);

    expect($schedule['now'])->toBe(inColombo('2026-10-05 10:00')->getTimestamp())
        // The rest of Monday morning, then its afternoon.
        ->and(array_slice($schedule['periods'], 0, 2))->toBe([
            [inColombo('2026-10-05 10:00')->getTimestamp(), inColombo('2026-10-05 12:30')->getTimestamp()],
            [inColombo('2026-10-05 13:30')->getTimestamp(), inColombo('2026-10-05 17:00')->getTimestamp()],
        ]);

    // Nobody else's list has one.
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending']))
        ->assertDontSee('id="countdown-schedule"', false);
});

it('reads the countdown labels the way the page shows them', function (int $seconds, string $label) {
    expect(Setting::countdownLabel($seconds))->toBe($label);
})->with([
    [3 * 3600 + 20 * 60, '3h 20m left'],
    [45 * 60, '45m left'],
    [30, 'Under 1m left'],
    [0, 'Due now'],
    [-90 * 60, 'Overdue by 1h 30m'],
]);
