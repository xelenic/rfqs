<?php

use App\Http\Controllers\Admin\RfqController;
use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Midday, so "today" and "yesterday" mean the same here and in Colombo.
    test()->travelTo(now()->setTime(12, 0));

    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * An RFQ about $subject, created $daysAgo days ago.
 */
function listFilterRfq(string $subject, int $daysAgo = 0, array $attributes = []): Rfq
{
    return Rfq::factory()->create($attributes + ['subject' => $subject, 'created_at' => now()->subDays($daysAgo)]);
}

/**
 * Which of $subjects Business Development's list shows for $query.
 *
 * @param  array<string, mixed>  $query
 * @param  array<int, string>  $subjects
 * @return array<int, string>
 */
function listedSubjects(array $query, array $subjects, string $role = 'Business Development'): array
{
    $html = test()->actingAs(userWithRole($role))
        ->get(route('admin.rfqs.index', $query + ['status' => 'Pending']))
        ->assertOk()
        ->getContent();

    return array_values(array_filter($subjects, fn (string $subject) => str_contains($html, $subject)));
}

/**
 * The RFQs most of these tests list, by subject: how many days ago each was created.
 *
 * @return array<string, int>
 */
function listFilterAges(): array
{
    return ['Fresh job' => 0, 'Two day job' => 2, 'Three day job' => 3, 'Four week job' => 29, 'Thirty day job' => 30, 'Old job' => 45];
}

// ---- the bar ---------------------------------------------------------------

it('puts search, when they were created and their priority above the RFQ lists', function (string $role, array $query) {
    test()->actingAs(userWithRole($role))->get(route('admin.rfqs.index', $query))->assertOk()
        ->assertSee('id="rfqListFilterForm"', false)
        ->assertSee('name="search"', false)
        ->assertSee('role="radiogroup" aria-label="Created"', false)
        ->assertSeeInOrder(['All time', 'Today', '3 days', 'Month', 'Custom'])
        ->assertSee('<input type="radio" name="range" value="all" checked>', false)
        ->assertSee('class="ops-custom-range js-custom-range d-none"', false)
        ->assertSee('role="group" aria-label="Priority"', false)
        ->assertSeeInOrder([
            '<input type="checkbox" name="priority[]" value="Urgent"',
            '<input type="checkbox" name="priority[]" value="High"',
            '<input type="checkbox" name="priority[]" value="Medium"',
            '<input type="checkbox" name="priority[]" value="Low"',
        ], false)
        ->assertDontSee('name="priority[]" value="Urgent" checked', false)
        // Nothing to clear yet.
        ->assertDontSee('list-filter-clear', false);
})->with([
    'Business Development\'s Pending' => ['Business Development', ['status' => 'Pending']],
    'Business Development\'s Returns' => ['Business Development', ['status' => 'Pending', 'view' => 'returns']],
    'the Closed list' => ['Business Development', ['status' => 'Completed']],
    'Sourcing\'s own' => ['Sourcing', ['status' => 'Pending']],
    'Data Entry\'s' => ['Data Entry', ['status' => 'Pending']],
    'Head of Business Development\'s' => ['Head of Business Development', ['status' => 'Pending']],
    'the General Manager\'s' => ['General Manager', ['status' => 'Pending']],
    'Senior Operations\' review' => ['Senior Operations', ['status' => 'Pending', 'view' => 'review']],
]);

it('gives Senior Operations\' Unassigned page the search alone — its own filters have the dates', function () {
    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('id="rfqListFilterForm"', false)
        ->assertSee('id="opsFilterForm"', false)
        ->assertDontSee('aria-label="Created"', false)
        ->assertDontSee('js-custom-range', false)
        ->assertDontSee('role="group" aria-label="Priority"', false);
});

// ---- the dates -------------------------------------------------------------

it('narrows a list to when its RFQs were created, today counting as the first day', function (array $query, array $expected) {
    foreach (listFilterAges() as $subject => $daysAgo) {
        listFilterRfq($subject, $daysAgo);
    }

    expect(listedSubjects($query, array_keys(listFilterAges())))->toBe($expected);
})->with([
    'all time' => [['range' => 'all'], ['Fresh job', 'Two day job', 'Three day job', 'Four week job', 'Thirty day job', 'Old job']],
    'today' => [['range' => 'today'], ['Fresh job']],
    '3 days' => [['range' => '3d'], ['Fresh job', 'Two day job']],
    'a month' => [['range' => '30d'], ['Fresh job', 'Two day job', 'Three day job', 'Four week job']],
    'something else' => [['range' => 'year'], ['Fresh job', 'Two day job', 'Three day job', 'Four week job', 'Thirty day job', 'Old job']],
]);

