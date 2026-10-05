<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * An RFQ Senior Operations has just sent back to Business Development —
 * unassigned, unplanned, waiting to be fixed. Priya is the Senior
 * Operations person who re-assigns it afterwards.
 *
 * @return array{bd: User, priya: User, riley: User, rfq: Rfq}
 */
function returnedToBd(): array
{
    JobCategory::query()->firstOrCreate(['name' => 'Electrical Works']);
    $priya = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $riley = userWithRole('Sourcing');

    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2001', 'subject' => 'Wrong building entirely', 'stage' => 'senior_ops_review']);
    $rfq->update(['operations_assigned_by' => $priya->id, 'operations_assigned_at' => now()]);

    test()->actingAs(userWithRole('Senior Operations'))
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'business_development', 'reason' => 'Confirm the site with the client']);

    return ['bd' => $bd, 'priya' => $priya, 'riley' => $riley, 'rfq' => $rfq->refresh()];
}

// ---- Editing -------------------------------------------------------------

it('lets Business Development edit a returned RFQ\'s own details, always leaving it Pending', function () {
    ['bd' => $bd, 'rfq' => $rfq] = returnedToBd();

    test()->actingAs($bd)
        ->put(route('admin.rfqs.update', $rfq), [
            'wc_number' => $rfq->wc_number,
            'rfq_number' => $rfq->rfq_number,
            'priority_level' => 'High',
            'number_of_items' => 5,
            'status' => 'Completed', // ignored — see RfqController::update()
            'subject' => 'Correct building: Tower B',
            'description' => 'Client confirmed it\'s Tower B, not Tower A.',
        ])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh())
        ->subject->toBe('Correct building: Tower B')
        ->priority_level->toBe('High')
        ->status->toBe('Pending');
});

it('takes a returned RFQ off the Returns page once Business Development saves an edit, banner and badge included', function () {
    ['bd' => $bd, 'rfq' => $rfq] = returnedToBd();

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('RFQ2001');

    test()->actingAs($bd)
        ->put(route('admin.rfqs.update', $rfq), [
            'wc_number' => $rfq->wc_number,
            'rfq_number' => $rfq->rfq_number,
            'priority_level' => 'Medium',
            'number_of_items' => 5,
            'status' => 'Pending',
            'subject' => 'Correct building: Tower B',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'RFQ updated — it\'s been taken off the Returns list.');

    expect($rfq->refresh())
        ->isReturnedToBusinessDevelopment()->toBeFalse()
        ->reject_reason->toBeNull()
        // Still counted, so a second time round is flagged as one.
        ->bd_return_count->toBe(1)
        ->and(Rfq::bdReturnsCount())->toBe(0)
        // The reason itself stays in the comment thread.
        ->and($rfq->comments()->where('action', 'rejected')->value('body'))->toBe('Confirm the site with the client');

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertDontSee('RFQ2001')
        ->assertSee('Nothing\'s been sent back to Business Development.', false);

    test()->actingAs($bd)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertDontSee('Sent back to Business Development');

    // Senior Operations still has it to re-assign.
    expect(Rfq::needingSourcing()->pluck('id'))->toContain($rfq->id);
});

it('takes a returned RFQ off the Returns page when Admin saves an edit too', function () {
    ['rfq' => $rfq] = returnedToBd();

    test()->actingAs(userWithRole('Admin'))
        ->put(route('admin.rfqs.update', $rfq), [
            'wc_number' => $rfq->wc_number,
            'rfq_number' => $rfq->rfq_number,
            'priority_level' => 'Medium',
            'number_of_items' => 5,
            'status' => 'Pending',
            'subject' => 'Correct building: Tower B',
        ])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->isReturnedToBusinessDevelopment())->toBeFalse();
});

it('leaves a rejection to any other stage alone when the RFQ is edited', function () {
    $rfq = Rfq::factory()->create(['reject_target_stage' => 'data_entry', 'reject_reason' => 'Totals don\'t add up']);

    test()->actingAs(userWithRole('Admin'))
        ->put(route('admin.rfqs.update', $rfq), [
            'wc_number' => $rfq->wc_number,
            'rfq_number' => $rfq->rfq_number,
            'priority_level' => 'Medium',
            'number_of_items' => 5,
            'status' => 'Pending',
            'subject' => 'Anything',
        ])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh())
        ->reject_target_stage->toBe('data_entry')
        ->reject_reason->toBe('Totals don\'t add up');
});

it('shows Business Development the Edit button on a returned RFQ, and not otherwise', function () {
    ['bd' => $bd, 'rfq' => $rfq] = returnedToBd();
    $untouched = Rfq::factory()->create(['rfq_number' => 'RFQ3001']);

    test()->actingAs($bd)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('data-action="'.route('admin.rfqs.update', $rfq).'"', false);

    test()->actingAs($bd)->get(route('admin.rfqs.show', $untouched))->assertOk()
        ->assertDontSee('data-action="'.route('admin.rfqs.update', $untouched).'"', false);
});

