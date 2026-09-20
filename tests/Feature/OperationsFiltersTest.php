<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    // Midday, so "today" and "yesterday" mean the same at any hour of the test run.
    test()->travelTo(now()->setTime(12, 0));

    Role::findOrCreate('Sourcing');
    Role::findOrCreate('Senior Operations');
});

/**
 * An RFQ with a subject to spot it by. The factory picks a priority at
 * random, so it's pinned here.
 *
 * @param  array<string, mixed>  $attributes
 */
function opsRfq(string $subject, array $attributes = []): Rfq
{
    return Rfq::factory()->create($attributes + ['subject' => $subject, 'priority_level' => 'Medium']);
}

/**
 * Which of the given subjects show up in a page of HTML.
 *
 * @param  array<int, string>  $subjects
 * @return array<int, string>
 */
function subjectsIn(string $html, array $subjects): array
{
    return array_values(array_filter($subjects, fn (string $subject) => str_contains($html, $subject)));
}

it('filters both tabs by when the RFQ was created', function (array $query, array $expected) {
    $ages = ['Fresh' => 0, 'Two days old' => 2, 'Six days old' => 6, 'Twenty days old' => 20, 'Sixty days old' => 60];
    $sourcing = userWithRole('Sourcing');

    foreach ($ages as $subject => $daysAgo) {
        opsRfq($subject.' unassigned', ['created_at' => now()->subDays($daysAgo)]);
        splitAmong(opsRfq($subject.' assigned', ['created_at' => now()->subDays($daysAgo)]), [1 => $sourcing]);
    }

    [$unassigned, $assigned] = operationsTabs($query);

    $unassignedSubjects = array_map(fn ($subject) => $subject.' unassigned', array_keys($ages));
    $assignedSubjects = array_map(fn ($subject) => $subject.' assigned', array_keys($ages));

    expect(subjectsIn($unassigned, $unassignedSubjects))->toBe(array_map(fn ($subject) => $subject.' unassigned', $expected))
        ->and(subjectsIn($assigned, $assignedSubjects))->toBe(array_map(fn ($subject) => $subject.' assigned', $expected));
})->with([
    'no filter' => [[], ['Fresh', 'Two days old', 'Six days old', 'Twenty days old', 'Sixty days old']],
    'all time' => [['range' => 'all'], ['Fresh', 'Two days old', 'Six days old', 'Twenty days old', 'Sixty days old']],
    'today' => [['range' => 'today'], ['Fresh']],
    '3 days' => [['range' => '3d'], ['Fresh', 'Two days old']],
    '7 days' => [['range' => '7d'], ['Fresh', 'Two days old', 'Six days old']],
    '30 days' => [['range' => '30d'], ['Fresh', 'Two days old', 'Six days old', 'Twenty days old']],
    'something else' => [['range' => 'bogus'], ['Fresh', 'Two days old', 'Six days old', 'Twenty days old', 'Sixty days old']],
]);

it('filters by a custom range of dates', function (array $query, array $expected) {
    foreach (['Fresh' => 0, 'Two days old' => 2, 'Six days old' => 6, 'Twenty days old' => 20] as $subject => $daysAgo) {
        opsRfq($subject, ['created_at' => now()->subDays($daysAgo)]);
    }

    $dates = fn (int $daysAgo) => now()->subDays($daysAgo)->toDateString();
    $query = array_map(fn ($value) => is_int($value) ? $dates($value) : $value, $query);

    [$unassigned] = operationsTabs($query);

    expect(subjectsIn($unassigned, ['Fresh', 'Two days old', 'Six days old', 'Twenty days old']))->toBe($expected);
})->with([
    'between two dates' => [['range' => 'custom', 'from' => 7, 'to' => 1], ['Two days old', 'Six days old']],
    'from a date on' => [['range' => 'custom', 'from' => 3], ['Fresh', 'Two days old']],
    'up to a date' => [['range' => 'custom', 'to' => 5], ['Six days old', 'Twenty days old']],
    'the ends the wrong way round' => [['range' => 'custom', 'from' => 1, 'to' => 7], ['Two days old', 'Six days old']],
    'both ends inclusive' => [['range' => 'custom', 'from' => 2, 'to' => 2], ['Two days old']],
    'no dates at all' => [['range' => 'custom'], ['Fresh', 'Two days old', 'Six days old', 'Twenty days old']],
    'dates that are not dates' => [['range' => 'custom', 'from' => 'soon', 'to' => '31/12'], ['Fresh', 'Two days old', 'Six days old', 'Twenty days old']],
    'dates without custom' => [['from' => 7, 'to' => 7], ['Fresh', 'Two days old', 'Six days old', 'Twenty days old']],
]);

