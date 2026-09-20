<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

// The RFQ list looks up its Sourcing and Senior Operations members, so every
// workflow role has to exist whether or not anyone holds it.
beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

function indexUrl(array $query): string
{
    return route('admin.rfqs.index', $query);
}

it('groups the workflow by role in the Admin sidebar', function () {
    $response = test()->actingAs(userWithRole('Admin'))
        ->get(indexUrl(['status' => 'Pending']))
        ->assertOk();

    expect(substr_count($response->getContent(), 'sidebar-group-title'))->toBe(count(Rfq::WORKFLOW_ROLES));

    foreach ([
        ['status' => 'Pending', 'view' => 'closing', 'role' => 'business-development'],
        ['status' => 'Pending', 'role' => 'senior-operations'],
        ['status' => 'Pending', 'view' => 'review', 'role' => 'senior-operations'],
        ['status' => 'Pending', 'role' => 'sourcing'],
        ['status' => 'Pending', 'view' => 'returns', 'role' => 'sourcing'],
        ['status' => 'Pending', 'role' => 'data-entry'],
        ['status' => 'Pending', 'role' => 'head-of-business-development'],
        ['status' => 'Pending', 'role' => 'gm-assistant'],
        ['status' => 'Pending', 'role' => 'general-manager'],
    ] as $query) {
        $response->assertSee(e(indexUrl($query)), false);
    }
});

it('makes each group collapsible, showing its queue count on the heading', function () {
    Rfq::factory()->create(['stage' => 'head_of_bd_review']);
    Rfq::factory()->create(['stage' => 'senior_ops_review']);
    Rfq::factory()->create(['stage' => 'senior_ops_review']);

    $response = test()->actingAs(userWithRole('Admin'))
        ->get(indexUrl(['status' => 'Pending']))
        ->assertOk();

    foreach (Rfq::WORKFLOW_ROLES as $role) {
        $slug = Str::slug($role);

        // Open until someone collapses it.
        $response->assertSee('data-bs-target="#sidebar-group-'.$slug.'"', false)
            ->assertSee('<div class="collapse show sidebar-group-links" id="sidebar-group-'.$slug.'">', false);
    }

    // Senior Operations' two queues add up on its heading — all three RFQs
    // are still unassigned, and two of them are in review — while Head of
    // Business Development's one is its own. A group with nothing waiting
    // shows no count.
    $response->assertSee('class="nav-link-count sidebar-group-count" title="5 waiting"', false)
        ->assertSee('class="nav-link-count sidebar-group-count" title="1 waiting"', false);
    expect(substr_count($response->getContent(), 'sidebar-group-count'))->toBe(2);
});

it('leaves every other role\'s sidebar as it was', function () {
    foreach (['Sourcing', 'Senior Operations', 'Business Development'] as $role) {
        test()->actingAs(userWithRole($role))
            ->get(indexUrl(['status' => 'Pending']))
            ->assertOk()
            ->assertDontSee('sidebar-group-title', false);
    }
});

it('counts each role\'s queue company-wide', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];

    Rfq::factory()->create(['stage' => 'senior_ops_review']);
    Rfq::factory()->create(['stage' => 'head_of_bd_review']);
    Rfq::factory()->create(['stage' => 'gm_assistant']);
    Rfq::factory()->create(['stage' => 'gm_review']);
    Rfq::factory()->create(['stage' => 'bd_closing']);

    // One part each with Riley and Sam; Riley's is sent back for rework.
    $split = splitAmong(Rfq::factory()->create(), [1 => $riley, 2 => $sam]);
    $split->returnSourcingPart(1, 'Missing quote', userWithRole('Data Entry'));

    // Sam's finished part is waiting on Data Entry.
    $split->completeSourcingPart(2);

    expect(Rfq::queueCounts())->toBe([
        'closing' => 1,
        // The five stage RFQs have no Sourcing assignees, and neither does anything else…
        'unassigned' => 5,
        'ops_review' => 1,
        'sourcing_pending' => 1,
        'sourcing_returns' => 1,
        'data_entry' => 1,
        'head_of_bd' => 1,
        'gm_assistant' => 1,
        'gm_review' => 1,
    ]);
});

it('shows Admin every Sourcing member\'s open parts on the Sourcing page', function () {
    $admin = userWithRole('Admin');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'subject' => 'Chiller replacement']), [1 => $riley, 2 => $sam, 3 => $riley]);
    $rfq->completeSourcingPart(2);

    test()->actingAs($admin)
        ->get(indexUrl(['status' => 'Pending', 'role' => 'sourcing']))
        ->assertOk()
        ->assertSee('Pending RFQs · Sourcing')
        ->assertSee('Chiller replacement')
        ->assertSee('RFQ2001-P1 of P3')
        ->assertSee('RFQ2001-P3 of P3')
        // Sam finished their part, so it's no longer pending.
        ->assertDontSee('RFQ2001-P2 of P3')
        // (Names also sit in the Assign Sourcing modal's pick-list, so match the table cell.)
        ->assertSee('<td>'.e($riley->name).'</td>', false)
        ->assertDontSee('<td>'.e($sam->name).'</td>', false)
        // Read-only: completing a part is its assignee's call.
        ->assertDontSee('Mark Complete');
});

