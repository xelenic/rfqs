<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Everyone in the chain, plus Admin.
 *
 * @return array{admin: User, bd: User, ops: User, sourcing: User, dataEntry: User, head: User, assistant: User, gm: User}
 */
function actingPeople(): array
{
    return [
        'admin' => userWithRole('Admin'),
        'bd' => userWithRole('Business Development'),
        'ops' => userWithRole('Senior Operations'),
        'sourcing' => userWithRole('Sourcing'),
        'dataEntry' => userWithRole('Data Entry'),
        'head' => userWithRole('Head of Business Development'),
        'assistant' => userWithRole('GM Assistant'),
        'gm' => userWithRole('General Manager'),
    ];
}

/**
 * A two-way split carried as far as $stage: 'sourcing_done' (with Data Entry),
 * 'data_entry_done' (with Senior Operations), 'ops_approved' (with the Head),
 * 'head_approved' (with GM Assistant), 'assistant_done' (with the General
 * Manager), 'gm_approved' (ready for Business Development to close).
 *
 * @param  array{admin: User, bd: User, ops: User, sourcing: User, dataEntry: User, head: User, assistant: User, gm: User}  $people
 */
function actingRfqAt(string $stage, array $people): Rfq
{
    $levels = ['sourcing_done', 'data_entry_done', 'ops_approved', 'head_approved', 'assistant_done', 'gm_approved'];
    $level = array_search($stage, $levels, true);

    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs']), [1 => $people['sourcing'], 2 => $people['sourcing']]);

    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);

        if ($level >= 1) {
            $rfq->refresh()->completeDataEntryPart($part, $people['dataEntry']);
        }
        if ($level >= 2) {
            $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);
        }
        if ($level >= 3) {
            $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
        }
        if ($level >= 4) {
            $rfq->refresh()->recordGmAssistantPart($part, $people['assistant'], 'Acme Ltd', null);
        }
        if ($level >= 5) {
            $rfq->refresh()->approveGmPart($part, $people['gm']);
        }
    }

    return $rfq->refresh();
}

// ---- Admin picks who a stage's action is recorded as done by -----------------

it('records an RFQ as created by the Business Development person Admin picks', function () {
    $people = actingPeople();
    Permission::findOrCreate('rfqs.create');
    $people['admin']->givePermissionTo('rfqs.create');

    test()->actingAs($people['admin'])
        ->post(route('admin.rfqs.store'), ['wc_number' => 'WC1234', 'priority_level' => 'High', 'subject' => 'Replace the lobby lighting', 'acting_user_id' => $people['bd']->id])
        ->assertSessionHasNoErrors();

    expect(Rfq::sole()->created_by)->toBe($people['bd']->id);
});

it('records the Sourcing assignment, and its category, as Senior Operations\' when Admin picks them', function () {
    $people = actingPeople();
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1005']);
    JobCategory::factory()->create(['name' => 'Electrical Works']);

    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.assign', $rfq), [
            'category' => 'Electrical Works',
            'split' => 1,
            'parts' => 2,
            'assignments' => [1 => $people['sourcing']->id, 2 => $people['sourcing']->id],
            'acting_user_id' => $people['ops']->id,
        ])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh())
        ->operations_assigned_by->toBe($people['ops']->id)
        ->category_set_by->toBe($people['ops']->id);
});

it('records Data Entry\'s completion and its return as the Data Entry person Admin picks', function () {
    $people = actingPeople();
    $rfq = actingRfqAt('sourcing_done', $people);

    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'comment' => 'Entered', 'acting_user_id' => $people['dataEntry']->id])
        ->assertSessionHasNoErrors();
    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.return-sourcing', $rfq), ['part' => 2, 'reason' => 'Prices are missing', 'acting_user_id' => $people['dataEntry']->id])
        ->assertSessionHasNoErrors();

    $rfq->refresh();

    expect($rfq->assigneeForPart(1)->pivot->data_entry_completed_by)->toBe($people['dataEntry']->id)
        ->and($rfq->assigneeForPart(2)->pivot->returned_by)->toBe($people['dataEntry']->id);
});

