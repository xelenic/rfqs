<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use App\Notifications\DataEntryPaused;
use App\Notifications\LineItemsDiscrepancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

// The RFQ list looks up every workflow role's members, so they all exist.
beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

function operationTrainee(): User
{
    return userWithRole('Operation Assistant Trainee');
}

describe('holding the role', function () {
    it('counts as holding HBDs Operations in every role check', function () {
        $trainee = operationTrainee();

        expect($trainee->hasRole('HBDs Operations'))->toBeTrue()
            ->and($trainee->hasAnyRole(['HBDs Operations', 'Admin']))->toBeTrue()
            ->and($trainee->hasAnyRole(User::ALL_RFQS_ROLES))->toBeTrue()
            ->and($trainee->hasRole('HBDs Operations|Sourcing'))->toBeTrue()
            ->and($trainee->hasRole('Operation Assistant Trainee'))->toBeTrue()
            ->and($trainee->canChangeRfqStatus())->toBeTrue()
            ->and($trainee->canSeeCriticalMonitor())->toBeTrue()
            ->and($trainee->canListAllRfqs())->toBeTrue()
            ->and($trainee->canViewPerformanceReports())->toBeTrue();
    });

    it('doesn\'t count as any other role, nor HBDs Operations as a trainee', function () {
        $trainee = operationTrainee();

        expect($trainee->hasRole('Sourcing'))->toBeFalse()
            ->and($trainee->hasAnyRole(['Admin', 'General Manager']))->toBeFalse()
            ->and(userWithRole('HBDs Operations')->hasRole('Operation Assistant Trainee'))->toBeFalse();
    });

    it('is among the people holding HBDs Operations, unless deactivated', function () {
        $operations = userWithRole('HBDs Operations');
        $trainee = operationTrainee();
        $deactivated = operationTrainee()->forceFill(['deactivated_at' => now()]);
        $deactivated->save();
        userWithRole('Sourcing');

        expect(User::query()->holdingRole('HBDs Operations')->pluck('id')->sort()->values()->all())->toBe([$operations->id, $trainee->id])
            ->and(User::query()->holdingAnyRole(['HBDs Operations', 'General Manager'])->pluck('id')->sort()->values()->all())->toBe([$operations->id, $trainee->id])
            ->and(User::query()->holdingRole('Operation Assistant Trainee')->pluck('id')->all())->toBe([$trainee->id]);
    });

    it('gets HBDs Operations\' alerts', function () {
        $trainee = operationTrainee();

        expect(LineItemsDiscrepancy::recipients()->pluck('id')->all())->toContain($trainee->id)
            ->and(DataEntryPaused::recipients()->pluck('id')->all())->toContain($trainee->id);
    });
});

describe('working as HBDs Operations', function () {
    it('gets their sidebar, Unassigned RFQs and dashboard', function () {
        $trainee = operationTrainee();
        Rfq::factory()->create(['subject' => 'ZZZ Waiting to be assigned']);

        test()->actingAs($trainee)
            ->get(route('admin.rfqs.index', ['status' => 'Pending']))
            ->assertOk()
            ->assertSee('<title>Unassigned RFQs', false)
            ->assertSee('ZZZ Waiting to be assigned');

        test()->actingAs($trainee)->get(route('admin.dashboard'))->assertOk()
            ->assertViewIs('admin.dashboard.senior-operations');
    });

    it('can categorize, split and assign an RFQ to Sourcing, recorded as theirs', function () {
        $trainee = operationTrainee();
        $sourcing = userWithRole('Sourcing');
        $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1901']);
        JobCategory::factory()->create(['name' => 'Electrical Works']);

        test()->actingAs($trainee)->patch(route('admin.rfqs.assign', $rfq), [
            'category' => 'Electrical Works',
            'split' => 0,
            'assignments' => [1 => $sourcing->id],
        ])->assertRedirect()->assertSessionHas('status');

        expect($rfq->refresh()->operations_assigned_by)->toBe($trainee->id)
            ->and($rfq->assignees->pluck('id')->all())->toBe([$sourcing->id]);
    });

    it('can put an RFQ on hold', function () {
        $rfq = Rfq::factory()->create();

        test()->actingAs(operationTrainee())
            ->patch(route('admin.rfqs.change-status', $rfq), ['status' => 'On Hold', 'reason' => 'Waiting on the client'])
            ->assertRedirect();

        expect($rfq->refresh()->status)->toBe('On Hold');
    });

    it('is offered on Admin\'s "Done by" picker and accepted as who did it', function () {
        $trainee = operationTrainee();
        $sourcing = userWithRole('Sourcing');
        $rfq = Rfq::factory()->create();
        JobCategory::factory()->create(['name' => 'Electrical Works']);
        $admin = userWithRole('Admin');

        test()->actingAs($admin)
            ->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'hbds-operations']))
            ->assertOk()
            ->assertSee('<option value="'.$trainee->id.'" >'.e($trainee->name).'</option>', false);

        test()->actingAs($admin)->patch(route('admin.rfqs.assign', $rfq), [
            'category' => 'Electrical Works',
            'split' => 0,
            'assignments' => [1 => $sourcing->id],
            'acting_user_id' => $trainee->id,
        ])->assertRedirect();

        expect($rfq->refresh()->operations_assigned_by)->toBe($trainee->id);
    });
});

