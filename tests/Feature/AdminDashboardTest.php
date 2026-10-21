<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Monday 5 October, 9:00 in Colombo — the start of a working morning.
    test()->travelTo(Carbon::parse('2026-10-05 03:30:00'));

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Admin's dashboard, as an Admin sees it.
 */
/**
 * Admin's dashboard, as an Admin sees it — with $query in its address (the
 * period, say).
 *
 * @param  array<string, mixed>  $query
 */
function adminDashboard(array $query = []): TestResponse
{
    return test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard', $query))->assertOk();
}

/**
 * What Admin's dashboard is drawn from (DashboardController::adminOverview()).
 *
 * @return array<string, mixed>
 */
function adminOverviewNow(array $query = []): array
{
    return adminDashboard($query)->viewData('overview');
}

/**
 * One of the headline figures, by its label.
 *
 * @param  array<string, mixed>  $overview
 * @return array<string, mixed>
 */
function adminKpi(array $overview, string $label): array
{
    return collect($overview['kpis'])->firstWhere('label', $label);
}

/**
 * Travels to $time in Colombo on the same Monday ("H:i").
 */
function colomboAt(string $time): void
{
    test()->travelTo(Carbon::parse("2026-10-05 {$time}:00", 'Asia/Colombo')->utc());
}

// ---- the page --------------------------------------------------------------

it('gives Admin a dashboard of its own, without the users, roles and permissions counts', function () {
    adminDashboard()
        ->assertViewIs('admin.dashboard.admin')
        ->assertDontSee('<div class="stat-label">Users</div>', false)
        ->assertDontSee('<div class="stat-label">Roles</div>', false)
        ->assertDontSee('<div class="stat-label">Permissions</div>', false)
        ->assertSeeInOrder(['Open RFQs', 'Pending tasks', 'In progress', 'Overdue', 'Finished', 'Data Entry idle'])
        ->assertSeeInOrder(['id="ongoing-tasks"', 'id="pending-by-stage"', 'id="team-performance"', 'id="data-entry-floor"', 'id="throughputChart"', 'id="workloadChart"', 'id="rfqActivityChart"'], false)
        ->assertSee('js/admin-dashboard.js', false)
        ->assertSee('Nobody\'s on a task right now.', false);
});

// ---- ongoing tasks ---------------------------------------------------------

it('lists every task someone\'s on, overdue first, against its target', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3001', 'priority_level' => 'Urgent']), [1 => $riley]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3002', 'priority_level' => 'Low']), [1 => $sam]);

    // 14:30: 4½ working hours in, lunch aside — Urgent's 4 hours are up.
    colomboAt('14:30');

    $overview = adminOverviewNow();

    expect($overview['ongoing']->pluck('label')->all())->toBe(['RFQ3001', 'RFQ3002'])
        ->and($overview['ongoing'][0])->toMatchArray(['task' => 'Sourcing', 'state' => 'overdue', 'target' => 4 * 3600, 'remaining' => -1800, 'seconds' => 16200])
        ->and($overview['ongoing'][0]['worker']->id)->toBe($riley->id)
        ->and($overview['ongoing'][1]['state'])->toBe('on_track')
        ->and(adminKpi($overview, 'In progress'))->toMatchArray(['value' => 2, 'caption' => '2 people working'])
        ->and(adminKpi($overview, 'Overdue'))->toMatchArray(['value' => 1, 'alert' => true]);

    adminDashboard()->assertSeeInOrder(['RFQ3001', e($riley->name), '30m over 4h', 'Overdue', 'RFQ3002', e($sam->name), 'On track'], false);
});

// ---- the team --------------------------------------------------------------