it('filters by priority, one or several', function (array $priorities, array $expected) {
    foreach (['Urgent', 'High', 'Medium', 'Low'] as $priority) {
        opsRfq($priority.' job', ['priority_level' => $priority]);
    }

    [$unassigned] = operationsTabs(['priority' => $priorities]);

    expect(subjectsIn($unassigned, ['Urgent job', 'High job', 'Medium job', 'Low job']))->toBe($expected);
})->with([
    'none' => [[], ['Urgent job', 'High job', 'Medium job', 'Low job']],
    'one' => [['Urgent'], ['Urgent job']],
    'two' => [['Urgent', 'High'], ['Urgent job', 'High job']],
    'not a priority' => [['Nope'], ['Urgent job', 'High job', 'Medium job', 'Low job']],
    'one real, one not' => [['Low', 'Nope'], ['Low job']],
]);

it('filters by job category', function () {
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    JobCategory::factory()->create(['name' => 'Landscaping']);
    opsRfq('Fire job', ['category' => 'Fire Safety']);
    opsRfq('Garden job', ['category' => 'Landscaping']);
    opsRfq('Uncategorised job');

    expect(subjectsIn(operationsTabs(['category' => 'Fire Safety'])[0], ['Fire job', 'Garden job', 'Uncategorised job']))->toBe(['Fire job'])
        // A category that doesn't exist filters nothing.
        ->and(subjectsIn(operationsTabs(['category' => 'Made up'])[0], ['Fire job', 'Garden job', 'Uncategorised job']))->toBe(['Fire job', 'Garden job', 'Uncategorised job']);
});

it('filters by who holds a part and where that part stands', function (array $query, array $expected) {
    $dataEntry = userWithRole('Data Entry');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];

    // A: Riley's part was sent back, Sam's is still going.
    $a = splitAmong(opsRfq('Job A'), [1 => $riley, 2 => $sam]);
    $a->returnSourcingPart(1, 'Missing prices', $dataEntry);
    // B: kept whole — Sam finished it, Data Entry hasn't.
    splitAmong(opsRfq('Job B'), [1 => $sam])->completeSourcingPart(1);
    // C: Riley has both parts, neither started.
    splitAmong(opsRfq('Job C'), [1 => $riley, 2 => $riley]);
    // D: Data Entry has finished it.
    $d = splitAmong(opsRfq('Job D'), [1 => $sam]);
    $d->completeSourcingPart(1);
    $d->refresh()->completeDataEntryPart(1, $dataEntry);

    $query = array_map(fn ($value) => match ($value) {
        'riley' => $riley->id,
        'sam' => $sam->id,
        default => $value,
    }, $query);

    [, $assigned] = operationsTabs($query);

    expect(subjectsIn($assigned, ['Job A', 'Job B', 'Job C', 'Job D']))->toBe($expected);
})->with([
    'anyone' => [[], ['Job A', 'Job B', 'Job C', 'Job D']],
    'Riley' => [['member' => 'riley'], ['Job A', 'Job C']],
    'Sam' => [['member' => 'sam'], ['Job A', 'Job B', 'Job D']],
    'in progress' => [['part_status' => 'in_progress'], ['Job A', 'Job C']],
    'returned' => [['part_status' => 'returned'], ['Job A']],
    'with Data Entry' => [['part_status' => 'with_data_entry'], ['Job B']],
    'Data Entry done' => [['part_status' => 'data_entry_done'], ['Job D']],
    'Riley\'s returned part' => [['member' => 'riley', 'part_status' => 'returned'], ['Job A']],
    // Sam is on A and someone's part there was returned — but not Sam's.
    'Sam\'s returned part' => [['member' => 'sam', 'part_status' => 'returned'], []],
    'Sam\'s part in progress' => [['member' => 'sam', 'part_status' => 'in_progress'], ['Job A']],
    'someone who isn\'t Sourcing' => [['member' => 999999], ['Job A', 'Job B', 'Job C', 'Job D']],
    'not a status' => [['part_status' => 'nonsense'], ['Job A', 'Job B', 'Job C', 'Job D']],
]);

