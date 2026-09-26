<?php

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
 * A two-way split held by two Sourcing members, and an Admin.
 *
 * @return array{admin: User, riley: User, sam: User, rfq: Rfq}
 */
function adminSourcingSplit(): array
{
    $riley = userWithRole('Sourcing');
    $sam = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs']), [1 => $riley, 2 => $sam]);

    return ['admin' => userWithRole('Admin'), 'riley' => $riley, 'sam' => $sam, 'rfq' => $rfq];
}

// ---- Completing a part as its assigned Sourcing member -----------------------

it('lets Admin mark a part complete as the Sourcing member it is assigned to', function () {
    ['admin' => $admin, 'riley' => $riley, 'rfq' => $rfq] = adminSourcingSplit();

    test()->actingAs($admin)
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Quotes gathered', 'acting_user_id' => $riley->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'Part marked complete — waiting on the rest of the parts.');

    $rfq->refresh();

    expect($rfq->assigneeForPart(1)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->assigneeForPart(2)->pivot->completed_at)->toBeNull()
        ->and($rfq->isWithDataEntry())->toBeFalse();

    // The completion note is the assignee's own, in the thread Data Entry reads.
    expect($rfq->comments()->where('body', 'like', '%Quotes gathered%')->first()->user_id)->toBe($riley->id);
});

it('hands off straight away when Admin completes an RFQ\'s only part', function () {
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Done', 'acting_user_id' => $riley->id])
        ->assertSessionHas('status', 'Marked complete — handed off to Data Entry.');

    expect($rfq->refresh()->isWithDataEntry())->toBeTrue();
});

it('hands the RFQ to Data Entry once Admin has completed every part', function () {
    ['admin' => $admin, 'riley' => $riley, 'sam' => $sam, 'rfq' => $rfq] = adminSourcingSplit();

    test()->actingAs($admin)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'One', 'acting_user_id' => $riley->id])->assertSessionHasNoErrors();
    test()->actingAs($admin)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 2, 'comment' => 'Two', 'acting_user_id' => $sam->id])->assertSessionHasNoErrors();

    expect($rfq->refresh()->isWithDataEntry())->toBeTrue();
});

it('completes it as the assignee when Admin names no one, since that is who does it anyway', function () {
    ['admin' => $admin, 'sam' => $sam, 'rfq' => $rfq] = adminSourcingSplit();

    test()->actingAs($admin)
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 2, 'comment' => 'Done'])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(2)->pivot->completed_at)->not->toBeNull()
        ->and($rfq->comments()->where('body', 'like', '%Done%')->first()->user_id)->toBe($sam->id);
});

it('refuses Admin naming someone the part is not assigned to, changing nothing', function () {
    ['admin' => $admin, 'sam' => $sam, 'rfq' => $rfq] = adminSourcingSplit();
    $outsider = userWithRole('Sourcing');

    foreach ([$sam->id, $outsider->id, 999999] as $wrong) {
        test()->actingAs($admin)
            ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Nope', 'acting_user_id' => $wrong])
            ->assertStatus(422);
    }

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull()
        ->and($rfq->comments()->count())->toBe(0);
});

it('still asks Admin for the comment', function () {
    ['admin' => $admin, 'riley' => $riley, 'rfq' => $rfq] = adminSourcingSplit();

    test()->actingAs($admin)
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'acting_user_id' => $riley->id])
        ->assertSessionHas('error', 'Add a comment to mark this part complete.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull();
});

it('gives Admin nothing to complete where no one holds the part', function () {
    $admin = userWithRole('Admin');
    $rfq = Rfq::factory()->create();

    test()->actingAs($admin)->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Hm'])->assertForbidden();
});

it('keeps it the assignee\'s call for everyone else — a pick does not open it up', function () {
    ['riley' => $riley, 'sam' => $sam, 'rfq' => $rfq] = adminSourcingSplit();

    test()->actingAs($sam)
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Mine now', 'acting_user_id' => $riley->id])
        ->assertForbidden();
    test()->actingAs(userWithRole('Senior Operations'))
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Mine now', 'acting_user_id' => $riley->id])
        ->assertForbidden();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull();
});

it('brings Admin back to the Sourcing page they were on', function (array $fields, array $expected) {
    ['admin' => $admin, 'riley' => $riley, 'rfq' => $rfq] = adminSourcingSplit();

    test()->actingAs($admin)
        ->patch(route('admin.rfqs.complete-sourcing', $rfq), ['part' => 1, 'comment' => 'Done', 'acting_user_id' => $riley->id] + $fields)
        ->assertRedirect(route('admin.rfqs.index', $expected));
})->with([
    'Pending' => [['redirect_status' => 'Pending', 'redirect_role' => 'sourcing'], ['status' => 'Pending', 'role' => 'sourcing']],
    'Returns' => [['redirect_status' => 'Pending', 'redirect_role' => 'sourcing', 'redirect_view' => 'returns'], ['status' => 'Pending', 'role' => 'sourcing', 'view' => 'returns']],
]);