it('shows how each tracked person is doing: finished, how long each took, and on time', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $quick = splitAmong(Rfq::factory()->create(['priority_level' => 'Low']), [1 => $riley]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3102']), [1 => $sam]);

    colomboAt('10:00');
    $quick->completeSourcingPart(1);

    $overview = adminOverviewNow();
    $rows = $overview['team']->keyBy(fn (array $row) => $row['user']->id);

    expect($rows[$riley->id])->toMatchArray(['role' => 'Sourcing', 'done' => 1, 'average' => 3600, 'onTime' => 100, 'inHand' => 0, 'state' => 'Free'])
        ->and($rows[$sam->id])->toMatchArray(['done' => 0, 'average' => null, 'onTime' => null, 'inHand' => 1, 'state' => 'Working', 'detail' => 'RFQ3102'])
        // Most finished first.
        ->and($overview['team']->first()['user']->id)->toBe($riley->id)
        ->and(adminKpi($overview, 'Finished')['value'])->toBe(1)
        // The charts: a column a day over the 7 days, today's last; and each person's two bars.
        ->and($overview['throughput']['total'])->toBe(1)
        ->and(collect($overview['throughput']['series'])->firstWhere('label', 'Sourcing')['values'])->toHaveCount(7)
        ->and(last(collect($overview['throughput']['series'])->firstWhere('label', 'Sourcing')['values']))->toBe(1)
        ->and($overview['workload']['labels'])->toBe([$riley->name, $sam->name])
        ->and($overview['workload']['inHand'])->toBe([0, 1])
        ->and($overview['workload']['done'])->toBe([1, 0]);

    adminDashboard()
        ->assertSee('href="'.route('admin.users.show', $riley).'" class="dash-person"', false)
        ->assertSee('data-dash-chart=', false);
});

// ---- Data Entry ------------------------------------------------------------

it('shows who in Data Entry is working, and who\'s idle with parts waiting, for how long', function () {
    $riley = userWithRole('Sourcing');
    [$idle, $busy] = [userWithRole('Data Entry'), userWithRole('Data Entry')];

    $waiting = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $waiting->completeSourcingPart(1);
    $started = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3201']), [1 => $riley]);
    $started->completeSourcingPart(1);
    startDataEntryOn($started->refresh(), 1, $busy);

    colomboAt('09:30');

    $overview = adminOverviewNow();
    $floor = $overview['dataEntry'];
    $people = $floor['people']->keyBy(fn (array $row) => $row['user']->id);

    expect($people[$idle->id])->toMatchArray(['state' => 'Idle', 'tone' => 'danger', 'seconds' => 1800, 'detail' => '30m'])
        ->and($people[$busy->id])->toMatchArray(['state' => 'Working', 'detail' => 'RFQ3201 · 30m'])
        ->and($floor)->toMatchArray(['idleCount' => 1, 'idleSeconds' => 1800, 'waiting' => 1, 'alertOn' => true])
        ->and(adminKpi($overview, 'Data Entry idle'))->toMatchArray(['value' => 1, 'caption' => '30m idle in all', 'alert' => true]);

    // The team table has them the same way.
    expect($overview['team']->keyBy(fn (array $row) => $row['user']->id)[$idle->id]['state'])->toBe('Idle');
});

// ---- what's waiting --------------------------------------------------------

it('counts what\'s waiting at each stage, each leading to Admin\'s view of that page', function () {
    Rfq::factory()->count(2)->create(['priority_level' => 'Urgent']);
    Rfq::factory()->create(['priority_level' => 'Low']);

    $overview = adminOverviewNow();
    $awaiting = collect($overview['pipeline'])->firstWhere('label', 'Awaiting assignment');

    expect($awaiting)->toMatchArray(['count' => 3, 'url' => route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'senior-operations'])])
        ->and(adminKpi($overview, 'Pending tasks')['value'])->toBe(collect($overview['pipeline'])->sum('count'))
        ->and(adminKpi($overview, 'Open RFQs'))->toMatchArray(['value' => 3, 'caption' => '2 urgent', 'alert' => true])
        ->and($overview['priorities'])->toBe(['Critical' => 0, 'Urgent' => 2, 'High' => 0, 'Medium' => 0, 'Low' => 1, 'Minimal' => 0, 'Trivial' => 0]);

    adminDashboard()->assertSee('href="'.e(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'senior-operations'])).'" class="pipeline-label"', false);
});

// ---- the period ------------------------------------------------------------

/**
 * A Sourcing part $holder finished an hour after it was given to them, at
 * 09:00 Colombo on $date.
 */