it('shows Admin the parts sent back on the Sourcing returns page', function () {
    $admin = userWithRole('Admin');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2002']), [1 => $riley, 2 => $sam]);
    $rfq->returnSourcingPart(2, 'Wrong supplier', userWithRole('Data Entry'));

    test()->actingAs($admin)
        ->get(indexUrl(['status' => 'Pending', 'view' => 'returns', 'role' => 'sourcing']))
        ->assertOk()
        ->assertSee('Returns · Sourcing')
        ->assertSee('RFQ2002-P2 of P2')
        ->assertSee('Wrong supplier')
        ->assertSee('<td>'.e($sam->name).'</td>', false)
        ->assertDontSee('RFQ2002-P1 of P2');
});

it('opens each stage\'s own queue for Admin', function (string $slug, string $stage, string $heading) {
    $inQueue = Rfq::factory()->create(['stage' => $stage, 'subject' => 'In this queue']);
    Rfq::factory()->create(['stage' => 'closed', 'status' => 'Pending', 'subject' => 'Somewhere else']);

    test()->actingAs(userWithRole('Admin'))
        ->get(indexUrl(['status' => 'Pending', 'role' => $slug]))
        ->assertOk()
        ->assertSee($heading)
        ->assertSee('In this queue')
        ->assertDontSee('Somewhere else');
})->with([
    'Head of Business Development' => ['head-of-business-development', 'head_of_bd_review', 'Review · Head of Business Development'],
    'GM Assistant' => ['gm-assistant', 'gm_assistant', 'Review · GM Assistant'],
    'General Manager' => ['general-manager', 'gm_review', 'Review · General Manager'],
]);

it('opens the second-queue views for Admin', function (string $slug, string $view, string $stage, string $heading) {
    Rfq::factory()->create(['stage' => $stage, 'subject' => 'In this queue']);
    Rfq::factory()->create(['stage' => 'closed', 'status' => 'Pending', 'subject' => 'Somewhere else']);

    test()->actingAs(userWithRole('Admin'))
        ->get(indexUrl(['status' => 'Pending', 'view' => $view, 'role' => $slug]))
        ->assertOk()
        ->assertSee($heading)
        ->assertSee('In this queue')
        ->assertDontSee('Somewhere else');
})->with([
    'Senior Operations review' => ['senior-operations', 'review', 'senior_ops_review', 'Review · Senior Operations'],
    'Business Development closing' => ['business-development', 'closing', 'bd_closing', 'Ready to Close · Business Development'],
]);

it('lets Admin work Data Entry\'s queue as Data Entry would', function () {
    $riley = userWithRole('Sourcing');
    splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2003']), [1 => $riley])->completeSourcingPart(1);

    test()->actingAs(userWithRole('Admin'))
        ->get(indexUrl(['status' => 'Pending', 'role' => 'data-entry']))
        ->assertOk()
        ->assertSee('Ready for Data Entry · Data Entry')
        ->assertSee('<td>'.e($riley->name).'</td>', false)
        ->assertSee('Mark Complete');
});

it('ignores ?role= for anyone but Admin', function () {
    $ops = userWithRole('Senior Operations');
    Rfq::factory()->create(['stage' => 'head_of_bd_review', 'subject' => 'Awaiting the Head']);

    test()->actingAs($ops)
        ->get(indexUrl(['status' => 'Pending', 'role' => 'head-of-business-development']))
        ->assertOk()
        ->assertSee('Unassigned RFQs')
        ->assertDontSee('· Head of Business Development');
});

it('keeps Admin on the role page being searched', function () {
    test()->actingAs(userWithRole('Admin'))
        ->get(indexUrl(['status' => 'Pending', 'view' => 'returns', 'role' => 'sourcing']))
        ->assertSee('<input type="hidden" name="role" value="sourcing">', false)
        ->assertSee('<input type="hidden" name="view" value="returns">', false);
});

it('sends Admin back to the role page a save came from', function () {
    $admin = userWithRole('Admin');
    $rfq = Rfq::factory()->create();

    test()->actingAs($admin)
        ->patch(route('admin.rfqs.assign-operations', $rfq), [
            'redirect_status' => 'Pending',
            'redirect_role' => 'senior-operations',
            'redirect_view' => 'review',
        ])
        ->assertRedirect(indexUrl(['status' => 'Pending', 'role' => 'senior-operations', 'view' => 'review']));

    // Anything that isn't a workflow role or view is dropped.
    test()->actingAs($admin)
        ->patch(route('admin.rfqs.assign-operations', $rfq), [
            'redirect_status' => 'Pending',
            'redirect_role' => 'nonsense',
            'redirect_view' => 'review',
        ])
        ->assertRedirect(indexUrl(['status' => 'Pending']));

    // …and it's Admin's alone.
    test()->actingAs(userWithRole('Senior Operations'))
        ->patch(route('admin.rfqs.assign-operations', $rfq), [
            'redirect_status' => 'Pending',
            'redirect_role' => 'sourcing',
        ])
        ->assertRedirect(indexUrl(['status' => 'Pending']));
});
