<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
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
    foreach (range(1, 11) as $number) {
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
