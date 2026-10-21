<?php

use App\Models\Rfq;
use App\Models\Setting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

it('has seven levels, P1 Critical to P7 Trivial — Urgent, High, Medium and Low as they were', function () {
    expect(collect(array_reverse(Rfq::PRIORITIES))->map(fn (string $priority) => Rfq::priorityLabelOf($priority))->all())->toBe([
        'P1 – Critical', 'P2 – Urgent', 'P3 – High', 'P4 – Medium', 'P5 – Low', 'P6 – Minimal', 'P7 – Trivial',
    ])
        ->and(Rfq::priorityLabelOf('Something else'))->toBe('Something else')
        ->and(Rfq::URGENT_PRIORITIES)->toBe(['Critical', 'Urgent']);
});

it('gives each level its colour', function (string $priority, string $badge) {
    $rfq = Rfq::factory()->make(['priority_level' => $priority]);

    expect($rfq->priorityBadgeClass())->toBe($badge)
        ->and($rfq->priorityLabel())->toBe(Rfq::priorityLabelOf($priority));
})->with([
    'P1 purple' => ['Critical', 'badge-priority priority-chip-critical'],
    'P2 red' => ['Urgent', 'badge-priority priority-chip-urgent'],
    'P3 orange' => ['High', 'badge-priority priority-chip-high'],
    'P4 blue' => ['Medium', 'badge-priority priority-chip-medium'],
    'P5 green' => ['Low', 'badge-priority priority-chip-low'],
    'P6 yellow' => ['Minimal', 'badge-priority priority-chip-minimal'],
    'P7 white' => ['Trivial', 'badge-priority priority-chip-trivial'],
]);

it('shows each level\'s colour in the priority picker, which can\'t be drawn in', function () {
    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index'))->assertOk()->getContent();
    $select = Str::betweenFirst($html, 'id="create-priority_level"', '</select>');

    foreach (['🟣 P1 – Critical', '🔴 P2 – Urgent', '🟠 P3 – High', '🔵 P4 – Medium', '🟢 P5 – Low', '🟡 P6 – Minimal', '⚪ P7 – Trivial'] as $option) {
        expect($select)->toContain($option);
    }

    expect(Rfq::factory()->make(['priority_level' => 'Something else'])->priorityBadgeClass())->toBe('badge-soft-primary');
});

it('offers every level when adding an RFQ, P1 first, and takes the new ones', function () {
    Permission::findOrCreate('rfqs.create');
    $bd = userWithRole('Business Development')->givePermissionTo('rfqs.create');

    $html = test()->actingAs($bd)->get(route('admin.rfqs.index'))->assertOk()->getContent();
    $select = Str::betweenFirst($html, 'id="create-priority_level"', '</select>');
    preg_match_all('/<option value="(\w+)"/', $select, $options);

    expect($options[1])->toBe(['Critical', 'Urgent', 'High', 'Medium', 'Low', 'Minimal', 'Trivial'])
        ->and($select)->toContain('P1 – Critical')->toContain('P7 – Trivial');

    foreach (['Critical' => 'WC1001', 'Trivial' => 'WC1002'] as $priority => $wc) {
        test()->actingAs($bd)->post(route('admin.rfqs.store'), ['wc_number' => $wc, 'priority_level' => $priority, 'number_of_items' => 5, 'subject' => "A {$priority} job"])
            ->assertSessionHasNoErrors();
    }

    test()->actingAs($bd)->post(route('admin.rfqs.store'), ['wc_number' => 'WC1003', 'priority_level' => 'Extreme', 'number_of_items' => 5, 'subject' => 'Not a level'])
        ->assertSessionHasErrorsIn('create', 'priority_level');

    expect(Rfq::query()->orderBy('id')->pluck('priority_level')->all())->toBe(['Critical', 'Trivial']);
});

