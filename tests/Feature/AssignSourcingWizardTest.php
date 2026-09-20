<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use Database\Seeders\JobCategorySeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

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

    $rfq->refresh()->completeSourcingPart(1);
    expect($rfq->refresh()->isWithDataEntry())->toBeFalse()
        ->and($rfq->allSourcingPartsCompleted())->toBeFalse();

    // Filling the empty part later doesn't disturb who holds part 1.
    assignSourcing($ops, $rfq, ['assignments' => [2 => $sam->id]])
        ->assertSessionHas('status');

    $rfq->refresh();
    expect($rfq->hasUnassignedParts())->toBeFalse()
        ->and($rfq->sourcingSplitNumberFor($riley))->toBe($rfq->rfq_number.'-P1 of P2')
        ->and($rfq->sourcingSplitNumberFor($sam))->toBe($rfq->rfq_number.'-P2 of P2');

    $rfq->completeSourcingPart(2);
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

it('lets one person take several parts, each its own assignment', function () {
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

    expect($rfq->assignees)->toHaveCount(3)
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

it('completes each of a person\'s parts on its own and hands off once every part is done', function () {
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

    $rfq->refresh()->completeSourcingPart(1);
    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->not->toBeNull()
        // Riley's other part is untouched by finishing this one.
        ->and($rfq->assigneeForPart(3)->pivot->completed_at)->toBeNull()
        ->and($rfq->isWithDataEntry())->toBeFalse();

    $rfq->completeSourcingPart(2);
    expect($rfq->refresh()->isWithDataEntry())->toBeFalse();

    $rfq->completeSourcingPart(3);
    expect($rfq->refresh()->isWithDataEntry())->toBeTrue();
});

it('lets a person take another part even after finishing one', function () {
    $ops = userWithRole('Senior Operations');
    $riley = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ1005']);

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 3,
        'assignments' => [1 => $riley->id],
    ]);
    $rfq->refresh()->completeSourcingPart(1);

    assignSourcing($ops, $rfq, ['assignments' => [3 => $riley->id]])->assertSessionHas('status');

    $rfq->refresh();
    expect($rfq->partNumbersFor($riley))->toBe([1, 3])
        // Finished stays finished; the new part starts out pending.
        ->and($rfq->assigneeForPart(1)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(3)->pivot->completed_at)->toBeNull();
});

it('only lets the part\'s own assignee complete it', function () {
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

    // Sam can't complete Riley's part; Riley can complete either of hers, separately.
    test()->actingAs($sam)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1])->assertForbidden();
    test()->actingAs($riley)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 3, 'comment' => 'Quotes attached'])->assertSessionHas('status');

    $rfq->refresh();
    expect($rfq->assigneeForPart(3)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(1)->pivot->completed_at)->toBeNull();
});

it('has Data Entry process and return parts one at a time, even ones held by the same person', function () {
    $ops = userWithRole('Senior Operations');
    $dataEntry = userWithRole('Data Entry');
    $riley = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create();

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 2,
        'assignments' => [1 => $riley->id, 2 => $riley->id],
    ]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->completeSourcingPart(2);
    expect($rfq->refresh()->isWithDataEntry())->toBeTrue();

    // Data Entry finishes part 1 only — the RFQ doesn't move on yet.
    test()->actingAs($dataEntry)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'comment' => 'Entered'])->assertSessionHas('status');
    expect($rfq->refresh()->stage)->toBeNull()
        ->and($rfq->assigneeForPart(1)->pivot->data_entry_completed_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->data_entry_completed_at)->toBeNull();

    // Sending part 2 back reopens just that part.
    test()->actingAs($dataEntry)->patch(route('admin.rfqs.return-sourcing', $rfq), ['part' => 2, 'reason' => 'Prices are missing'])->assertSessionHas('status');
    $rfq->refresh();
    expect($rfq->assigneeForPart(2)->pivot->completed_at)->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->return_reason)->toBe('Prices are missing')
        ->and($rfq->assigneeForPart(1)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(1)->pivot->returned_at)->toBeNull()
        ->and($rfq->isWithDataEntry())->toBeFalse();
});