it('gives Business Development no status field to choose from, even editing a returned RFQ', function () {
    ['bd' => $bd, 'rfq' => $rfq] = returnedToBd();

    test()->actingAs($bd)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertDontSee('id="edit-status"', false);
});

// ---- Assigning -------------------------------------------------------------

it('refuses Business Development assigning Sourcing, even on an RFQ sent back to them, changing nothing', function () {
    ['bd' => $bd, 'priya' => $priya, 'riley' => $riley, 'rfq' => $rfq] = returnedToBd();

    test()->actingAs($bd)
        ->patch(route('admin.rfqs.assign', $rfq), [
            'category' => 'Electrical Works',
            'assignments' => [1 => $riley->id],
            'acting_user_id' => $priya->id,
        ])
        ->assertForbidden();

    expect($rfq->refresh())
        ->category->toBeNull()
        ->operations_assigned_by->toBeNull()
        ->and($rfq->assignees)->toHaveCount(0);
});

it('leaves a returned RFQ in Senior Operations\' Unassigned queue for them to re-assign', function () {
    ['rfq' => $rfq] = returnedToBd();

    expect(Rfq::needingSourcing()->pluck('id'))->toContain($rfq->id);
});

it('refuses Business Development assigning an RFQ that hasn\'t been sent back to them', function () {
    $bd = userWithRole('Business Development');
    $priya = userWithRole('Senior Operations');
    $riley = userWithRole('Sourcing');
    $rfq = Rfq::factory()->create();

    test()->actingAs($bd)
        ->patch(route('admin.rfqs.assign', $rfq), ['category' => 'Electrical Works', 'assignments' => [1 => $riley->id], 'acting_user_id' => $priya->id])
        ->assertForbidden();

    expect($rfq->refresh()->assignees)->toHaveCount(0);
});

it('gives Business Development no Assign Sourcing button on their Returns page', function () {
    ['bd' => $bd, 'rfq' => $rfq] = returnedToBd();

    test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee($rfq->rfq_number)
        ->assertSee('data-action="'.route('admin.rfqs.update', $rfq).'"', false)
        ->assertDontSee('data-action="'.route('admin.rfqs.assign', $rfq).'"', false);
});

it('shows Business Development only a blurred, disabled Assign Sourcing button on a returned RFQ\'s own page, same as any other', function () {
    ['bd' => $bd, 'rfq' => $rfq] = returnedToBd();

    $html = test()->actingAs($bd)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect($html)->toContain('js-assign-rfq rfq-blurred')
        ->not->toContain('id="assign-acting-as"');
});

// ---- The "sent back more than once" badge -----------------------------------

it('flags a second and third time round differently from a first, on the Returns list and the RFQ page', function () {
    ['bd' => $bd, 'priya' => $priya, 'riley' => $riley, 'rfq' => $rfq] = returnedToBd();

    $first = test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()->getContent();
    expect($first)->not->toContain('time</span>');

    // Senior Operations re-assigns it…
    test()->actingAs($priya)->patch(route('admin.rfqs.assign', $rfq), [
        'category' => 'Electrical Works', 'assignments' => [1 => $riley->id],
    ])->assertSessionHasNoErrors();

    // …it runs back through Sourcing and Data Entry, reaching Senior
    // Operations' review again — and they send it straight back a second time.
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->completeDataEntryPart(1, userWithRole('Data Entry'));
    $rfq->refresh()->finalizePart(1);
    expect($rfq->refresh()->stage)->toBe('senior_ops_review');

    test()->actingAs($priya)
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'business_development', 'reason' => 'Still wrong'])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->bd_return_count)->toBe(2);

    $second = test()->actingAs($bd)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()->getContent();
    expect($second)->toContain('2nd time');

    test()->actingAs($bd)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('2nd time');
});

it('gives Rfq::ordinal() its exceptions right', function () {
    expect(Rfq::ordinal(1))->toBe('1st')
        ->and(Rfq::ordinal(2))->toBe('2nd')
        ->and(Rfq::ordinal(3))->toBe('3rd')
        ->and(Rfq::ordinal(4))->toBe('4th')
        ->and(Rfq::ordinal(11))->toBe('11th')
        ->and(Rfq::ordinal(12))->toBe('12th')
        ->and(Rfq::ordinal(13))->toBe('13th')
        ->and(Rfq::ordinal(21))->toBe('21st')
        ->and(Rfq::ordinal(22))->toBe('22nd')
        ->and(Rfq::ordinal(23))->toBe('23rd')
        ->and(Rfq::ordinal(111))->toBe('111th');
});
