<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }

    // Monday 5 October, 09:00 in Colombo.
    deClock('09:00');
});

/**
 * Travels to $time ("H:i") on Monday 5 October in Colombo — or $date.
 */
function deClock(string $time, string $date = '2026-10-05'): void
{
    test()->travelTo(CarbonImmutable::parse("{$date} {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * RFQ $number at $priority, its one part handed to Data Entry by Sourcing now.
 */
function handedToDe(string $number, string $priority = 'Medium'): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => $number, 'priority_level' => $priority, 'subject' => "Subject of {$number}"]), [1 => userWithRole('Sourcing')]);
    $rfq->refresh()->completeSourcingPart(1);

    return $rfq->refresh();
}

/**
 * $person's dashboard, for $period.
 */
function deDashboard(User $person, string $period = 'today'): TestResponse
{
    return test()->actingAs($person)->get(route('admin.dashboard', ['period' => $period]))->assertOk();
}

it('gives Data Entry a dashboard of their own — not the people, roles and permissions counts', function () {
    $morgan = userWithRole('Data Entry');

    deDashboard($morgan)
        ->assertViewIs('admin.dashboard.data-entry')
        ->assertSee('Right now')
        ->assertSee('Waiting to start')
        ->assertSee('Your output')
        ->assertSee('Within target, by priority')
        ->assertDontSee('<div class="stat-label">Users</div>', false)
        ->assertDontSee('<div class="stat-label">Roles</div>', false);

    // Admin keeps theirs, even holding Data Entry too.
    deDashboard(userWithRole('Admin')->assignRole('Data Entry'))->assertViewIs('admin.dashboard.admin');
});

it('lists the parts waiting to be started — Urgent first, then longest-waiting — and the queue by priority', function () {
    handedToDe('RFQ5001', 'Low');
    deClock('09:10');
    handedToDe('RFQ5002', 'Urgent');
    handedToDe('RFQ5003', 'Medium');
    // Started by someone else: not waiting.
    startDataEntryOn(handedToDe('RFQ5004', 'Urgent'), 1);

    $response = deDashboard(userWithRole('Data Entry'));
    $waiting = Str::betweenFirst($response->getContent(), 'id="data-entry-waiting"', '</table>');

    expect($waiting)->toContain('RFQ5002')->not->toContain('RFQ5004')
        ->and(strpos($waiting, 'RFQ5002'))->toBeLessThan(strpos($waiting, 'RFQ5003'))
        ->and(strpos($waiting, 'RFQ5003'))->toBeLessThan(strpos($waiting, 'RFQ5001'))
        ->and($response->viewData('overview')['waitingByPriority'])->toBe(['Critical' => 0, 'Urgent' => 1, 'High' => 0, 'Medium' => 1, 'Low' => 1, 'Minimal' => 0, 'Trivial' => 0]);

    $response->assertSeeInOrder(['Waiting to start', '3', '1 urgent']);
});

it('shows the part they\'re on against its target, and nothing about idling', function () {
    $morgan = userWithRole('Data Entry');
    startDataEntryOn(handedToDe('RFQ5101', 'Medium'), 1, $morgan);
    deClock('09:20');

    deDashboard($morgan)
        ->assertSeeInOrder(['Right now', 'RFQ5101', 'Medium', 'On track', '20m of 2h · 1h 40m left'])
        ->assertDontSee('Idle for');
});

