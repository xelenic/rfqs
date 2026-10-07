<?php

use App\Models\Rfq;
use App\Models\RfqStep;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }
});

it('offers Reassign on the Assigned tab for a part still with Sourcing, and not once it\'s gone on to Data Entry', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ18001']), [1 => $riley, 2 => $sam]);
    $rfq->refresh()->completeSourcingPart(2);

    [, $assignedTab] = operationsTabs(['tab' => 'assigned']);

    $partLine = fn (string $number) => Str::betweenFirst($assignedTab, '<span class="rfq-part-name">'.$number.'</span>', '</tr>');

    expect($partLine('RFQ18001-P1 of P2'))->toContain('js-reassign-part')
        ->toContain('data-current-id="'.$riley->id.'"')
        ->and($partLine('RFQ18001-P2 of P2'))->not->toContain('js-reassign-part')
        ->and($assignedTab)->toContain('id="reassignModal"')
        ->toContain(e($sam->name).' · ');
});

it('gives a part still with Sourcing to another member — off the first one\'s list, onto the other\'s, afresh', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ18002']), [1 => $riley, 2 => userWithRole('Sourcing')]);

    test()->actingAs($ops)->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => $sam->id])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', "Reassigned RFQ18002-P1 of P2 from {$riley->name} to {$sam->name}.");

    expect($rfq->refresh()->assigneeForPart(1)?->id)->toBe($sam->id)
        ->and($rfq->comments->last())->toMatchArray(['action' => 'reassigned', 'body' => "From {$riley->name} to {$sam->name}.", 'user_id' => $ops->id])
        ->and($rfq->comments->last()->meta)->toMatchArray(['part' => 1])
        // Riley's round on it is over; Sam's has begun.
        ->and(RfqStep::query()->where('part_number', 1)->whereNull('ended_at')->sole()->assignee_id)->toBe($sam->id)
        ->and(RfqStep::query()->where('part_number', 1)->where('assignee_id', $riley->id)->sole()->ended_at)->not->toBeNull();

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertDontSee('<td class="text-nowrap">RFQ18002-P1 of P2</td>', false);
    test()->actingAs($sam)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('<td class="text-nowrap">RFQ18002-P1 of P2</td>', false);
});

it('can reassign a part Data Entry sent back to Sourcing for rework', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->returnSourcingPart(1, 'Wrong supplier', userWithRole('Data Entry'));

    expect($rfq->refresh()->canReassignPart(1))->toBeTrue();

    test()->actingAs(userWithRole('Senior Operations'))->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => $sam->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)?->id)->toBe($sam->id);
});

it('refuses to reassign a part that\'s gone on to Data Entry, or to whoever has it, or to anyone outside Sourcing', function () {
    $ops = userWithRole('Senior Operations');
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley, 2 => userWithRole('Sourcing')]);

    test()->actingAs($ops)->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => $riley->id])
        ->assertSessionHasErrorsIn('reassign', ['user_id' => 'They already have it — pick someone else.']);
    test()->actingAs($ops)->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => userWithRole('Data Entry')->id])
        ->assertSessionHasErrorsIn('reassign', ['user_id' => 'Pick someone from Sourcing.']);
    test()->actingAs($ops)->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1])
        ->assertSessionHasErrorsIn('reassign', ['user_id' => 'Pick who takes it over.']);

    $rfq->refresh()->completeSourcingPart(1);
    test()->actingAs($ops)->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => $sam->id])
        ->assertStatus(422);

    expect($rfq->refresh()->assigneeForPart(1)?->id)->toBe($riley->id);
});

it('lets only Senior Operations and Admin reassign', function () {
    [$riley, $sam] = [userWithRole('Sourcing'), userWithRole('Sourcing')];
    $rfq = splitAmong(Rfq::factory()->create(), [1 => $riley]);

    foreach (['Sourcing', 'Business Development', 'Data Entry'] as $role) {
        test()->actingAs(userWithRole($role))->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => $sam->id])
            ->assertForbidden();
    }

    $ops = userWithRole('Senior Operations');
    test()->actingAs(userWithRole('Admin'))->patch(route('admin.rfqs.reassign-sourcing', $rfq), ['part' => 1, 'user_id' => $sam->id, 'acting_user_id' => $ops->id])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)?->id)->toBe($sam->id)
        ->and($rfq->comments->last()->user_id)->toBe($ops->id);
});

it('reopens the popup against the same part when no one\'s picked', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = splitAmong(Rfq::factory()->create(), [1 => userWithRole('Sourcing')]);
    $assignedUrl = route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'assigned']);

    test()->actingAs($ops)->from($assignedUrl)->patch(route('admin.rfqs.reassign-sourcing', $rfq), [
        'part' => 1, 'reassign_rfq_id' => $rfq->id, 'reassign_target' => 'RFQ — with Riley',
    ])->assertRedirect($assignedUrl);

    $html = test()->actingAs($ops)->get($assignedUrl)->assertOk()
        ->assertSee('Pick who takes it over.')
        ->assertSee('getElementById(\'reassignModal\')).show()', false)
        ->getContent();

    expect($html)->toMatch('/id="reassignForm"[^>]*action="'.preg_quote(route('admin.rfqs.reassign-sourcing', $rfq), '/').'"/');
});