it('narrows a list to dates of your own', function (array $query, array $expected) {
    foreach (listFilterAges() as $subject => $daysAgo) {
        listFilterRfq($subject, $daysAgo);
    }

    $query = array_map(fn ($value) => is_int($value) ? now()->subDays($value)->toDateString() : $value, $query);

    expect(listedSubjects($query, array_keys(listFilterAges())))->toBe($expected);
})->with([
    'between two dates' => [['range' => 'custom', 'from' => 30, 'to' => 2], ['Two day job', 'Three day job', 'Four week job', 'Thirty day job']],
    'the wrong way round' => [['range' => 'custom', 'from' => 2, 'to' => 30], ['Two day job', 'Three day job', 'Four week job', 'Thirty day job']],
    'from a date on' => [['range' => 'custom', 'from' => 3], ['Fresh job', 'Two day job', 'Three day job']],
    'up to a date' => [['range' => 'custom', 'to' => 30], ['Thirty day job', 'Old job']],
    'a single day' => [['range' => 'custom', 'from' => 29, 'to' => 29], ['Four week job']],
    'no dates' => [['range' => 'custom'], ['Fresh job', 'Two day job', 'Three day job', 'Four week job', 'Thirty day job', 'Old job']],
    'not dates' => [['range' => 'custom', 'from' => 'soon', 'to' => '31/12'], ['Fresh job', 'Two day job', 'Three day job', 'Four week job', 'Thirty day job', 'Old job']],
]);

it('counts a day as the working hours\' time zone has it, not UTC', function () {
    // Midday UTC is 5:30 PM in Colombo: 8 PM UTC yesterday was already
    // 1:30 AM today there; 6 PM UTC yesterday, 11:30 PM yesterday.
    listFilterRfq('Small hours job', attributes: ['created_at' => now()->subDay()->setTime(20, 0)]);
    listFilterRfq('Late night job', attributes: ['created_at' => now()->subDay()->setTime(18, 0)]);

    expect(listedSubjects(['range' => 'today'], ['Small hours job', 'Late night job']))->toBe(['Small hours job'])
        ->and(listedSubjects(['range' => 'custom', 'to' => now()->subDay()->toDateString()], ['Small hours job', 'Late night job']))->toBe(['Late night job']);
});

it('searches within the dates, on the Closed list as well', function () {
    listFilterRfq('Lobby lights', 1, ['status' => 'Completed', 'stage' => 'closed']);
    listFilterRfq('Lobby doors', 20, ['status' => 'Completed', 'stage' => 'closed']);
    listFilterRfq('Roof repair', 1, ['status' => 'Completed', 'stage' => 'closed']);

    $html = test()->actingAs(userWithRole('Business Development'))
        ->get(route('admin.rfqs.index', ['status' => 'Completed', 'search' => 'Lobby', 'range' => '3d']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('Lobby lights')
        ->not->toContain('Lobby doors')
        ->not->toContain('Roof repair');
});

it('narrows both of Data Entry\'s tabs, and what they count', function () {
    $sourcing = userWithRole('Sourcing');
    $dataEntry = userWithRole('Data Entry');

    foreach (['Fresh handover' => 0, 'Old handover' => 10] as $subject => $daysAgo) {
        splitAmong(listFilterRfq($subject, $daysAgo), [1 => $sourcing])->completeSourcingPart(1);
    }

    $response = test()->actingAs($dataEntry)
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'range' => 'today']))
        ->assertOk()
        ->assertSee('Fresh handover')
        ->assertDontSee('Old handover');

    expect($response->viewData('dataEntryCounts'))->toBe(['ready' => 1, 'completed' => 0]);
});

// ---- priority --------------------------------------------------------------

it('narrows a list to one priority or several', function (array $priorities, array $expected) {
    foreach (['Urgent job' => 'Urgent', 'High job' => 'High', 'Medium job' => 'Medium', 'Low job' => 'Low'] as $subject => $priority) {
        listFilterRfq($subject, attributes: ['priority_level' => $priority]);
    }

    expect(listedSubjects(['priority' => $priorities], ['Urgent job', 'High job', 'Medium job', 'Low job']))->toBe($expected);
})->with([
    'one' => [['Urgent'], ['Urgent job']],
    'several' => [['High', 'Low'], ['High job', 'Low job']],
    'none' => [[], ['Urgent job', 'High job', 'Medium job', 'Low job']],
    'something that isn\'t one' => [['Critical'], ['Urgent job', 'High job', 'Medium job', 'Low job']],
    'one, and something that isn\'t' => [['Medium', 'Critical'], ['Medium job']],
]);

