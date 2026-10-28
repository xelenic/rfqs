<?php

use App\Models\JobCategory;
use App\Models\Rfq;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach (Rfq::WORKFLOW_ROLES as $role) {
        Role::findOrCreate($role);
    }

    JobCategory::query()->firstOrCreate(['name' => 'Electrical Works']);
});

/**
 * Business Development saving their fix to $rfq, as from their Returns or
 * Rejected page.
 */
function bdSavesFix(Rfq $rfq, User $bd): void
{
    test()->actingAs($bd)->put(route('admin.rfqs.update', $rfq), [
        'wc_number' => $rfq->wc_number,
        'rfq_number' => $rfq->rfq_number,
        'priority_level' => 'Medium',
        'number_of_items' => 5,
        'status' => 'Pending',
        'subject' => 'Now with the site address',
    ])->assertSessionHasNoErrors();
}

/**
 * The RFQ numbers on Senior Operations' $view page (Unassigned when null).
 *
 * @return array<int, string>
 */
function seniorOpsListed(User $ops, ?string $view = null): array
{
    return test()->actingAs($ops)->get(route('admin.rfqs.index', array_filter(['status' => 'Pending', 'view' => $view])))->assertOk()
        ->viewData('rfqs')->pluck('rfq_number')->all();
}

it('puts an RFQ Business Development fixed on Senior Operations\' Returns, not Unassigned, ready to assign', function () {
    $ops = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2001']);

    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Need the site address']);
    bdSavesFix($rfq, $bd);

    expect($rfq->refresh())
        ->bd_fixed_by->toBe($bd->id)
        ->bd_fixed_at->not->toBeNull()
        ->and(seniorOpsListed($ops))->toBe([])
        ->and(seniorOpsListed($ops, 'returns'))->toBe(['RFQ2001'])
        ->and(seniorOpsListed($ops, 'rejected'))->toBe([])
        ->and(Rfq::queueCounts())->toMatchArray(['unassigned' => 0, 'ops_returns' => 1, 'ops_rejected' => 0]);

    $page = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('id="requestDetailsModal"', false)
        // Their sidebar: one on Returns, none on Unassigned.
        ->assertSee('title="1 sent back to Senior Operations"', false)
        ->assertDontSee('not yet assigned to Sourcing');
    $row = Str::betweenFirst($page->getContent(), '<td class="text-nowrap">RFQ2001</td>', '</tr>');

    expect($row)->toContain('Sent back to Business Development by '.$ops->name.': Need the site address')
        ->toContain('Fixed by Business Development ('.$bd->name.') — ready to assign again.')
        ->toContain('Assignment')
        ->toContain('data-action="'.route('admin.rfqs.assign', $rfq).'"')
        ->toContain('data-action="'.route('admin.rfqs.request-details', $rfq).'"')
        ->not->toContain('Waiting on Business Development')
        // Planned afresh: nobody to pass it straight back to.
        ->not->toContain(route('admin.rfqs.pass-back-sourcing', $rfq));
});

it('brings one a reviewer rejected back to Business Development to Senior Operations\' Returns too, not their Rejected page', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2002', 'stage' => 'senior_ops_review']);

    test()->actingAs($ops)
        ->patch(route('admin.rfqs.reject-senior-ops', $rfq), ['target_stage' => 'business_development', 'reason' => 'Wrong client'])
        ->assertSessionHasNoErrors();
    bdSavesFix($rfq->refresh(), userWithRole('Business Development'));

    expect(seniorOpsListed($ops))->toBe([])
        ->and(seniorOpsListed($ops, 'returns'))->toBe(['RFQ2002'])
        ->and(seniorOpsListed($ops, 'rejected'))->toBe([]);

    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Sent back to Business Development by '.$ops->name.': Wrong client');
});

it('keeps it on Returns till every part is assigned again, then on neither list', function () {
    $ops = userWithRole('Senior Operations');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2003']);

    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Need the site address']);
    bdSavesFix($rfq, userWithRole('Business Development'));

    // Split in two, one part assigned: still theirs to finish.
    test()->actingAs($ops)->patch(route('admin.rfqs.assign', $rfq), [
        'category' => 'Electrical Works', 'split' => 1, 'parts' => 2, 'assignments' => [1 => userWithRole('Sourcing')->id],
    ])->assertSessionHasNoErrors();

    expect(seniorOpsListed($ops, 'returns'))->toBe(['RFQ2003'])
        ->and(seniorOpsListed($ops))->toBe([]);

    test()->actingAs($ops)->patch(route('admin.rfqs.assign', $rfq), ['assignments' => [2 => userWithRole('Sourcing')->id]])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh())
        ->bd_fixed_at->toBeNull()
        ->bd_fixed_by->toBeNull()
        ->and(seniorOpsListed($ops, 'returns'))->toBe([])
        ->and(seniorOpsListed($ops))->toBe([]);
});

it('lets Senior Operations ask for details again from Returns, and has it back there once fixed again', function () {
    $ops = userWithRole('Senior Operations');
    $bd = userWithRole('Business Development');
    $rfq = Rfq::factory()->create(['rfq_number' => 'RFQ2004']);

    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Need the site address']);
    bdSavesFix($rfq, $bd);

    test()->actingAs($ops)->patch(route('admin.rfqs.request-details', $rfq), ['reason' => 'Still no floor number'])
        ->assertSessionHasNoErrors();

    // With Business Development again: on Returns just to follow, not to act on.
    expect(Rfq::seniorOpsReturnsCount())->toBe(0);
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('Waiting on Business Development', false)
        ->assertDontSee('data-fixed-by-business-development', false);

    bdSavesFix($rfq->refresh(), $bd);

    expect($rfq->refresh()->bd_return_count)->toBe(2)
        ->and(Rfq::seniorOpsReturnsCount())->toBe(1)
        ->and(seniorOpsListed($ops))->toBe([]);
    test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee(': Still no floor number', false)
        ->assertSee('data-fixed-by-business-development', false);
});

it('keeps a part of a split Business Development fixed on Returns, to pass straight back to whoever had it', function () {
    $ops = userWithRole('Senior Operations');
    $riley = userWithRole('Sourcing');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ2005']), [1 => userWithRole('Sourcing'), 2 => $riley]);

    $rfq->rejectPartToStage(2, 'business_development', 'Wrong spec for part 2', $ops, 'senior_ops_review');
    bdSavesFix($rfq->refresh(), userWithRole('Business Development'));

    expect(seniorOpsListed($ops))->toBe([])
        ->and(seniorOpsListed($ops, 'returns'))->toBe(['RFQ2005']);

    $row = Str::betweenFirst(test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->getContent(), '<td class="text-nowrap">RFQ2005</td>', '</tr>');
    expect($row)->toContain('Pass back to '.$riley->name);

    test()->actingAs($ops)->patch(route('admin.rfqs.pass-back-sourcing', $rfq))->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(2)?->id)->toBe($riley->id)
        ->and($rfq->bd_fixed_at)->toBeNull()
        ->and(seniorOpsListed($ops, 'returns'))->toBe([]);
});
