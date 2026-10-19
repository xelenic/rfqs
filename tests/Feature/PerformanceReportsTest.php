<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\Setting;
use App\Models\User;
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
 * Travels to $time on Monday 5 October 2026 ("H:i"), or another day
 * ("Y-m-d H:i") — in Colombo: 08:30–17:00, lunch 12:30–13:30.
 */
function reportClock(string $time): void
{
    test()->travelTo(CarbonImmutable::parse(str_contains($time, '-') ? $time : "2026-10-05 {$time}", Setting::DEFAULT_TIMEZONE));
}

/**
 * Monday's work: Riley Chen and Sam Rivera in Sourcing, Morgan Ito in Data
 * Entry. From 08:30 Riley has RFQ8001 (10 items), RFQ8002 (5 items) and part
 * 1 of RFQ8003 (8 items), Sam its part 2. Morgan does RFQ8001's data entry
 * 09:00–11:00, adding 7 line items, and Riley finalizes it at 12:00. RFQ8002
 * goes on hold at 10:00, waiting for the customer's clarification.
 *
 * @return array{riley: User, sam: User, morgan: User, ops: User, finalized: Rfq, waiting: Rfq, shared: Rfq}
 */
function mondaysWork(): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $sam = tap(userWithRole('Sourcing'))->update(['name' => 'Sam Rivera']);
    $morgan = tap(userWithRole('Data Entry'))->update(['name' => 'Morgan Ito']);
    $ops = userWithRole('Senior Operations');

    reportClock('08:30');
    $finalized = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8001', 'wc_number' => 'WC-101', 'subject' => 'Kitchen items', 'number_of_items' => 10, 'priority_level' => 'High']), [1 => $riley]);
    $waiting = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8002', 'wc_number' => 'WC-102', 'subject' => 'Valves', 'number_of_items' => 5, 'priority_level' => 'Low']), [1 => $riley]);
    $shared = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8003', 'wc_number' => 'WC-103', 'subject' => 'Pumps', 'number_of_items' => 8, 'priority_level' => 'Urgent']), [1 => $riley, 2 => $sam]);

    reportClock('08:45');
    $finalized->completeSourcingPart(1);
    reportClock('09:00');
    startDataEntryOn($finalized, 1, $morgan);
    reportClock('10:00');
    $waiting->changeStatus(Rfq::ON_HOLD, $ops, 'Asked the customer which sizes', true);
    reportClock('11:00');
    $finalized->refresh()->completeDataEntryPart(1, $morgan, 'Prices entered', 7);
    reportClock('12:00');
    $finalized->refresh()->finalizePart(1);
    reportClock('17:00');

    return compact('riley', 'sam', 'morgan', 'ops', 'finalized', 'waiting', 'shared');
}

/**
 * What a report hands its page, as Admin asks for it.
 *
 * @param  array<string, mixed>  $query
 */
function reportData(string $report, array $query = []): mixed
{
    return test()->actingAs(userWithRole('Admin'))->get(route("admin.reports.{$report}", $query))->assertOk()->viewData('report');
}

/**
 * What a report hands its page, as $user asks for it.
 *
 * @param  array<string, mixed>  $query
 */
function reportDataAs(User $user, string $report, array $query = []): mixed
{
    return test()->actingAs($user)->get(route("admin.reports.{$report}", $query))->assertOk()->viewData('report');
}

/**
 * A row's figures, without who and what's behind them.
 *
 * @param  array<string, mixed>  $row
 * @return array<string, mixed>
 */
function figuresOf(array $row): array
{
    return collect($row)->except(['user', 'ids', 'durations'])->all();
}

// ---- Sourcing: daily -----------------------------------------------------------