function partFinishedOn(string $date, User $holder): Rfq
{
    test()->travelTo(Carbon::parse("{$date} 09:00:00", 'Asia/Colombo')->utc());
    $rfq = splitAmong(Rfq::factory()->create(['priority_level' => 'Low']), [1 => $holder]);

    test()->travelTo(Carbon::parse("{$date} 10:00:00", 'Asia/Colombo')->utc());
    $rfq->completeSourcingPart(1);

    return $rfq;
}

it('covers the last 7 days to begin with, offering Today, 30 and 90 days, and dates of your own', function () {
    $response = adminDashboard()
        ->assertSee('id="dashboardPeriodForm"', false)
        ->assertSee('<input type="radio" name="period" value="week" checked>', false)
        ->assertSeeInOrder(['Today', '7 days', '30 days', '90 days', 'Custom'])
        ->assertSee('Sep 29 – Oct 5')
        // The activity chart follows the period, with no range of its own.
        ->assertDontSee('aria-label="Time range"', false);

    expect($response->viewData('period'))->toMatchArray(['key' => 'week', 'from' => '2026-09-29', 'to' => '2026-10-05']);
});

it('scopes what\'s been finished, the team and the charts to the period — what\'s open stays as of now', function () {
    $riley = userWithRole('Sourcing');
    partFinishedOn('2026-09-25', $riley);
    partFinishedOn('2026-10-05', $riley);
    splitAmong(Rfq::factory()->create(), [1 => $riley]); // in hand, now

    $week = adminOverviewNow();
    $month = adminOverviewNow(['period' => 'month']);
    $today = adminOverviewNow(['period' => 'today']);

    expect(adminKpi($week, 'Finished')['value'])->toBe(1)
        ->and(adminKpi($month, 'Finished')['value'])->toBe(2)
        ->and(adminKpi($today, 'Done today')['value'])->toBe(1)
        ->and($week['team']->first()['done'])->toBe(1)
        ->and($month['team']->first()['done'])->toBe(2)
        ->and($month['workload']['done'])->toBe([2])
        ->and($month['throughput']['total'])->toBe(2)
        // As of now, whatever the period.
        ->and(adminKpi($week, 'In progress')['value'])->toBe(1)
        ->and(adminKpi($month, 'In progress')['value'])->toBe(1)
        ->and($week['team']->first()['inHand'])->toBe(1)
        ->and($month['team']->first()['inHand'])->toBe(1);
});

it('draws a single day by the hour, two months by the day, and longer by the week', function () {
    $hours = adminOverviewNow(['period' => 'today'])['throughput'];
    $days = adminOverviewNow(['period' => 'month'])['throughput'];
    $weeks = adminOverviewNow(['period' => 'quarter'])['throughput'];

    expect($hours['labels'])->toHaveCount(24)
        ->and($hours['labels'][0])->toBe('12 AM')
        ->and($hours['dates'][9])->toBe('Mon, Oct 5 · 9 AM')
        ->and($days['labels'])->toHaveCount(30)
        ->and(last($days['labels']))->toBe('Oct 5')
        // 90 days back from Monday 5 October is Thursday 8 July: weeks from Monday 6 July.
        ->and($weeks['dates'][0])->toBe('Week of Jul 6')
        ->and(last($weeks['dates']))->toBe('Week of Oct 5')
        ->and($weeks['labels'])->toHaveCount(14);
});

it('covers dates of your own, and the RFQs created in them', function () {
    $riley = userWithRole('Sourcing');
    partFinishedOn('2026-09-25', $riley);
    partFinishedOn('2026-10-05', $riley);

    $response = adminDashboard(['period' => 'custom', 'from' => '2026-09-24', 'to' => '2026-09-26'])
        ->assertSee('<input type="radio" name="period" value="custom" checked>', false)
        ->assertSee('value="2026-09-24"', false)
        ->assertSee('Sep 24 – Sep 26');

    $overview = $response->viewData('overview');

    expect(adminKpi($overview, 'Finished')['value'])->toBe(1)
        ->and($overview['throughput']['dates'])->toBe(['Thu, Sep 24', 'Fri, Sep 25', 'Sat, Sep 26'])
        ->and($response->viewData('rfqActivity'))->toMatchArray(['labels' => ['Sep 24', 'Sep 25', 'Sep 26'], 'pending' => [0, 1, 0], 'completed' => [0, 0, 0]]);
});