it('sorts by newest, oldest or priority', function (string $sort, array $expected) {
    opsRfq('Old job', ['created_at' => now()->subDays(5), 'priority_level' => 'High']);
    opsRfq('Middle job', ['created_at' => now()->subDays(3), 'priority_level' => 'Low']);
    opsRfq('Recent job', ['created_at' => now()->subDay(), 'priority_level' => 'Urgent']);
    opsRfq('Latest job', ['created_at' => now(), 'priority_level' => 'Low']);

    [$unassigned] = operationsTabs(['sort' => $sort]);

    $inOrder = collect(['Old job', 'Middle job', 'Recent job', 'Latest job'])
        ->sortBy(fn ($subject) => strpos($unassigned, $subject))->values()->all();

    expect($inOrder)->toBe($expected);
})->with([
    'newest first' => ['newest', ['Latest job', 'Recent job', 'Middle job', 'Old job']],
    'oldest first' => ['oldest', ['Old job', 'Middle job', 'Recent job', 'Latest job']],
    // Urgent, High, then the two Lows newest first.
    'highest priority first' => ['priority', ['Recent job', 'Old job', 'Latest job', 'Middle job']],
    'something else' => ['sideways', ['Latest job', 'Recent job', 'Middle job', 'Old job']],
]);

it('keeps filters and sorting away from everyone else\'s lists', function () {
    opsRfq('Old job', ['created_at' => now()->subDays(20)]);

    test()->actingAs(userWithRole('Business Development'))
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'range' => 'today', 'priority' => ['Urgent']]))
        ->assertOk()
        ->assertSee('Old job')
        ->assertDontSee('id="opsFilterForm"', false);
});

it('narrows both tabs at once and shows what\'s left in the tab counts', function () {
    $sourcing = userWithRole('Sourcing');

    opsRfq('Urgent unassigned', ['priority_level' => 'Urgent']);
    opsRfq('Low unassigned', ['priority_level' => 'Low']);
    splitAmong(opsRfq('Urgent assigned', ['priority_level' => 'Urgent']), [1 => $sourcing]);
    splitAmong(opsRfq('Low assigned', ['priority_level' => 'Low']), [1 => $sourcing]);
    splitAmong(opsRfq('Another low assigned', ['priority_level' => 'Low']), [1 => $sourcing]);

    [$unassigned, $assigned, $page] = operationsTabs(['priority' => ['Urgent']]);

    expect($unassigned)->toContain('Urgent unassigned')->not->toContain('Low unassigned')
        ->and($assigned)->toContain('Urgent assigned')->not->toContain('Low assigned')
        ->and($page)->toContain('Unassigned <span class="rfq-tab-count">1</span>')
        ->toContain('Assigned <span class="rfq-tab-count">1</span>');

    // Unfiltered, the counts are everything.
    [, , $everything] = operationsTabs();
    expect($everything)->toContain('Unassigned <span class="rfq-tab-count">2</span>')
        ->toContain('Assigned <span class="rfq-tab-count">3</span>');
});

it('stays on the tab it was on, and pages within it', function () {
    opsRfq('Only job');

    [, , $onAssigned] = operationsTabs(['tab' => 'assigned']);
    [, , $onUnassigned] = operationsTabs();
    [, , $junk] = operationsTabs(['tab' => 'sideways']);

    expect($onAssigned)->toContain('id="rfq-ops-assigned"')
        ->toContain('<button class="nav-link active" data-bs-toggle="pill" data-bs-target="#rfq-ops-assigned"')
        ->toContain('<div class="tab-pane fade show active" id="rfq-ops-assigned"')
        ->toContain('name="tab" id="opsFilterTab" value="assigned"')
        ->and($onUnassigned)->toContain('<div class="tab-pane fade show active" id="rfq-ops-unassigned"')
        ->toContain('name="tab" id="opsFilterTab" value="unassigned"')
        ->and($junk)->toContain('<div class="tab-pane fade show active" id="rfq-ops-unassigned"');

    // Page links keep the tab they belong to, whichever one is showing.
    Rfq::factory()->count(12)->create(['priority_level' => 'Medium']);
    [$unassigned] = operationsTabs(['tab' => 'assigned', 'priority' => ['Medium']]);

    expect($unassigned)->toContain('page=2')->toContain('tab=unassigned')->toContain('priority%5B0%5D=Medium');
});