it('gives each Sourcing member\'s day: finalized, pending and waiting for the customer, with items', function () {
    ['riley' => $riley, 'sam' => $sam] = mondaysWork();

    $report = reportData('sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-05']);

    expect($report['rows']->map(fn (array $row) => [$row['user']->name => figuresOf($row)])->all())->toBe([
        ['Riley Chen' => ['date' => '2026-10-05', 'finalized' => 1, 'finalized_items' => 10, 'pending' => 1, 'pending_items' => 8, 'customer' => 1]],
        ['Sam Rivera' => ['date' => '2026-10-05', 'finalized' => 0, 'finalized_items' => 0, 'pending' => 1, 'pending_items' => 8, 'customer' => 0]],
    ]);

    // RFQ8003 is both of theirs: once in the total.
    expect(figuresOf($report['total']))->toBe(['finalized' => 1, 'finalized_items' => 10, 'pending' => 1, 'pending_items' => 8, 'customer' => 1]);
});

it('keeps each day as it was at its end', function () {
    ['riley' => $riley, 'ops' => $ops, 'waiting' => $waiting] = mondaysWork();

    // Tuesday the customer answers; a new RFQ comes in.
    reportClock('2026-10-06 09:00');
    $waiting->refresh()->changeStatus('Pending', $ops);
    splitAmong(Rfq::factory()->create(['number_of_items' => 4]), [1 => $riley]);
    reportClock('2026-10-06 17:00');

    $rows = reportData('sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-06', 'people' => [$riley->id]])['rows'];

    expect($rows->map(fn (array $row) => figuresOf($row))->all())->toBe([
        ['date' => '2026-10-05', 'finalized' => 1, 'finalized_items' => 10, 'pending' => 1, 'pending_items' => 8, 'customer' => 1],
        ['date' => '2026-10-06', 'finalized' => 0, 'finalized_items' => 0, 'pending' => 3, 'pending_items' => 17, 'customer' => 0],
    ]);
});

it('leaves out a cancelled part, and days off they did nothing on', function () {
    ['sam' => $sam, 'ops' => $ops, 'shared' => $shared] = mondaysWork();
    $shared->refresh()->changePartStatus(2, Rfq::CANCELLED, $ops, 'Not needed');

    $rows = reportData('sourcing-daily', ['from' => '2026-10-03', 'to' => '2026-10-05', 'people' => [$sam->id]])['rows'];

    // Saturday and Sunday aren't working days; Monday's part 2 is cancelled.
    expect($rows->map(fn (array $row) => figuresOf($row))->all())->toBe([
        ['date' => '2026-10-05', 'finalized' => 0, 'finalized_items' => 0, 'pending' => 0, 'pending_items' => 0, 'customer' => 0],
    ]);
});

it('adds up each person across the dates, pending and waiting as at the last', function () {
    ['riley' => $riley] = mondaysWork();
    reportClock('2026-10-06 17:00');

    $report = reportData('sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-06', 'people' => [$riley->id]]);

    expect($report['asOf'])->toBe('2026-10-06')
        ->and(figuresOf($report['people']->sole()))->toBe(['finalized' => 1, 'finalized_items' => 10, 'pending' => 1, 'pending_items' => 8, 'customer' => 1]);
});

it('sorts by name, date, RFQ count or item count, either way', function (array $query, array $names) {
    mondaysWork();

    expect(reportData('sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-05'] + $query)['rows']->map(fn (array $row) => $row['user']->name)->all())->toBe($names);
})->with([
    'name' => [['sort' => 'name'], ['Riley Chen', 'Sam Rivera']],
    'name, last first' => [['sort' => 'name', 'dir' => 'desc'], ['Sam Rivera', 'Riley Chen']],
    'most RFQs finalized' => [['sort' => 'rfqs', 'dir' => 'desc'], ['Riley Chen', 'Sam Rivera']],
    'fewest items' => [['sort' => 'items', 'dir' => 'asc'], ['Sam Rivera', 'Riley Chen']],
]);

it('covers up to 93 days at once', function () {
    mondaysWork();

    $response = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-daily', ['from' => '2026-01-01', 'to' => '2026-10-05']))->assertOk();

    expect($response->viewData('from'))->toBe('2026-07-05')
        ->and($response->viewData('clamped'))->toBeTrue();
    $response->assertSee('A daily report covers up to 93 days');
});