it('counts what they sent to finalize — parts, entries, and time against target — over the period', function () {
    $morgan = userWithRole('Data Entry');

    // Urgent (30m target): 09:00–09:20, 12 entries — within it.
    $urgent = startDataEntryOn(handedToDe('RFQ5201', 'Urgent'), 1, $morgan);
    deClock('09:20');
    $urgent->completeDataEntryPart(1, $morgan, null, 12);

    // High (1h target): 09:30–11:00, 30 entries — over it.
    deClock('09:30');
    $high = startDataEntryOn(handedToDe('RFQ5202', 'High'), 1, $morgan);
    deClock('11:00');
    $high->completeDataEntryPart(1, $morgan, null, 30);

    // Someone else's.
    $other = userWithRole('Data Entry');
    startDataEntryOn(handedToDe('RFQ5203'), 1, $other)->completeDataEntryPart(1, $other, null, 99);

    deClock('11:30');
    $response = deDashboard($morgan);
    $overview = $response->viewData('overview');

    expect($overview)->toMatchArray([
        'sent' => 2,
        'entries' => 42,
        'onTime' => 50,
        'onTimeCount' => 1,
        'timedCount' => 2,
        'average' => 55 * 60,
    ])
        ->and(collect($overview['byPriority'])->firstWhere('priority', 'Urgent'))->toMatchArray(['sent' => 1, 'entries' => 12, 'onTime' => 1, 'timed' => 1, 'average' => 20 * 60])
        ->and(collect($overview['byPriority'])->firstWhere('priority', 'High'))->toMatchArray(['sent' => 1, 'onTime' => 0, 'timed' => 1, 'average' => 90 * 60])
        // Today, hour by hour: one at 9 AM, one at 11 AM.
        ->and(array_sum($overview['output']['parts']))->toBe(2)
        ->and($overview['output']['entries'][array_search('11 AM', $overview['output']['labels'], true)])->toBe(30);

    $latest = Str::betweenFirst($response->getContent(), 'id="data-entry-latest"', '</table>');
    expect($latest)->toContain('RFQ5202')->toContain('Over 1h')->toContain('RFQ5201')->toContain('Within 30m')->not->toContain('RFQ5203')
        ->and(strpos($latest, 'RFQ5202'))->toBeLessThan(strpos($latest, 'RFQ5201'));

    // The charts' data: parts span by span, and within target by priority.
    $charts = collect(preg_match_all('/data-dash-chart="([^"]+)"/', $response->getContent(), $matches) ? $matches[1] : [])
        ->map(fn (string $json) => json_decode(html_entity_decode($json, ENT_QUOTES), true));

    expect($charts[0])->toMatchArray(['type' => 'metric'])
        ->and($charts[0]['series'][1]['key'])->toBe('entries')
        ->and($charts[1])->toMatchArray(['type' => 'share', 'total' => 2, 'labels' => ['P1 – Critical', 'P2 – Urgent', 'P3 – High', 'P4 – Medium', 'P5 – Low', 'P6 – Minimal', 'P7 – Trivial']])
        // P2 Urgent, after P1 Critical.
        ->and($charts[1]['series'][0]['values'])->toBe([0, 100, 0, 0, 0, 0, 0])
        ->and($charts[1]['display'][1])->toBe('100% · 1 of 1');

    // Yesterday has none of it; the latest list still does.
    $yesterday = test()->actingAs($morgan)->get(route('admin.dashboard', ['period' => 'custom', 'from' => '2026-10-04', 'to' => '2026-10-04']))->assertOk();
    expect($yesterday->viewData('overview'))->toMatchArray(['sent' => 0, 'entries' => 0, 'onTime' => null])
        ->and(array_sum($yesterday->viewData('overview')['output']['parts']))->toBe(0)
        ->and($yesterday->viewData('overview')['latest'])->toHaveCount(2);
});

it('counts the parts they returned to Sourcing in the period', function () {
    $morgan = userWithRole('Data Entry');
    startDataEntryOn(handedToDe('RFQ5301'), 1, $morgan)->returnSourcingPart(1, 'Prices missing', $morgan);

    expect(deDashboard($morgan)->viewData('overview')['returned'])->toBe(1)
        ->and(deDashboard(userWithRole('Data Entry'))->viewData('overview')['returned'])->toBe(0);
});

it('says when they\'re idle with parts waiting, and alerts them', function () {
    $morgan = userWithRole('Data Entry');
    handedToDe('RFQ5401', 'Urgent');
    deClock('09:30');

    deDashboard($morgan)
        ->assertSee('Idle for 30m')
        ->assertSee('Senior Operations is told after 10 minutes idle.')
        ->assertSee('1 urgent part is waiting');
});

it('says when it\'s outside their hours rather than idle, and shows the hours they work — their role\'s', function () {
    $morgan = userWithRole('Data Entry');
    handedToDe('RFQ5501');

    WorkingSchedule::factory()->create([
        'role_id' => Role::findByName('Data Entry')->id,
        'user_id' => null,
        'week' => collect(Setting::workingHours())->map(fn (array $day) => array_merge($day, ['start' => '07:00', 'end' => '16:00', 'lunch_start' => '12:00', 'lunch_end' => '13:00']))->all(),
        'starts_on' => '2026-10-01',
    ]);

    deClock('16:30');

    deDashboard($morgan)
        ->assertSee('Your hours today: 07:00–16:00 · lunch 12:00–13:00')
        ->assertSee('Outside your working hours')
        ->assertDontSee('Idle for');
});
