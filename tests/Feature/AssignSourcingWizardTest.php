<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

function userWithRole(string $role): User
{
    Permission::findOrCreate('rfqs.view');
    Permission::findOrCreate('rfqs.edit');

    Role::findOrCreate($role)->givePermissionTo(['rfqs.view', 'rfqs.edit']);

    return User::factory()->create()->assignRole($role);
}

function assignSourcing(User $actor, Rfq $rfq, array $payload)
{
    return test()->actingAs($actor)->patch(route('admin.rfqs.assign', $rfq), $payload);
}

it('categorizes, splits and assigns some parts while leaving others empty', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1005']);
    JobCategory::factory()->create(['name' => 'Electrical Works']);

    assignSourcing($ops, $rfq, [
        'category' => 'Electrical Works',
        'split' => 1,
        'parts' => 5,
        'assignments' => [1 => $riley->id, 2 => '', 3 => $sam->id, 4 => '', 5 => ''],
    ])->assertRedirect()->assertSessionHas('status');

    $rfq->refresh();

    expect($rfq->category)->toBe('Electrical Works')
        ->and($rfq->split_count)->toBe(5)
        ->and($rfq->assignees)->toHaveCount(2)
        ->and($rfq->hasUnassignedParts())->toBeTrue()
        ->and($rfq->operations_assigned_by)->toBe($ops->id)
        ->and($rfq->sourcingParts()->pluck('number')->all())->toBe([
            'RFQ1005-P1 of P5', 'RFQ1005-P2 of P5', 'RFQ1005-P3 of P5', 'RFQ1005-P4 of P5', 'RFQ1005-P5 of P5',
        ])
        // Sam holds part 3 even though part 2 is still empty.
        ->and($rfq->sourcingSplitNumberFor($sam))->toBe('RFQ1005-P3 of P5')
        ->and($rfq->sourcingParts()->whereNotNull('assignee')->pluck('part')->all())->toBe([1, 3]);
});

it('stores a category typed in by hand, reusing an existing one regardless of case', function () {
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety']);

    $first = Rfq::factory()->create();
    assignSourcing($ops, $first, [
        'category' => JobCategory::NEW_OPTION,
        'new_category' => '  Marine Works ',
        'assignments' => [1 => $sourcing->id],
    ])->assertSessionHas('status');

    $second = Rfq::factory()->create();
    assignSourcing($ops, $second, [
        'category' => JobCategory::NEW_OPTION,
        'new_category' => 'fire safety',
        'assignments' => [1 => $sourcing->id],
    ]);

    expect(JobCategory::where('name', 'Marine Works')->exists())->toBeTrue()
        ->and(JobCategory::whereRaw('lower(name) = ?', ['fire safety'])->count())->toBe(1)
        ->and($first->refresh()->category)->toBe('Marine Works')
        ->and($second->refresh()->category)->toBe('Fire Safety');
});

it('requires a category and, unsplit, exactly one Sourcing member', function () {
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create();

    assignSourcing($ops, $rfq, ['assignments' => [1 => $sourcing->id]])
        ->assertSessionHas('error');

    assignSourcing($ops, $rfq, ['category' => 'Fire Safety', 'assignments' => [1 => '']])
        ->assertSessionHas('error');

    expect($rfq->refresh()->split_count)->toBeNull()
        ->and($rfq->assignees)->toBeEmpty();
});

it('holds the RFQ back from Data Entry until every part is assigned and done', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = Rfq::factory()->create();
    JobCategory::factory()->create(['name' => 'Fire Safety']);

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 2,
        'assignments' => [1 => $riley->id, 2 => ''],
    ]);

    $rfq->refresh()->completeSourcingPartFor($riley);
    expect($rfq->refresh()->isWithDataEntry())->toBeFalse()
        ->and($rfq->allSourcingPartsCompleted())->toBeFalse();

    // Filling the empty part later doesn't disturb who holds part 1.
    assignSourcing($ops, $rfq, ['assignments' => [2 => $sam->id]])
        ->assertSessionHas('status');

    $rfq->refresh();
    expect($rfq->hasUnassignedParts())->toBeFalse()
        ->and($rfq->sourcingSplitNumberFor($riley))->toBe($rfq->rfq_number.'-P1 of P2')
        ->and($rfq->sourcingSplitNumberFor($sam))->toBe($rfq->rfq_number.'-P2 of P2');

    $rfq->completeSourcingPartFor($sam);
    expect($rfq->refresh()->isWithDataEntry())->toBeTrue();
});

it('never reassigns a part someone already holds', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = Rfq::factory()->create();
    JobCategory::factory()->create(['name' => 'Fire Safety']);

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 2,
        'assignments' => [1 => $riley->id],
    ]);

    assignSourcing($ops, $rfq, ['assignments' => [1 => $sam->id]]);

    expect($rfq->refresh()->assignees->pluck('id')->all())->toBe([$riley->id]);
});