it('narrows by priority together with the dates and the search, and shows what\'s chosen', function () {
    listFilterRfq('Lobby lights', 1, ['priority_level' => 'Urgent']);
    listFilterRfq('Lobby doors', 1, ['priority_level' => 'Low']);
    listFilterRfq('Lobby floor', 20, ['priority_level' => 'Urgent']);
    listFilterRfq('Roof repair', 1, ['priority_level' => 'Urgent']);

    $query = ['status' => 'Pending', 'search' => 'Lobby', 'range' => '3d', 'priority' => ['Urgent', 'High']];

    $response = test()->actingAs(userWithRole('Business Development'))->get(route('admin.rfqs.index', $query))->assertOk()
        ->assertSee('<input type="checkbox" name="priority[]" value="Urgent" checked>', false)
        ->assertSee('<input type="checkbox" name="priority[]" value="High" checked>', false)
        ->assertDontSee('name="priority[]" value="Low" checked', false)
        // Clear takes the priority off too.
        ->assertSee('href="'.e(route('admin.rfqs.index', ['status' => 'Pending'])).'"', false);

    expect($response->getContent())->toContain('Lobby lights')
        ->not->toContain('Lobby doors')
        ->not->toContain('Lobby floor')
        ->not->toContain('Roof repair');
});

it('narrows Data Entry\'s count by priority too', function () {
    $sourcing = userWithRole('Sourcing');

    foreach (['Urgent handover' => 'Urgent', 'Low handover' => 'Low'] as $subject => $priority) {
        splitAmong(listFilterRfq($subject, attributes: ['priority_level' => $priority]), [1 => $sourcing])->completeSourcingPart(1);
    }

    $response = test()->actingAs(userWithRole('Data Entry'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'priority' => ['Urgent']]))
        ->assertOk()
        ->assertSee('Urgent handover')
        ->assertDontSee('Low handover');

    expect($response->viewData('dataEntryCounts'))->toBe(['ready' => 1, 'completed' => 0]);
});

// ---- keeping your place ----------------------------------------------------

it('keeps where you are, and clears only the search and the dates', function () {
    $query = ['status' => 'Pending', 'view' => 'returns', 'search' => 'Lobby', 'range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-30'];

    test()->actingAs(userWithRole('Business Development'))->get(route('admin.rfqs.index', $query))->assertOk()
        ->assertSee('<input type="hidden" name="status" value="Pending">', false)
        ->assertSee('<input type="hidden" name="view" value="returns">', false)
        ->assertSee('value="Lobby"', false)
        ->assertSee('<input type="radio" name="range" value="custom" checked>', false)
        ->assertSee('value="2026-09-01"', false)
        ->assertSee('value="2026-09-30"', false)
        ->assertSee('class="ops-custom-range js-custom-range "', false)
        ->assertSee('href="'.e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns'])).'"', false);
});

it('pages within the dates', function () {
    foreach (range(1, RfqController::PER_PAGE + 1) as $number) {
        listFilterRfq("Job {$number}", 1);
    }

    test()->actingAs(userWithRole('Business Development'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'range' => '3d']))
        ->assertOk()
        ->assertSee(e(route('admin.rfqs.index', ['status' => 'Pending', 'range' => '3d', 'page' => 2])), false);
});

it('leaves the dates off the lists when none are asked for', function () {
    listFilterRfq('Ancient job', 400);

    expect(listedSubjects([], ['Ancient job']))->toBe(['Ancient job']);
});

// ---- grouping --------------------------------------------------------------

/**
 * A list as $role sees it, with $query in its address.
 *
 * @param  array<string, mixed>  $query
 */
function groupedList(array $query, string $role = 'Admin'): TestResponse
{
    return test()->actingAs(userWithRole($role))->get(route('admin.rfqs.index', $query))->assertOk();
}

it('offers grouping on the plain lists, not on a role\'s own queue', function (string $role, array $query, bool $offered) {
    $html = groupedList($query, $role)->getContent();

    expect(str_contains($html, '<select name="group"'))->toBe($offered);
})->with([
    'All RFQs' => ['Admin', [], true],
    'Business Development\'s Pending' => ['Business Development', ['status' => 'Pending'], true],
    'the Closed list' => ['Business Development', ['status' => 'Completed'], true],
    'On Hold' => ['Senior Operations', ['status' => 'On Hold'], true],
    'Senior Operations\' Unassigned' => ['Senior Operations', ['status' => 'Pending'], false],
    'Sourcing\'s own' => ['Sourcing', ['status' => 'Pending'], false],
    'Data Entry\'s' => ['Data Entry', ['status' => 'Pending'], false],
    'the Head of Business Development\'s review' => ['Head of Business Development', ['status' => 'Pending'], false],
]);

