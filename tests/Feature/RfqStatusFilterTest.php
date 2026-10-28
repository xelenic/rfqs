<?php

use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
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
 * Travels to $time in Colombo on the same Monday ("H:i").
 */
function statusAt(string $time): void
{
    test()->travelTo(Carbon::parse("2026-10-05 {$time}:00", 'Asia/Colombo')->utc());
}

/**
 * Every status by 14:30. An Urgent part with Sourcing since 09:00, 30
 * minutes past its 4 hours (overdue). Another since 10:00, half an hour
 * left (due soon). A Low one on track. One nobody's assigned. One on
 * hold, one cancelled, and two closed: today and last week.
 */
function everyStatus(): void
{
    $sourcing = userWithRole('Sourcing');

    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ5001', 'priority_level' => 'Urgent', 'subject' => 'Fire pump']), [1 => $sourcing]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ5003', 'priority_level' => 'Low']), [1 => $sourcing]);
    Rfq::factory()->create(['rfq_number' => 'RFQ5004', 'priority_level' => 'Medium']);
    Rfq::factory()->create(['rfq_number' => 'RFQ5005', 'status' => Rfq::ON_HOLD]);
    Rfq::factory()->create(['rfq_number' => 'RFQ5006', 'status' => Rfq::CANCELLED]);
    Rfq::factory()->create(['rfq_number' => 'RFQ5008', 'status' => 'Completed', 'stage' => 'closed', 'bd_closed_at' => now()->subWeek()]);

    statusAt('10:00');
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ5002', 'priority_level' => 'Urgent']), [1 => $sourcing]);

    statusAt('14:30');
    Rfq::factory()->create(['rfq_number' => 'RFQ5007', 'status' => 'Completed', 'stage' => 'closed', 'bd_closed_at' => now()]);
}

/**
 * The RFQ numbers All RFQs lists for $viewer with $query.
 *
 * @param  array<string, mixed>  $query
 * @return array<int, string>
 */
function listedWithStatus(User $viewer, array $query): array
{
    return test()->actingAs($viewer)->get(route('admin.rfqs.index', $query))->assertOk()
        ->viewData('rfqs')->pluck('rfq_number')->sort()->values()->all();
}

// ---- All RFQs' Status filter -----------------------------------------------

it('narrows All RFQs to each status', function (string $filter, array $expected) {
    everyStatus();

    expect(listedWithStatus(userWithRole('Admin'), ['state' => $filter]))->toBe($expected);
})->with([
    'pending — every open RFQ, whatever its timing' => ['pending', ['RFQ5001', 'RFQ5002', 'RFQ5003', 'RFQ5004']],
    'overdue — a task past its target' => ['overdue', ['RFQ5001']],
    'due soon — under a quarter of the target left' => ['due_soon', ['RFQ5002']],
    'on hold' => ['on_hold', ['RFQ5005']],
    'cancelled' => ['cancelled', ['RFQ5006']],
    'closed' => ['closed', ['RFQ5007', 'RFQ5008']],
]);

it('lists every RFQ with no status, or one it doesn\'t know', function (array $query) {
    everyStatus();

    expect(listedWithStatus(userWithRole('Admin'), $query))->toHaveCount(8);
})->with([
    'none' => [[]],
    'unknown' => [['state' => 'lost']],
]);

it('has the Status filter on All RFQs, saying what it shows and how to clear it', function () {
    everyStatus();

    $page = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['state' => 'overdue']))->assertOk();
    $filters = Str::betweenFirst($page->getContent(), 'id="rfqListFilterForm"', '</form>');

    expect($filters)->toContain('name="state"')
        ->toContain('<option value="">Status: All</option>')
        ->toContain('<option value="overdue" selected>Status: Overdue</option>')
        ->toContain('Status: Due soon')
        ->toContain('Status: On Hold')
        ->toContain('Status: Cancelled')
        ->toContain('Status: Closed')
        ->toContain('list-filter-clear');

    $page->assertSee('data-all-rfqs-status="overdue"', false)
        ->assertSeeInOrder(['RFQs with status', 'Overdue', 'a task running past its target', '1 RFQ.'], false)
        ->assertSee('href="'.e(route('admin.rfqs.index')).'" class="ms-auto"', false);
});

it('combines the Status filter with Show', function () {
    everyStatus();

    expect(listedWithStatus(userWithRole('Admin'), ['show' => 'not_finalized', 'state' => 'due_soon']))->toBe(['RFQ5002']);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['show' => 'open', 'state' => 'overdue']))->assertOk()
        ->assertSee('data-all-rfqs-view="open"', false)
        ->assertSeeInOrder(['Open RFQs', ', status', 'Overdue', '1 RFQ.'], false);
});