it('only assigns people with the Sourcing role', function () {
    $ops = userWithRole('Senior Operations');
    userWithRole('Sourcing');
    $notSourcing = userWithRole('Data Entry');
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create();

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 2,
        'assignments' => [1 => $notSourcing->id],
    ])->assertSessionHas('error');

    expect($rfq->refresh()->assignees)->toBeEmpty()
        ->and($rfq->split_count)->toBeNull()
        ->and($rfq->parts)->toBeEmpty();
});

it('lets one person take several parts, kept as a single share', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1005']);

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 5,
        'assignments' => [1 => $riley->id, 2 => $sam->id, 3 => '', 4 => $riley->id, 5 => ''],
    ])->assertSessionHas('status');

    $rfq->refresh();

    expect($rfq->assignees)->toHaveCount(2)
        ->and($rfq->partNumbersFor($riley))->toBe([1, 4])
        ->and($rfq->partNumbersFor($sam))->toBe([2])
        ->and($rfq->sourcingSplitNumberFor($riley))->toBe('RFQ1005-P1 & P4 of P5')
        ->and($rfq->sourcingSplitNumberFor($sam))->toBe('RFQ1005-P2 of P5')
        ->and($rfq->sourcingParts()->pluck('assignee.id')->all())->toBe([$riley->id, $sam->id, null, $riley->id, null]);
});

it('words three or more held parts as a list', function () {
    $ops = userWithRole('Senior Operations');
    $riley = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1005']);

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 5,
        'assignments' => [1 => $riley->id, 2 => $riley->id, 4 => $riley->id],
    ]);

    expect($rfq->refresh()->sourcingSplitNumberFor($riley))->toBe('RFQ1005-P1, P2 & P4 of P5');
});

it('completes all of a person\'s parts together and hands off once every part is done', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create();

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 3,
        'assignments' => [1 => $riley->id, 2 => $sam->id, 3 => $riley->id],
    ]);

    $rfq->refresh()->completeSourcingPartFor($riley);
    expect($rfq->refresh()->hasCompletedSourcingPart($riley))->toBeTrue()
        ->and($rfq->isWithDataEntry())->toBeFalse();

    $rfq->completeSourcingPartFor($sam);
    expect($rfq->refresh()->isWithDataEntry())->toBeTrue();
});

it('adds more parts to a share that is not finished yet, but never to a finished one', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1005']);

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 4,
        'assignments' => [1 => $riley->id, 2 => $sam->id],
    ]);

    // Riley hasn't finished — part 3 joins her existing share, still one assignment row.
    assignSourcing($ops, $rfq, ['assignments' => [3 => $riley->id]])->assertSessionHas('status');
    expect($rfq->refresh()->assignees)->toHaveCount(2)
        ->and($rfq->partNumbersFor($riley))->toBe([1, 3]);

    // Sam finishes — part 4 can't be added to a finished share.
    $rfq->completeSourcingPartFor($sam);
    assignSourcing($ops, $rfq, ['assignments' => [4 => $sam->id]])->assertSessionHas('error');
    expect($rfq->refresh()->partNumbersFor($sam))->toBe([2])
        ->and($rfq->hasUnassignedParts())->toBeTrue();
});

it('keeps Sourcing itself from assigning', function () {
    $sourcing = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create();

    assignSourcing($sourcing, $rfq, [
        'category' => 'Fire Safety',
        'assignments' => [1 => $sourcing->id],
    ])->assertForbidden();
});

it('leaves an RFQ assigned before parts could be planned alone', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1001']);
    $rfq->assignees()->attach([$riley->id, $sam->id]);

    // Legacy numbering still follows assignment order.
    expect($rfq->refresh()->sourcingSplitNumberFor($sam))->toBe('RFQ1001-P2 of P2')
        ->and($rfq->hasUnassignedParts())->toBeFalse();

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'assignments' => [1 => $riley->id],
    ])->assertStatus(422);
});

it('lists RFQs with empty parts in Operations\' queue until they are full', function () {
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create();

    expect(Rfq::needingSourcing()->pluck('id')->all())->toBe([$rfq->id]);

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 3,
        'assignments' => [1 => $sourcing->id],
    ]);

    expect(Rfq::needingSourcing()->pluck('id')->all())->toBe([$rfq->id])
        ->and(Rfq::fullySourced()->count())->toBe(0);

    // Two people cover the remaining two parts between them — still full.
    $other = userWithRole('Sourcing');
    assignSourcing($ops, $rfq, ['assignments' => [2 => $other->id, 3 => $sourcing->id]]);

    expect(Rfq::needingSourcing()->count())->toBe(0)
        ->and(Rfq::fullySourced()->pluck('id')->all())->toBe([$rfq->id]);
});