it('shows the report with its columns and total', function () {
    mondaysWork();

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-05']))->assertOk()
        ->assertSeeInOrder(['Name', 'Date', 'Finalized RFQs', 'No. of items in finalized RFQs', 'Pending RFQs', 'No. of items in pending RFQs', 'Waiting for customer clarification'])
        ->assertSeeInOrder(['Riley Chen', '05-10-2026', 'Sam Rivera', 'Total'])
        ->assertSee('name="people[]"', false);
});

// ---- Data Entry: daily ---------------------------------------------------------

it('gives each Data Entry person\'s day: RFQs completed, their items, line items added and time spent', function () {
    ['morgan' => $morgan] = mondaysWork();

    $report = reportData('data-entry-daily', ['from' => '2026-10-05', 'to' => '2026-10-05']);

    expect(figuresOf($report['rows']->sole()))->toBe(['date' => '2026-10-05', 'completed' => 1, 'items' => 10, 'line_items' => 7, 'seconds' => 2 * 3600])
        ->and($report['total'])->toBe(['completed' => 1, 'items' => 10, 'line_items' => 7, 'seconds' => 2 * 3600]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.data-entry-daily', ['from' => '2026-10-05', 'to' => '2026-10-05']))->assertOk()
        ->assertSeeInOrder(['Completed RFQs', 'No. items in completed RFQs', 'Line items added', 'Time spent'])
        ->assertSeeInOrder(['Morgan Ito', '05-10-2026', '1', '10', '7', '2h']);
});

it('counts time on Data Entry day by day', function () {
    ['morgan' => $morgan, 'shared' => $shared] = mondaysWork();
    $shared->refresh()->completeSourcingPart(1);
    reportClock('16:00');
    startDataEntryOn($shared, 1, $morgan);
    reportClock('2026-10-06 10:00');

    $rows = reportData('data-entry-daily', ['from' => '2026-10-05', 'to' => '2026-10-06'])['rows'];

    // Monday 2h + 16:00–17:00; Tuesday 08:30–10:00, still on it.
    expect($rows->pluck('seconds', 'date')->all())->toBe(['2026-10-05' => 3 * 3600, '2026-10-06' => 90 * 60]);
});

// ---- Sourcing: monthly ---------------------------------------------------------

it('gives each Sourcing member\'s month: received, completed, average completion time and items', function () {
    mondaysWork();
    reportClock('2026-10-20 09:00');

    $report = reportData('sourcing-monthly', ['month' => '2026-10']);

    expect($report['rows']->map(fn (array $row) => [$row['user']->name => figuresOf($row)])->all())->toBe([
        // RFQ8001 received 08:30, finalized 12:00: 3h 30m of working time.
        ['Riley Chen' => ['received' => 3, 'completed' => 1, 'average' => 3 * 3600 + 30 * 60, 'items' => 23]],
        ['Sam Rivera' => ['received' => 1, 'completed' => 0, 'average' => null, 'items' => 8]],
    ])->and($report['total'])->toBe(['received' => 3, 'completed' => 1, 'average' => 3 * 3600 + 30 * 60, 'items' => 23]);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-monthly', ['month' => '2026-10']))->assertOk()
        ->assertSeeInOrder(['Total RFQs received', 'Total quotations completed', 'Average RFQ completion time', 'Total no. of items for month'])
        ->assertSeeInOrder(['Riley Chen', 'October 2026', '3', '1', '3h 30m', '23']);
});

it('counts nothing in a month nothing came in', function () {
    mondaysWork();

    expect(reportData('sourcing-monthly', ['month' => '2026-09'])['total'])->toBe(['received' => 0, 'completed' => 0, 'average' => null, 'items' => 0]);
});

// ---- The RFQ tracking sheet --------------------------------------------------------

it('lists every RFQ with who in Sourcing finalized it and whether it\'s open', function () {
    mondaysWork();

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.rfq-tracking'))->assertOk()
        ->assertSeeInOrder(['Date', 'RFQ Number', 'WC Number', 'Subject', 'Priority', 'Finalized by Sourcing', 'RFQ Status'])
        ->assertSee('3 RFQs · 3 open · 0 closed · 23 items')
        ->getContent();

    $row = Str::betweenFirst($html, '>RFQ8001</a>', '</tr>');
    expect($row)->toContain('WC-101')->toContain('Kitchen items')->toContain('High')->toContain('Riley Chen')->toContain('Open')
        ->and(Str::betweenFirst($html, '>RFQ8002</a>', '</tr>'))->toContain('Waiting for customer');
});

it('filters the tracking sheet', function (array $query, array $listed) {
    ['sam' => $sam] = mondaysWork();
    $query = array_map(fn ($value) => $value === 'sam' ? $sam->id : $value, $query);

    $rfqs = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.rfq-tracking', $query))->assertOk()->viewData('rfqs');

    expect($rfqs->getCollection()->pluck('rfq_number')->sort()->values()->all())->toBe($listed);
})->with([
    'RFQ number' => [['rfq' => '8002'], ['RFQ8002']],
    'WC number' => [['wc' => 'WC-103'], ['RFQ8003']],
    'priority' => [['priority' => ['High', 'Urgent']], ['RFQ8001', 'RFQ8003']],
    'Sourcing member' => [['person' => 'sam'], ['RFQ8003']],
    'closed' => [['status' => 'closed'], []],
    'open' => [['status' => 'open'], ['RFQ8001', 'RFQ8002', 'RFQ8003']],
    'dates' => [['from' => '2026-10-06'], []],
]);

it('sorts the tracking sheet', function () {
    mondaysWork();

    $rfqs = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.rfq-tracking', ['sort' => 'priority', 'dir' => 'asc']))->viewData('rfqs');

    expect($rfqs->getCollection()->pluck('rfq_number')->all())->toBe(['RFQ8003', 'RFQ8001', 'RFQ8002']);
});

// ---- Access ------------------------------------------------------------------------

it('is Admin\'s, Senior Operations\' and HR Executive\'s alone', function (string $role) {
    $user = userWithRole($role);

    foreach (['sourcing-daily', 'data-entry-daily', 'sourcing-monthly', 'rfq-tracking'] as $report) {
        test()->actingAs($user)->get(route("admin.reports.{$report}"))->assertForbidden();
    }

    // Nor are they in their sidebar.
    expect(test()->actingAs($user)->get(route('admin.dashboard'))->assertOk()->getContent())
        ->not->toContain('href="'.route('admin.reports.sourcing-daily').'"')
        ->not->toContain('href="'.route('admin.reports.rfq-tracking').'"');
})->with(['Sourcing', 'Data Entry', 'General Manager', 'Business Development']);

it('lets Senior Operations see every report, from Reports in their sidebar', function () {
    mondaysWork();
    $ops = userWithRole('Senior Operations');

    foreach (['sourcing-daily', 'data-entry-daily', 'sourcing-monthly', 'rfq-tracking'] as $report) {
        $html = test()->actingAs($ops)->get(route("admin.reports.{$report}"))->assertOk()->getContent();

        expect(Str::after($html, '<div class="sidebar-section-title">Reports</div>'))
            ->toContain('href="'.route('admin.reports.sourcing-daily').'"')
            ->toContain('href="'.route('admin.reports.sourcing-monthly').'"')
            ->toContain('href="'.route('admin.reports.data-entry-daily').'"')
            ->toContain('href="'.route('admin.reports.rfq-tracking').'"');
    }

    expect(reportDataAs($ops, 'sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-05'])['total'])->toMatchArray(['finalized' => 1, 'pending' => 1, 'customer' => 1]);

    // They see the RFQs too, so the tracking sheet links to them.
    test()->actingAs($ops)->get(route('admin.reports.rfq-tracking'))->assertOk()
        ->assertSee('href="'.route('admin.rfqs.show', Rfq::query()->where('rfq_number', 'RFQ8001')->sole()).'"', false);
});