it('marks All RFQs\' rows Overdue or Due soon, with the tasks behind it', function () {
    everyStatus();

    $main = pageMain(test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index'))->assertOk());
    $row = fn (string $number) => Str::before(Str::after($main, $number), '</tr>');

    expect($row('RFQ5001'))->toContain('data-target-state="overdue"')
        ->toContain('title="Sourcing · RFQ5001 · 30m over"')
        ->and($row('RFQ5002'))->toContain('data-target-state="due_soon"')
        ->toContain('title="Sourcing · RFQ5002 · 30m left"')
        ->and($row('RFQ5003'))->not->toContain('data-target-state')
        ->and($row('RFQ5004'))->not->toContain('data-target-state');
});

it('gives the Status filter to everyone with All RFQs, and keeps it off every other list', function () {
    everyStatus();

    foreach (['General Manager', 'Senior Operations'] as $role) {
        expect(listedWithStatus(userWithRole($role), ['state' => 'overdue']))->toBe(['RFQ5001']);
    }

    // Senior Operations' own Unassigned page, and a list for someone without All RFQs.
    foreach ([['Senior Operations', ['status' => 'Pending', 'state' => 'overdue']], ['Head of Business Development', ['state' => 'overdue']]] as [$role, $query]) {
        $page = test()->actingAs(userWithRole($role))->get(route('admin.rfqs.index', $query))->assertOk();

        expect($page->getContent())->not->toContain('name="state"')
            ->not->toContain('data-all-rfqs-status')
            ->not->toContain('data-target-state');
    }

    expect(listedWithStatus(userWithRole('Head of Business Development'), ['state' => 'overdue']))->toHaveCount(8);
});

// ---- Senior Operations' dashboard ------------------------------------------

it('gives Senior Operations a dashboard of RFQs by status, each card opening All RFQs on it', function () {
    everyStatus();

    $response = test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.dashboard'))->assertOk()
        ->assertViewIs('admin.dashboard.senior-operations');

    expect(collect($response->viewData('overview')['statuses'])->pluck('value', 'key')->all())->toBe([
        'pending' => 4,
        'overdue' => 1,
        'due_soon' => 1,
        'on_hold' => 1,
        'cancelled' => 1,
        'closed' => 2,
    ]);

    $cards = Str::betweenFirst($response->getContent(), 'id="rfq-statuses"', 'class="row g-3"');

    foreach (Rfq::STATUS_FILTERS as $filter => $label) {
        expect($cards)->toContain('href="'.e(route('admin.rfqs.index', ['state' => $filter])).'" class="stat-card-link" data-status-card="'.$filter.'"')
            ->toContain('<span class="stat-label">'.$label.'</span>');
    }

    expect($cards)->toContain('2 urgent')
        ->toContain('1 closed today');

    $response->assertDontSee('<div class="stat-label">Users</div>', false);

    // The latest RFQs say which are overdue or due soon.
    $recent = Str::after($response->getContent(), 'Recent RFQs');

    expect(Str::before(Str::after($recent, 'Fire pump'), '</tr>'))->toContain('data-target-state="overdue"');
});

it('shows Senior Operations every task running now, overdue first, as Admin\'s dashboard does', function () {
    everyStatus();

    $response = test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.dashboard'))->assertOk();
    $tasks = Str::betweenFirst($response->getContent(), 'id="ongoing-tasks"', '</table>');

    expect($tasks)->toContain("Today's ongoing tasks")
        ->and(substr_count($tasks, 'class="task-sheet-task"'))->toBe(3);

    // The Urgent part past its 4 hours, the one nearly there, then the one on track.
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.dashboard'))
        ->assertSeeInOrder(['id="ongoing-tasks"', 'RFQ5001', 'Sourcing · Fire pump', '30m over 4h', 'Overdue', 'RFQ5002', 'Due soon', 'RFQ5003', 'On track', 'RFQ Activity'], false);

    // They can't open Users, so the names aren't links; for someone who can, they are.
    $admin = userWithRole('Admin');
    $admin->givePermissionTo(Permission::findOrCreate('users.view'));

    expect($tasks)->not->toContain('/admin/users/');
    expect(Str::betweenFirst(test()->actingAs($admin)->get(route('admin.dashboard'))->getContent(), 'id="ongoing-tasks"', '</table>'))
        ->toContain('/admin/users/');
});

it('counts on each card just what its list shows', function () {
    everyStatus();
    $ops = userWithRole('Senior Operations');

    $statuses = test()->actingAs($ops)->get(route('admin.dashboard'))->assertOk()->viewData('overview')['statuses'];

    foreach ($statuses as $card) {
        expect(listedWithStatus($ops, ['state' => $card['key']]))->toHaveCount($card['value']);
    }
});

it('gives Senior Operations the All RFQs link', function () {
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.dashboard'))->assertOk()
        ->assertSee('href="'.route('admin.rfqs.index').'" class="nav-link', false)
        ->assertSee('All RFQs');

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index'))->assertOk()
        ->assertSee('<title>All RFQs', false)
        ->assertSee('name="show"', false)
        ->assertSee('name="state"', false);
});

it('keeps the general dashboard for Senior Operations who also hold a role with one of its own', function () {
    $both = userWithRole('Senior Operations')->assignRole('Sourcing');

    test()->actingAs($both)->get(route('admin.dashboard'))->assertOk()->assertViewIs('admin.dashboard.sourcing');
});