it('records each approval, and the closing, as the person Admin picks for that stage', function (string $stage, string $route, string $column, string $person, string $role) {
    $people = actingPeople();
    $rfq = actingRfqAt($stage, $people);

    test()->actingAs($people['admin'])
        ->patch(route($route, $rfq), ['part' => 1, 'acting_user_id' => $people[$person]->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->{$column})->toBe($people[$person]->id, "recorded as the {$role}");
})->with([
    'Senior Operations approves' => ['data_entry_done', 'admin.rfqs.approve-senior-ops-part', 'senior_ops_reviewed_by', 'ops', 'Senior Operations'],
    'the Head approves' => ['ops_approved', 'admin.rfqs.approve-head-of-bd-part', 'head_of_bd_approved_by', 'head', 'Head of Business Development'],
    'the General Manager approves' => ['assistant_done', 'admin.rfqs.approve-gm-part', 'gm_approved_by', 'gm', 'General Manager'],
    'Business Development closes' => ['gm_approved', 'admin.rfqs.close-part', 'bd_closed_by', 'bd', 'Business Development'],
]);

it('records the whole-RFQ approval and close as the person Admin picks too', function () {
    $people = actingPeople();

    // Every part through Data Entry parks the RFQ at Senior Operations' own review.
    $rfq = Rfq::factory()->create(['stage' => 'senior_ops_review']);
    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.complete-senior-ops-review', $rfq), ['acting_user_id' => $people['ops']->id]);
    expect($rfq->refresh()->senior_ops_reviewed_by)->toBe($people['ops']->id);

    $rfq = Rfq::factory()->create(['stage' => 'bd_closing']);
    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.close', $rfq), ['acting_user_id' => $people['bd']->id]);
    expect($rfq->refresh()->bd_closed_by)->toBe($people['bd']->id);
});

it('records GM Assistant\'s details as the GM Assistant person Admin picks', function () {
    $people = actingPeople();
    $rfq = actingRfqAt('head_approved', $people);

    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.gm-assistant-details', $rfq), ['part' => 1, 'client_details' => 'Acme Ltd', 'acting_user_id' => $people['assistant']->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->gm_assistant_completed_by)->toBe($people['assistant']->id);
});

it('records a rejection as the person Admin picks for the stage that rejected', function (string $stage, string $route, string $person, string $target) {
    $people = actingPeople();
    $rfq = actingRfqAt($stage, $people);

    test()->actingAs($people['admin'])
        ->patch(route($route, $rfq), ['part' => 1, 'target_stage' => $target, 'reason' => 'Look again', 'acting_user_id' => $people[$person]->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->rejected_by)->toBe($people[$person]->id);
})->with([
    'Senior Operations' => ['data_entry_done', 'admin.rfqs.reject-senior-ops', 'ops', 'data_entry'],
    'the Head' => ['ops_approved', 'admin.rfqs.reject-head-of-bd', 'head', 'senior_ops_review'],
    'the General Manager' => ['assistant_done', 'admin.rfqs.reject-gm', 'gm', 'gm_assistant'],
]);

it('records Admin themselves when no one is picked, as it always did', function () {
    $people = actingPeople();
    $rfq = actingRfqAt('data_entry_done', $people);

    test()->actingAs($people['admin'])->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'acting_user_id' => '']);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_by)->toBe($people['admin']->id);
});

// ---- Only Admin, and only someone who holds the role ------------------------

it('ignores the pick from anyone but Admin', function () {
    $people = actingPeople();
    $someoneElse = userWithRole('Senior Operations');
    $rfq = actingRfqAt('data_entry_done', $people);

    test()->actingAs($people['ops'])
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'acting_user_id' => $someoneElse->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_by)->toBe($people['ops']->id);
});

it('refuses a pick of someone who doesn\'t hold the stage\'s role, changing nothing', function () {
    $people = actingPeople();
    $rfq = actingRfqAt('data_entry_done', $people);

    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'acting_user_id' => $people['dataEntry']->id])
        ->assertStatus(422);

    test()->actingAs($people['admin'])
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'acting_user_id' => 999999])
        ->assertStatus(422);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_at)->toBeNull();
});