it('sorts the most pressing first — P1 Critical to P7 Trivial', function () {
    foreach (['Low', 'Critical', 'Trivial', 'Urgent', 'Minimal', 'Medium', 'High'] as $priority) {
        Rfq::factory()->create(['priority_level' => $priority, 'subject' => "{$priority} job"]);
    }

    expect(Rfq::query()->orderByRaw(Rfq::priorityOrderSql())->pluck('priority_level')->all())
        ->toBe(['Critical', 'Urgent', 'High', 'Medium', 'Low', 'Minimal', 'Trivial']);

    $sheet = test()->actingAs(userWithRole('Admin'))->get(route('admin.reports.rfq-tracking', ['sort' => 'priority', 'dir' => 'asc']))->assertOk()->viewData('rfqs');
    expect($sheet->getCollection()->pluck('priority_level')->all())->toBe(['Critical', 'Urgent', 'High', 'Medium', 'Low', 'Minimal', 'Trivial']);
});

it('gives each level its own targets, set on Settings', function () {
    $rfq = fn (string $priority) => Rfq::factory()->make(['priority_level' => $priority]);

    // Sourcing's: 2h for P1 up to 48h for P7.
    expect($rfq('Critical')->sourcingTargetSeconds())->toBe(2 * 3600)
        ->and($rfq('Minimal')->sourcingTargetSeconds())->toBe(36 * 3600)
        ->and($rfq('Trivial')->sourcingTargetSeconds())->toBe(48 * 3600)
        ->and($rfq('Critical')->finalizeTargetSeconds())->toBe(15 * 60)
        ->and($rfq('Trivial')->finalizeTargetSeconds())->toBe(4 * 3600);

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.settings.edit', ['tab' => 'sourcing-targets']))->assertOk()->getContent();
    $form = Str::betweenFirst($html, 'action="'.route('admin.settings.sourcing-targets').'"', '</form>');
    preg_match_all('/name="targets\[(\w+)\]"/', $form, $fields);

    expect($fields[1])->toBe(['Critical', 'Urgent', 'High', 'Medium', 'Low', 'Minimal', 'Trivial'])
        ->and($form)->toContain('>P1 – Critical</label>')->toContain('>P6 – Minimal</label>');

    // Targets saved before the new levels keep theirs, and the new ones start at their defaults.
    Setting::put('sourcing_targets', json_encode(['Low' => 1200, 'Medium' => 900, 'High' => 400, 'Urgent' => 200]));
    expect(Setting::sourcingTargets())->toBe(['Trivial' => 2880, 'Minimal' => 2160, 'Low' => 1200, 'Medium' => 900, 'High' => 400, 'Urgent' => 200, 'Critical' => 120]);
});

it('counts P1 Critical as urgent, and leads the Critical monitor with it', function () {
    $riley = userWithRole('Sourcing');
    $critical = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9301', 'priority_level' => 'Critical']), [1 => $riley]);
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ9302', 'priority_level' => 'High']), [1 => $riley]);

    test()->actingAs($riley)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('1 urgent');

    $monitor = test()->actingAs(userWithRole('Admin'))->get(route('admin.critical-monitor'))->assertOk();
    expect($monitor->viewData('urgentCounts'))->toBe(['Critical' => 1, 'Urgent' => 0, 'High' => 1])
        ->and($monitor->viewData('urgent')->pluck('rfq.rfq_number')->all())->toBe(['RFQ9301', 'RFQ9302']);
    $monitor->assertSee('>P1 – Critical</span>', false);

    expect($critical->isUrgent())->toBeTrue();
});

it('filters a list by any level', function () {
    foreach (['Critical', 'Minimal', 'Medium'] as $priority) {
        Rfq::factory()->create(['priority_level' => $priority, 'subject' => "{$priority} job"]);
    }

    $response = test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.index', ['priority' => ['Critical', 'Minimal']]))->assertOk()
        ->assertSee('<input type="checkbox" name="priority[]" value="Critical" checked>', false)
        ->assertSee('P7 – Trivial');

    expect($response->viewData('rfqs')->getCollection()->pluck('priority_level')->sort()->values()->all())->toBe(['Critical', 'Minimal']);
});