it('moves to Senior Operations\' review only once Data Entry has finished every part', function () {
    $ops = userWithRole('Senior Operations');
    $dataEntry = userWithRole('Data Entry');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    JobCategory::factory()->create(['name' => 'Fire Safety']);
    $rfq = Rfq::factory()->create();

    assignSourcing($ops, $rfq, [
        'category' => 'Fire Safety',
        'split' => 1,
        'parts' => 3,
        'assignments' => [1 => $riley->id, 2 => $sam->id, 3 => $riley->id],
    ]);
    foreach ([1, 2, 3] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
    }

    foreach ([1, 2] as $part) {
        test()->actingAs($dataEntry)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => $part, 'comment' => 'Entered']);
        expect($rfq->refresh()->stage)->toBeNull();
    }

    test()->actingAs($dataEntry)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 3, 'comment' => 'Entered']);
    expect($rfq->refresh()->stage)->toBe('senior_ops_review');
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

it('stores the description typed in with a new category', function () {
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');
    $rfq = Rfq::factory()->create();

    assignSourcing($ops, $rfq, [
        'category' => JobCategory::NEW_OPTION,
        'new_category' => 'Elevator Maintenance',
        'new_category_description' => '  Servicing, repairs and inspections of lifts and escalators. ',
        'assignments' => [1 => $sourcing->id],
    ])->assertSessionHas('status');

    expect(JobCategory::where('name', 'Elevator Maintenance')->value('description'))
        ->toBe('Servicing, repairs and inspections of lifts and escalators.');
});

it('leaves a new category\'s description empty when none is typed', function () {
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');

    assignSourcing($ops, Rfq::factory()->create(), [
        'category' => JobCategory::NEW_OPTION,
        'new_category' => 'Elevator Maintenance',
        'new_category_description' => '   ',
        'assignments' => [1 => $sourcing->id],
    ])->assertSessionHas('status');

    expect(JobCategory::where('name', 'Elevator Maintenance')->value('description'))->toBeNull();
});

it('keeps an existing category\'s description when the same name is typed in again', function () {
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');
    JobCategory::factory()->create(['name' => 'Fire Safety', 'description' => 'Alarms and extinguishers.']);

    assignSourcing($ops, Rfq::factory()->create(), [
        'category' => JobCategory::NEW_OPTION,
        'new_category' => 'fire safety',
        'new_category_description' => 'Something else entirely.',
        'assignments' => [1 => $sourcing->id],
    ])->assertSessionHas('status');

    expect(JobCategory::where('name', 'Fire Safety')->value('description'))->toBe('Alarms and extinguishers.');
});

it('turns down a category description that\'s too long', function () {
    $ops = userWithRole('Senior Operations');
    $sourcing = userWithRole('Sourcing');

    assignSourcing($ops, Rfq::factory()->create(), [
        'category' => JobCategory::NEW_OPTION,
        'new_category' => 'Elevator Maintenance',
        'new_category_description' => str_repeat('a', 501),
        'assignments' => [1 => $sourcing->id],
    ])->assertSessionHas('error');

    expect(JobCategory::where('name', 'Elevator Maintenance')->exists())->toBeFalse();
});

it('gives the wizard each category\'s description and icon to show', function () {
    JobCategory::factory()->create(['name' => 'Electrical Works', 'description' => 'Wiring and lighting.']);
    JobCategory::factory()->create(['name' => 'Elevator Maintenance', 'description' => null]);
    userWithRole('Sourcing');

    test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index'))
        ->assertOk()
        ->assertSee('<option value="Electrical Works" data-icon="bi-lightning-charge" data-description="Wiring and lighting.">Electrical Works</option>', false)
        // No description yet: nothing to show, and a plain tag for an icon.
        ->assertSee('<option value="Elevator Maintenance" data-icon="bi-tag" data-description="">Elevator Maintenance</option>', false)
        // The new-category form asks for one.
        ->assertSee('name="new_category_description"', false);
});