it('lets HR Executive see every report, from Reports in their sidebar', function () {
    mondaysWork();
    $hr = User::factory()->create()->assignRole(Role::findOrCreate('HR Executive'));

    foreach (['sourcing-daily', 'data-entry-daily', 'sourcing-monthly', 'rfq-tracking'] as $report) {
        $html = test()->actingAs($hr)->get(route("admin.reports.{$report}"))->assertOk()->getContent();

        expect(Str::after($html, '<div class="sidebar-section-title">Reports</div>'))
            ->toContain('href="'.route('admin.reports.sourcing-daily').'"')
            ->toContain('href="'.route('admin.reports.sourcing-monthly').'"')
            ->toContain('href="'.route('admin.reports.data-entry-daily').'"')
            ->toContain('href="'.route('admin.reports.rfq-tracking').'"');
    }

    expect(reportDataAs($hr, 'sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-05'])['total'])->toMatchArray(['finalized' => 1, 'pending' => 1, 'customer' => 1]);

    // They see the tracking sheet, not the RFQs themselves.
    test()->actingAs($hr)->get(route('admin.reports.rfq-tracking'))->assertOk()
        ->assertSee('RFQ8001')
        ->assertDontSee('href="'.route('admin.rfqs.show', Rfq::query()->where('rfq_number', 'RFQ8001')->sole()).'"', false);
});