it('says so, with a way out, when the filters leave nothing', function () {
    opsRfq('A job', ['priority_level' => 'Low']);

    [$unassigned, $assigned] = operationsTabs(['priority' => ['Urgent']]);

    foreach ([$unassigned, $assigned] as $tab) {
        expect($tab)->toContain('No RFQs match these filters.')->toContain('Clear filters');
    }

    // Nothing filtered, nothing there: the usual message.
    Rfq::query()->delete();
    [$unassigned] = operationsTabs();
    expect($unassigned)->toContain('all caught up')->not->toContain('No RFQs match');
});

it('counts the filters in use and offers to clear them, keeping the search', function () {
    opsRfq('A job');

    [$unassigned, , $page] = operationsTabs(['priority' => ['Urgent', 'High'], 'range' => '7d', 'search' => 'job', 'sort' => 'oldest']);

    // Priority and created count once each; sorting isn't a filter.
    expect($page)->toContain('2 active')->toContain('Clear filters');
    expect($page)->toContain(e(route('admin.rfqs.index', ['status' => 'Pending', 'search' => 'job', 'tab' => 'unassigned'])));

    // No filters, no badge.
    [, , $plain] = operationsTabs();
    expect($plain)->not->toContain(' active</span>')->not->toContain('ops-filters-clear');
});

it('carries the filters through the search box and the search through the filters', function () {
    [, , $page] = operationsTabs(['range' => 'custom', 'from' => '2026-09-01', 'to' => '2026-09-15', 'priority' => ['Urgent'], 'sort' => 'priority', 'search' => 'lobby', 'part_status' => 'returned']);

    foreach ([
        '<input type="hidden" name="range" value="custom">',
        '<input type="hidden" name="from" value="2026-09-01">',
        '<input type="hidden" name="to" value="2026-09-15">',
        '<input type="hidden" name="priority[]" value="Urgent">',
        '<input type="hidden" name="sort" value="priority">',
        '<input type="hidden" name="part_status" value="returned">',
        // …and the other way: the filter form holds on to the search.
        '<input type="hidden" name="search" value="lobby">',
    ] as $carried) {
        expect($page)->toContain($carried);
    }
});

it('shows the chosen filters in the form itself', function () {
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $riley = userWithRole('Sourcing');

    [, , $page] = operationsTabs(['range' => '3d', 'priority' => ['Urgent'], 'category' => 'Fire Safety', 'member' => $riley->id, 'part_status' => 'returned', 'sort' => 'oldest']);

    expect($page)
        ->toContain('<input type="radio" name="range" value="3d" checked>')
        ->toContain('<input type="checkbox" name="priority[]" value="Urgent" checked>')
        ->toContain('<option value="Fire Safety" selected>')
        ->toContain('<option value="'.$riley->id.'" selected>')
        ->toContain('<option value="returned" selected>')
        ->toContain('<option value="oldest" selected>')
        // The custom dates stay tucked away until Custom is chosen.
        ->toContain('id="opsCustomRange"');
    expect($page)->toContain('class="ops-custom-range d-none"');
});

it('gives Admin the same filters on Senior Operations\' page', function () {
    opsRfq('Urgent job', ['priority_level' => 'Urgent']);
    opsRfq('Low job', ['priority_level' => 'Low']);

    [$unassigned, , $page] = operationsTabs(['priority' => ['Urgent']], 'senior-operations');

    expect($unassigned)->toContain('Urgent job')->not->toContain('Low job')
        // The filter form stays on the role page.
        ->and($page)->toContain('<input type="hidden" name="role" value="senior-operations">');
});