it('picks a category\'s icon from a keyword in its name', function (string $name, string $icon) {
    expect((new JobCategory(['name' => $name]))->icon())->toBe($icon);
})->with([
    'Electrical Works' => ['Electrical Works', 'bi-lightning-charge'],
    'Plumbing & Sanitary' => ['Plumbing & Sanitary', 'bi-droplet'],
    'HVAC & Air Conditioning' => ['HVAC & Air Conditioning', 'bi-snow'],
    'Fire Safety' => ['Fire Safety', 'bi-fire'],
    'IT & Networking' => ['IT & Networking', 'bi-hdd-network'],
    'typed by hand' => ['electrical repairs', 'bi-lightning-charge'],
    'nothing matches' => ['Elevator Maintenance', 'bi-tag'],
]);

it('seeds a description for every starter category without overwriting one already written', function () {
    JobCategory::factory()->create(['name' => 'Fire Safety', 'description' => 'Our own wording.']);
    JobCategory::factory()->create(['name' => 'Landscaping', 'description' => null]);

    test()->seed(JobCategorySeeder::class);

    expect(JobCategory::count())->toBe(12)
        ->and(JobCategory::whereNull('description')->count())->toBe(0)
        ->and(JobCategory::where('name', 'Fire Safety')->value('description'))->toBe('Our own wording.')
        ->and(JobCategory::where('name', 'Landscaping')->value('description'))->toContain('lawns');

    // Running it again changes nothing.
    test()->seed(JobCategorySeeder::class);
    expect(JobCategory::count())->toBe(12);
});

it('lays the wizard out as three steps — category, split, then assigning the parts', function () {
    userWithRole('Sourcing');

    $response = test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index'))
        ->assertOk();

    foreach ([1, 2, 3] as $step) {
        $response->assertSee('data-step-tab="'.$step.'"', false)
            ->assertSee('data-step-panel="'.$step.'"', false);
    }

    // The split choice and the per-part pick-lists live on separate steps.
    $html = $response->getContent();
    $splitStep = strpos($html, 'data-step-panel="2"');
    $assignStep = strpos($html, 'data-step-panel="3"');

    expect(strpos($html, 'name="split_choice"'))->toBeGreaterThan($splitStep)->toBeLessThan($assignStep)
        ->and(strpos($html, 'id="assign-parts-list"'))->toBeGreaterThan($assignStep);
});

it('offers a stepper and quick picks for how many parts, within the limits', function () {
    userWithRole('Sourcing');

    $response = test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index'))
        ->assertOk()
        // The number field is the one that's submitted, with − and + either side.
        ->assertSee('name="parts" id="assign-parts-count"', false)
        ->assertSee('min="2" max="'.Rfq::MAX_SPLIT_PARTS.'"', false)
        ->assertSee('id="assign-parts-minus"', false)
        ->assertSee('id="assign-parts-plus"', false)
        ->assertSee('Between 2 and '.Rfq::MAX_SPLIT_PARTS);

    foreach ([2, 3, 4, 5, 6, 8, 10] as $count) {
        $response->assertSee('data-parts="'.$count.'"', false);
    }
});

it('ends the wizard on a summary step, the only place Finish appears', function () {
    userWithRole('Sourcing');

    $response = test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index'))
        ->assertOk()
        ->assertSee('data-step-tab="4"', false)
        ->assertSee('data-step-panel="4"', false)
        // What the summary lays out, and the way back to each step.
        ->assertSee('id="review-category-name"', false)
        ->assertSee('id="review-parts"', false)
        ->assertSee('id="review-note"', false)
        ->assertSee('data-goto-step="1"', false)
        ->assertSee('data-goto-step="2"', false)
        ->assertSee('data-goto-step="3"', false)
        // Finish starts hidden and animates in when the summary shows.
        ->assertSee('class="btn btn-primary wizard-finish d-none" id="assign-wizard-finish"', false);

    $html = $response->getContent();

    // The summary comes after assigning the parts, and Finish is only in the footer.
    expect(strpos($html, 'data-step-panel="4"'))->toBeGreaterThan(strpos($html, 'data-step-panel="3"'))
        ->and(substr_count($html, 'id="assign-wizard-finish"'))->toBe(1);
});

it('gives each Sourcing member\'s bare name to the summary', function () {
    $sam = userWithRole('Sourcing');

    test()->actingAs(userWithRole('Senior Operations'))
        ->get(route('admin.rfqs.index'))
        ->assertSee('data-name="'.e($sam->name).'"', false);
});