it('groups by WC number — the same however it was typed — each group with its total', function () {
    listFilterRfq('First at WC100', 3, ['rfq_number' => 'RFQ5001', 'wc_number' => 'wc100']);
    listFilterRfq('Second at WC100', 1, ['rfq_number' => 'RFQ5002', 'wc_number' => ' WC100']);
    listFilterRfq('Only at WC200', 2, ['rfq_number' => 'RFQ5003', 'wc_number' => 'WC200']);

    groupedList(['group' => 'wc'])
        ->assertSee('<option value="wc" selected>Group by: WC number</option>', false)
        ->assertSeeInOrder([
            'rfq-list-group', 'WC100', '2 RFQs', 'RFQ5002', 'RFQ5001',
            'rfq-list-group', 'WC200', '1 RFQ', 'RFQ5003',
        ], false);
});

it('counts a whole group, though the page shows only some of it', function () {
    listFilterRfq('A lone one', 0, ['wc_number' => 'WC100']);
    foreach (range(1, 22) as $number) {
        listFilterRfq("Busy site {$number}", 0, ['wc_number' => 'WC300']);
    }

    $response = groupedList(['group' => 'wc']);

    expect($response->viewData('groupTotals')->all())->toBe(['WC100' => 1, 'WC300' => 22]);
    $response->assertSeeInOrder(['WC100', '1 RFQ', 'WC300', '22 RFQs'], false);

    // The second page carries on with WC300, its heading again.
    groupedList(['group' => 'wc', 'page' => 2])->assertSeeInOrder(['rfq-list-group', 'WC300', '22 RFQs'], false);
});

it('groups by priority, Urgent first', function () {
    listFilterRfq('Low job', 0, ['priority_level' => 'Low']);
    listFilterRfq('Urgent job', 0, ['priority_level' => 'Urgent']);
    listFilterRfq('High job', 0, ['priority_level' => 'High']);

    groupedList(['group' => 'priority'])->assertSeeInOrder([
        'rfq-list-group', 'Urgent', '1 RFQ', 'Urgent job',
        'rfq-list-group', 'High', '1 RFQ', 'High job',
        'rfq-list-group', 'Low', '1 RFQ', 'Low job',
    ], false);
});

it('groups by status as the work goes — pending, on hold, closed, cancelled', function () {
    listFilterRfq('Cancelled job', 0, ['status' => Rfq::CANCELLED]);
    listFilterRfq('Closed job', 0, ['status' => 'Completed', 'stage' => 'closed']);
    listFilterRfq('Held job', 0, ['status' => Rfq::ON_HOLD]);
    listFilterRfq('Open job', 0);

    groupedList(['group' => 'status'])->assertSeeInOrder([
        'rfq-list-group', 'Pending', 'Open job',
        'rfq-list-group', 'On Hold', 'Held job',
        'rfq-list-group', 'Closed', 'Closed job',
        'rfq-list-group', 'Cancelled', 'Cancelled job',
    ], false);
});

it('groups what the filters leave, and leaves an unknown grouping — or a role\'s queue — ungrouped', function () {
    listFilterRfq('Lobby lights', 0, ['wc_number' => 'WC100', 'priority_level' => 'Urgent']);
    listFilterRfq('Lobby doors', 0, ['wc_number' => 'WC100', 'priority_level' => 'Low']);
    listFilterRfq('Roof repair', 0, ['wc_number' => 'WC200', 'priority_level' => 'Urgent']);

    $response = groupedList(['group' => 'wc', 'priority' => ['Urgent']]);
    expect($response->viewData('groupTotals')->all())->toBe(['WC100' => 1, 'WC200' => 1]);

    groupedList(['group' => 'colour'])->assertDontSee('rfq-list-group', false);
    groupedList(['status' => 'Pending', 'group' => 'wc'], 'Senior Operations')->assertDontSee('rfq-list-group', false);
});

it('shows 20 RFQs a page', function () {
    Rfq::factory()->count(21)->create();

    $response = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index'))->assertOk();

    expect(RfqController::PER_PAGE)->toBe(20)
        ->and($response->viewData('rfqs')->count())->toBe(20)
        ->and($response->viewData('rfqs')->total())->toBe(21);
    $response->assertSee(e(route('admin.rfqs.index', ['page' => 2])), false);

    expect(test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['page' => 2]))->viewData('rfqs')->count())->toBe(1);
});
