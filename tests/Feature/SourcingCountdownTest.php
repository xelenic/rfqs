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
    expect(Setting::sourcingTargets())->toBe(['Trivial' => 2880, 'Minimal' => 2160, 'Low' => 1440, 'Medium' => 960, 'High' => 480, 'Urgent' => 240, 'Critical' => 120]);
});

it('lets an Admin set the targets in hours, to the quarter hour', function () {
    test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit'))->assertOk()
        ->assertSee('Sourcing Targets')
        ->assertSee('name="targets[Urgent]"', false);

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.sourcing-targets'), ['targets' => ['Trivial' => 48, 'Minimal' => 36, 'Low' => 30, 'Medium' => 12, 'High' => 6.5, 'Urgent' => 2.25, 'Critical' => 1.5]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Sourcing targets saved.');

    expect(Setting::sourcingTargets())->toBe(['Trivial' => 2880, 'Minimal' => 2160, 'Low' => 1800, 'Medium' => 720, 'High' => 390, 'Urgent' => 135, 'Critical' => 90]);
});

it('refuses a target that isn\'t sensible', function (mixed $urgent, string $message) {
    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.settings.sourcing-targets'), ['targets' => ['Trivial' => 48, 'Minimal' => 36, 'Low' => 24, 'Medium' => 16, 'High' => 8, 'Urgent' => $urgent, 'Critical' => 1.5]])
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
        ->patch(route('admin.settings.sourcing-targets'), ['targets' => ['Trivial' => 48, 'Minimal' => 36, 'Low' => 1, 'Medium' => 1, 'High' => 1, 'Urgent' => 1, 'Critical' => 1.5]])
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

it('shows no countdown while the part\'s with Data Entry — and Finalize\'s own once it\'s back to finalize', function () {
    $riley = userWithRole('Sourcing');

    test()->travelTo(inColombo('2026-10-05 09:00'));
    $withDataEntry = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8001']), [1 => $riley]);
    $toFinalize = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8002']), [1 => $riley]);

    test()->travelTo(inColombo('2026-10-05 10:00'));
    $withDataEntry->refresh()->completeSourcingPart(1);
    $toFinalize->refresh()->completeSourcingPart(1);
    $toFinalize->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));

    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();

    // Back to finalize: the Finalize target's countdown (FinalizeCountdownTest).
    expect(countdownBadge($html, 'RFQ8001'))->toBeNull()
        ->and(countdownBadge($html, 'RFQ8002'))->toContain('data-running-title="Working time left to finalize it · ');
});

it('carries a part sent back to Sourcing on from where it was, on their Returns list — not from the start', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');

    test()->travelTo(inColombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8101', 'priority_level' => 'Urgent']), [1 => $riley]);

    test()->travelTo(inColombo('2026-10-05 10:00'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1, $dataEntry);

    test()->travelTo(inColombo('2026-10-05 10:30'));
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $dataEntry);

    // An hour on: the Urgent target (4h) less the hour before it went to
    // Data Entry and the hour since it came back — the half hour with Data
    // Entry not counted.
    test()->travelTo(inColombo('2026-10-05 11:30'));
    $html = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Time left')
        ->assertSee('id="countdown-schedule"', false)
        ->getContent();

    expect(countdownBadge($html, 'RFQ8101'))
        ->toContain('badge-soft-success')
        ->toContain('data-remaining="7200" data-target="14400"')
        ->toContain('2h left')
        ->toContain('data-worker="'.$riley->id.'"');

    // The time counts on the RFQ too: 09–10 and 10:30–11:30, two rounds.
    expect($rfq->refresh()->timeSpent(inColombo('2026-10-05 11:30'))['roles']['Sourcing'])
        ->toMatchArray(['minutes' => 120, 'rounds' => 2, 'ongoing' => true]);
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

it('counts a part late across its rounds with the same member — on the deadline too', function () {
    $riley = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');

    // Urgent: 4 hours. Three hours, then back from Data Entry for two more.
    test()->travelTo(inColombo('2026-10-05 08:30'));
    $rfq = splitAmong(Rfq::factory()->create(['priority_level' => 'Urgent']), [1 => $riley]);
    test()->travelTo(inColombo('2026-10-05 11:30'));
    $rfq->refresh()->completeSourcingPart(1);
    startDataEntryOn($rfq, 1, $dataEntry);
    test()->travelTo(inColombo('2026-10-05 11:45'));
    $rfq->refresh()->returnSourcingPart(1, 'Prices missing', $dataEntry);

    // Each round alone was within four hours; together they're an hour over.
    test()->travelTo(inColombo('2026-10-05 14:45'));
    expect($rfq->refresh()->sourcingCountdown(1))->toBe(['target' => 14400, 'remaining' => -3600]);

    $rfq->completeSourcingPart(1);
    expect($rfq->refresh()->sourcingDeadline())->toMatchArray(['remaining' => null, 'late_parts' => 1, 'late_by' => 3600, 'exceeded' => true]);
});

it('starts a part afresh with whoever it\'s reassigned to', function () {
    $riley = userWithRole('Sourcing');
    $sam = userWithRole('Sourcing');

    test()->travelTo(inColombo('2026-10-05 09:00'));
    $rfq = splitAmong(Rfq::factory()->create(['priority_level' => 'Urgent']), [1 => $riley]);

    // Riley has it three hours; then it's Sam's.
    test()->travelTo(inColombo('2026-10-05 12:00'));
    $rfq->refresh()->replaceSourcingPart(1, $sam, userWithRole('Senior Operations'), 'Riley is away');

    // Sam's hour and a half since (lunch aside) — not Riley's three as well.
    test()->travelTo(inColombo('2026-10-05 14:30'));
    expect($rfq->refresh()->sourcingCountdown(1))->toBe(['target' => 14400, 'remaining' => 14400 - 5400]);
});
