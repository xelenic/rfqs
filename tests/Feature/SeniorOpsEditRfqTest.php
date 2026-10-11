<?php

use App\Models\Rfq;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * What the Edit form sends for $rfq, with $changes.
 *
 * @param  array<string, mixed>  $changes
 * @return array<string, mixed>
 */
function editPayload(Rfq $rfq, array $changes = []): array
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

it('lets Senior Operations and Business Development edit an RFQ\'s details, from its page and their lists', function (string $role) {
    $ops = userWithRole($role);
    $rfq = Rfq::factory()->create(['subject' => 'Replace exit signs', 'priority_level' => 'Low']);

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('data-action="'.route('admin.rfqs.update', $rfq).'"', false)
        // The details — not the status, which they stop and resume from the RFQ's page.
        ->assertDontSee('id="edit-status"', false);

    // Their Pending list too.
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-action="'.route('admin.rfqs.update', $rfq).'"', false);

    test()->actingAs($ops)->put(route('admin.rfqs.update', $rfq), editPayload($rfq, ['subject' => 'Replace exit signs — all floors', 'priority_level' => 'Urgent']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'RFQ updated successfully.');

    expect($rfq->refresh())
        ->subject->toBe('Replace exit signs — all floors')
        ->priority_level->toBe('Urgent')
        ->status->toBe('Pending');
})->with(['Senior Operations', 'Business Development']);

it('never lets Senior Operations or Business Development close an RFQ through Edit, or change a stopped one\'s status', function (string $role) {
    $ops = userWithRole($role);
    $open = Rfq::factory()->create();
    $held = Rfq::factory()->create(['status' => Rfq::ON_HOLD]);

    test()->actingAs($ops)->put(route('admin.rfqs.update', $open), editPayload($open, ['status' => 'Completed']))->assertSessionHasNoErrors();
    test()->actingAs($ops)->put(route('admin.rfqs.update', $held), editPayload($held, ['subject' => 'Paused for now']))->assertSessionHasNoErrors();

    expect($open->refresh()->status)->toBe('Pending')
        ->and($held->refresh())->status->toBe(Rfq::ON_HOLD)->subject->toBe('Paused for now');
})->with(['Senior Operations', 'Business Development']);

it('keeps a closed RFQ out of Senior Operations\' and Business Development\'s reach — no Edit, and refused', function (string $role) {
    $ops = userWithRole($role);
    $closed = Rfq::factory()->create(['status' => 'Completed', 'subject' => 'Done and dusted']);

    expect(test()->actingAs($ops)->get(route('admin.rfqs.show', $closed))->assertOk()->getContent())
        ->not->toContain('data-action="'.route('admin.rfqs.update', $closed).'"');

    $closedList = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Completed']))->assertOk()->getContent();
    expect(Str::contains($closedList, 'data-action="'.route('admin.rfqs.update', $closed).'"'))->toBeFalse();

    test()->actingAs($ops)->put(route('admin.rfqs.update', $closed), editPayload($closed, ['subject' => 'Changed after closing', 'status' => 'Completed']))
        ->assertForbidden();
    expect($closed->refresh()->subject)->toBe('Done and dusted');

    // An Admin still can.
    test()->actingAs(userWithRole('Admin'))->put(route('admin.rfqs.update', $closed), editPayload($closed, ['subject' => 'Corrected', 'status' => 'Completed']))
        ->assertSessionHasNoErrors();
    expect($closed->refresh()->subject)->toBe('Corrected');
})->with(['Senior Operations', 'Business Development']);

it('keeps editing from everyone else', function (string $role) {
    $rfq = Rfq::factory()->create(['subject' => 'Untouched']);

    expect(test()->actingAs(userWithRole($role))->get(route('admin.rfqs.show', $rfq))->getContent())
        ->not->toContain('data-action="'.route('admin.rfqs.update', $rfq).'"');

    test()->actingAs(userWithRole($role))->put(route('admin.rfqs.update', $rfq), editPayload($rfq, ['subject' => 'Changed']))->assertForbidden();
    expect($rfq->refresh()->subject)->toBe('Untouched');
})->with(['Sourcing', 'Data Entry', 'Head of Business Development', 'GM Assistant', 'General Manager']);