// ---- The button and the prompt ------------------------------------------------

it('offers Admin Mark Complete on every part of Sourcing\'s pending page, naming who holds it', function () {
    ['admin' => $admin, 'riley' => $riley, 'sam' => $sam, 'rfq' => $rfq] = adminSourcingSplit();

    $html = test()->actingAs($admin)
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'sourcing']))
        ->assertOk()
        ->getContent();

    expect(substr_count($html, 'js-complete'))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('data-action="'.route('admin.rfqs.complete-sourcing', $rfq).'"')
        ->toContain('data-actor-role="Sourcing"')
        ->toContain('data-assignee-id="'.$riley->id.'"')
        ->toContain('data-assignee-name="'.e($riley->name).'"')
        ->toContain('data-assignee-id="'.$sam->id.'"')
        ->toContain('data-redirect-role="sourcing"')
        // The shared prompt, with the picker Admin fills from the button.
        ->toContain('id="completeModal"')
        ->toContain('id="complete-assignee-select"');
});

it('offers it on the Returns page for a part sent back, coming back to Returns', function () {
    ['admin' => $admin, 'riley' => $riley, 'rfq' => $rfq] = adminSourcingSplit();
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->returnSourcingPart(1, 'Prices are missing', userWithRole('Data Entry'));

    $html = test()->actingAs($admin)
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'sourcing', 'view' => 'returns']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-assignee-id="'.$riley->id.'"')
        ->toContain('data-redirect-view="returns"')
        ->toContain('data-redirect-role="sourcing"');
});

it('offers no Mark Complete for a part already completed', function () {
    ['admin' => $admin, 'riley' => $riley, 'sam' => $sam, 'rfq' => $rfq] = adminSourcingSplit();
    $rfq->refresh()->completeSourcingPart(1);

    $html = test()->actingAs($admin)
        ->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'sourcing']))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('data-assignee-id="'.$sam->id.'"')
        ->not->toContain('data-assignee-id="'.$riley->id.'"');
});

it('offers Admin a Mark Complete for each open part on the RFQ page', function () {
    ['admin' => $admin, 'riley' => $riley, 'sam' => $sam, 'rfq' => $rfq] = adminSourcingSplit();

    $html = test()->actingAs($admin)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect($html)->toContain('Mark P1 Complete')
        ->toContain('Mark P2 Complete')
        ->toContain('data-assignee-id="'.$riley->id.'"')
        ->toContain('data-assignee-id="'.$sam->id.'"')
        ->toContain('data-return-to="show"')
        ->toContain('id="complete-assignee-select"');

    $rfq->refresh()->completeSourcingPart(1);

    test()->actingAs($admin)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertDontSee('Mark P1 Complete')
        ->assertSee('Mark P2 Complete');
});

it('does not double up Admin\'s button when Admin also holds the part as Sourcing', function () {
    $admin = userWithRole('Admin');
    $admin->assignRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $admin, 2 => userWithRole('Sourcing')]);

    $html = test()->actingAs($admin)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();

    expect(substr_count($html, 'Mark P1 Complete'))->toBe(1)
        ->and(substr_count($html, 'Mark P2 Complete'))->toBe(1);
});

it('shows nobody but Admin the picker, or Mark Complete on parts that are not theirs', function () {
    ['riley' => $riley, 'rfq' => $rfq] = adminSourcingSplit();

    // A Sourcing member: their own part only, and no "Done by" anywhere.
    $html = test()->actingAs($riley)->get(route('admin.rfqs.show', $rfq))->assertOk()->getContent();
    expect($html)->toContain('Mark P1 Complete')
        ->not->toContain('Mark P2 Complete')
        ->not->toContain('complete-assignee')
        ->not->toContain('data-assignee-id="'.$riley->id.'"');

    $list = test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()->getContent();
    expect($list)->toContain('id="completeModal"')->not->toContain('complete-assignee');

    foreach (['Business Development', 'Senior Operations', 'Data Entry'] as $role) {
        test()->actingAs(userWithRole($role))->get(route('admin.rfqs.show', $rfq))->assertOk()
            ->assertDontSee('Mark P1 Complete')
            ->assertDontSee('Mark P2 Complete');
    }
});