describe('the priority level', function () {
    /**
     * What the Edit form sends for $rfq, with $changes.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    function traineeEditPayload(Rfq $rfq, array $changes = []): array
    {
        return $changes + [
            'wc_number' => $rfq->wc_number,
            'rfq_number' => $rfq->rfq_number,
            'priority_level' => $rfq->priority_level,
            'number_of_items' => $rfq->number_of_items,
            'status' => 'Pending',
            'subject' => $rfq->subject,
            'description' => $rfq->description,
        ];
    }

    it('stays as it was when a trainee edits an RFQ, while the rest of their edit saves', function () {
        $rfq = Rfq::factory()->create(['subject' => 'Replace exit signs', 'priority_level' => 'Low']);

        test()->actingAs(operationTrainee())
            ->put(route('admin.rfqs.update', $rfq), traineeEditPayload($rfq, ['subject' => 'Replace exit signs — all floors', 'priority_level' => 'Urgent']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'RFQ updated successfully.');

        expect($rfq->refresh())
            ->subject->toBe('Replace exit signs — all floors')
            ->priority_level->toBe('Low');
    });

    it('is saved without one from a trainee, whose Edit form doesn\'t send it', function () {
        $rfq = Rfq::factory()->create(['priority_level' => 'High']);
        $payload = traineeEditPayload($rfq, ['subject' => 'ZZZ Renamed']);
        unset($payload['priority_level']);

        test()->actingAs(operationTrainee())->put(route('admin.rfqs.update', $rfq), $payload)->assertSessionHasNoErrors();

        expect($rfq->refresh())->subject->toBe('ZZZ Renamed')->priority_level->toBe('High');
    });

    it('is locked on a trainee\'s Edit form, and open on HBDs Operations\'', function () {
        $rfq = Rfq::factory()->create();
        $field = '<select name="priority_level" id="edit-priority_level" class="form-select " required';

        test()->actingAs(operationTrainee())->get(route('admin.rfqs.show', $rfq))->assertOk()
            ->assertSee($field.' disabled>', false)
            ->assertSee('Your role can\'t change the priority level.', false);

        test()->actingAs(userWithRole('HBDs Operations'))->get(route('admin.rfqs.show', $rfq))->assertOk()
            ->assertSee($field.' >', false)
            ->assertDontSee('Your role can\'t change the priority level.', false);
    });

    it('can still be changed by HBDs Operations, and by a trainee who also holds HBDs Operations', function () {
        $alsoOperations = operationTrainee()->assignRole('HBDs Operations');

        expect(userWithRole('HBDs Operations')->canChangeRfqPriority())->toBeTrue()
            ->and(userWithRole('Admin')->canChangeRfqPriority())->toBeTrue()
            ->and($alsoOperations->canChangeRfqPriority())->toBeTrue()
            ->and(operationTrainee()->canChangeRfqPriority())->toBeFalse();

        $rfq = Rfq::factory()->create(['priority_level' => 'Low']);
        test()->actingAs($alsoOperations)->put(route('admin.rfqs.update', $rfq), traineeEditPayload($rfq, ['priority_level' => 'Urgent']))->assertSessionHasNoErrors();

        expect($rfq->refresh()->priority_level)->toBe('Urgent');
    });
});