// ---- The pickers ------------------------------------------------------------

it('offers Admin the Business Development people in the Add RFQ modal, and no one else', function () {
    $people = actingPeople();
    $other = userWithRole('Business Development');
    Permission::findOrCreate('rfqs.create');
    $people['admin']->givePermissionTo('rfqs.create');
    $people['bd']->givePermissionTo('rfqs.create');

    $html = test()->actingAs($people['admin'])->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    $modal = Str::between($html, 'id="createRfqModal"', 'id="editRfqModal"');

    expect($modal)->toContain('id="create-acting-as"')
        ->toContain('Created by')
        ->toContain('Myself (Admin)')
        ->toContain(e($people['bd']->name))
        ->toContain(e($other->name))
        // Only Business Development's own people — not everyone.
        ->not->toContain(e($people['ops']->name));

    test()->actingAs($people['bd'])->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee('acting_user_id', false);
});

it('offers Admin each stage\'s own people beside its Approve and Close buttons, and no one else', function (array $query, string $stage, string $role, string $person) {
    $people = actingPeople();
    $rfq = actingRfqAt($stage, $people);

    $html = test()->actingAs($people['admin'])
        ->get(route('admin.rfqs.index', ['status' => 'Pending'] + $query))
        ->assertOk()->getContent();

    expect($html)->toContain('aria-label="Done by ('.$role.')"')
        ->toContain('<option value="'.$people[$person]->id.'">'.e($people[$person]->name).'</option>');

    // The stage's own people, in their own sidebar view, see nothing of it.
    test()->actingAs($people[$person])
        ->get(route('admin.rfqs.index', ['status' => 'Pending'] + ($query['view'] ?? null ? ['view' => $query['view']] : [])))
        ->assertOk()
        ->assertDontSee('acting_user_id', false);
})->with([
    'Senior Operations\' Review' => [['role' => 'senior-operations', 'view' => 'review'], 'data_entry_done', 'Senior Operations', 'ops'],
    'the Head\'s Review' => [['role' => 'head-of-business-development'], 'ops_approved', 'Head of Business Development', 'head'],
    'the General Manager\'s Review' => [['role' => 'general-manager'], 'assistant_done', 'General Manager', 'gm'],
    'Ready to Close' => [['role' => 'business-development', 'view' => 'closing'], 'gm_approved', 'Business Development', 'bd'],
]);

it('puts the picker in each stage\'s modal too — rejecting, GM Assistant\'s details, assigning, Data Entry\'s prompt', function () {
    $people = actingPeople();
    actingRfqAt('sourcing_done', $people);
    $admin = fn (array $query) => test()->actingAs($people['admin'])->get(route('admin.rfqs.index', ['status' => 'Pending'] + $query))->assertOk()->getContent();

    // Rejecting, from wherever the Head or the General Manager is.
    expect($admin(['role' => 'head-of-business-development']))->toContain('id="reject-acting-as"')->toContain('Rejected by');
    expect($admin(['role' => 'general-manager']))->toContain('id="reject-acting-as"');

    // GM Assistant's client details.
    expect($admin(['role' => 'gm-assistant']))->toContain('id="gm-assistant-acting-as"')->toContain('Added by');

    // The Assign Sourcing wizard's review step.
    expect($admin(['role' => 'senior-operations']))->toContain('id="assign-acting-as"')->toContain('Assigned by');

    // Data Entry's prompt — hidden until one of Data Entry's own actions opens it.
    $dataEntry = $admin(['role' => 'data-entry']);
    expect($dataEntry)->toContain('id="complete-actor"')->toContain('id="complete-acting-as"')
        ->toContain('data-actor-role="Data Entry"');
});

it('shows nothing where nobody holds the role', function () {
    $admin = userWithRole('Admin');
    Permission::findOrCreate('rfqs.create');
    $admin->givePermissionTo('rfqs.create');

    // No Business Development person exists.
    test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee('id="create-acting-as"', false);
});