it('is in Admin\'s sidebar, under Reports', function () {
    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.sourcing-daily'))->assertOk()->getContent();

    expect(Str::after($html, 'data-sidebar-group="reports"'))
        ->toContain('href="'.route('admin.reports.sourcing-daily').'"')
        ->toContain('href="'.route('admin.reports.sourcing-monthly').'"')
        ->toContain('href="'.route('admin.reports.data-entry-daily').'"')
        ->toContain('href="'.route('admin.reports.rfq-tracking').'"');
});

// ---- Waiting for customer clarification ----------------------------------------------

it('lets a hold say it\'s waiting for the customer\'s clarification', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('name="customer_clarification"', false)
        ->assertSee('Waiting for customer clarification');

    test()->actingAs($ops)->patch(route('admin.rfqs.change-status', $rfq), ['status' => Rfq::ON_HOLD, 'reason' => 'Which sizes?', 'customer_clarification' => '1'])
        ->assertSessionHas('status');

    $comment = RfqComment::query()->where('action', 'put_on_hold')->sole();
    expect($comment->meta)->toBe(['customer_clarification' => true])
        ->and($comment->actionContext())->toBe('Waiting for customer clarification');
});

it('keeps an ordinary hold, or a cancellation, apart from waiting for the customer', function (string $status) {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create();

    test()->actingAs($ops)->patch(route('admin.rfqs.change-status', $rfq), ['status' => $status, 'reason' => 'Because', 'customer_clarification' => $status === Rfq::CANCELLED ? '1' : null]);

    expect(RfqComment::query()->whereNotNull('action')->sole()->meta)->toBeNull();
})->with([Rfq::ON_HOLD, Rfq::CANCELLED]);

it('lets one part of a split wait for the customer', function () {
    ['riley' => $riley, 'sam' => $sam, 'ops' => $ops, 'shared' => $shared] = mondaysWork();

    test()->actingAs($ops)->patch(route('admin.rfqs.change-status', $shared), ['status' => Rfq::ON_HOLD, 'part' => 2, 'reason' => 'Which pump?', 'customer_clarification' => '1'])
        ->assertSessionHas('status');

    $rows = reportData('sourcing-daily', ['from' => '2026-10-05', 'to' => '2026-10-05'])['rows']->keyBy(fn (array $row) => $row['user']->name);

    expect($rows['Sam Rivera'])->toMatchArray(['pending' => 0, 'customer' => 1])
        ->and($rows['Riley Chen'])->toMatchArray(['pending' => 1, 'customer' => 1]);
});
