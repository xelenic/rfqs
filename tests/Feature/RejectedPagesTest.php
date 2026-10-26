<?php

use App\Models\Rfq;
use App\Models\User;
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
 * One of each role on the way through.
 *
 * @return array<string, User>
 */
function rejectedPagePeople(): array
{
    return [
        'riley' => tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']),
        'sam' => tap(userWithRole('Sourcing'))->update(['name' => 'Sam Rivera']),
        'morgan' => tap(userWithRole('Data Entry'))->update(['name' => 'Morgan Ito']),
        'ops' => userWithRole('Senior Operations'),
        'head' => userWithRole('Head of Business Development'),
        'assistant' => userWithRole('GM Assistant'),
        'gm' => userWithRole('General Manager'),
        'bd' => userWithRole('Business Development'),
    ];
}

/**
 * RFQ3001, split between Riley and Sam and carried through to $until: 'head'
 * (waiting on the Head of Business Development), 'gm' (on the General
 * Manager) or 'closing' (on Business Development to close).
 *
 * @param  array<string, User>  $people
 */
function rfqCarriedTo(array $people, string $until): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3001', 'subject' => 'Lift lobby lighting']), [1 => $people['riley'], 2 => $people['sam']]);

    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        startDataEntryOn($rfq, $part, $people['morgan']);
        $rfq->refresh()->completeDataEntryPart($part, $people['morgan']);
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);

        if ($until !== 'head') {
            $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
            $rfq->refresh()->recordGmAssistantPart($part, $people['assistant']);
        }
        if ($until === 'closing') {
            $rfq->refresh()->approveGmPart($part, $people['gm']);
        }
    }

    return $rfq->refresh();
}

/**
 * $user's list at ?view=$view (null: their own queue), the part numbers on it.
 *
 * @return list<string>
 */
function partsListedFor(User $user, ?string $view): array
{
    $html = test()->actingAs($user)->get(route('admin.rfqs.index', array_filter(['status' => 'Pending', 'view' => $view])))->assertOk()->getContent();
    preg_match_all('/<td class="text-nowrap">(RFQ3001[^<]*)<\/td>/', $html, $matches);

    return array_values(array_unique($matches[1]));
}

// ---- Sourcing -------------------------------------------------------------

it('lists a part a reviewer rejected back to Sourcing on their Rejected page — not Returns — until it\'s done again', function () {
    $people = rejectedPagePeople();
    $rfq = rfqCarriedTo($people, 'head');

    test()->actingAs($people['head'])
        ->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'Get a second quote'])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot)
        ->part_rejected_to->toBe('sourcing')
        ->part_rejected_from->toBe('head_of_bd_review')
        ->part_rejected_by->toBe($people['head']->id)
        ->part_reject_reason->toBe('Get a second quote');

    $page = test()->actingAs($people['riley'])->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']))->assertOk()
        ->assertSee('<title>Rejected', false)
        ->assertSee('Rejected by Head of Business Development: Get a second quote')
        ->assertSee('Rejected At')
        ->assertSee('title="1 rejected back by a reviewer"', false);
    expect(partsListedFor($people['riley'], 'rejected'))->toBe(['RFQ3001-P1 of P2'])
        ->and(partsListedFor($people['riley'], 'returns'))->toBe([])
        ->and(Rfq::sourcingRejectedCount($people['riley']->id))->toBe(1)
        ->and(Rfq::queueCounts())->sourcing_rejected->toBe(1)->sourcing_returns->toBe(0);
    // Doing it again from here comes back here.
    expect($page->getContent())->toContain('data-redirect-view="rejected"');

    $rfq->refresh()->completeSourcingPart(1);

    expect(partsListedFor($people['riley'], 'rejected'))->toBe([])
        ->and(Rfq::sourcingRejectedCount($people['riley']->id))->toBe(0);
});

it('keeps a part Data Entry sent back to Sourcing on Returns, not Rejected', function () {
    $people = rejectedPagePeople();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3001']), [1 => $people['riley']]);
    $rfq->completeSourcingPart(1);

    test()->actingAs($people['morgan'])
        ->patch(route('admin.rfqs.return-sourcing', $rfq), ['part' => 1, 'reason' => 'Missing the supplier quote', 'entries' => 0])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(1)->pivot->part_rejected_to)->toBeNull()
        ->and(partsListedFor($people['riley'], 'returns'))->toBe(['RFQ3001'])
        ->and(partsListedFor($people['riley'], 'rejected'))->toBe([])
        ->and(Rfq::queueCounts())->sourcing_returns->toBe(1)->sourcing_rejected->toBe(0);
});

it('moves a part from Rejected to Returns once Data Entry sends it back after all', function () {
    $people = rejectedPagePeople();
    $rfq = rfqCarriedTo($people, 'head');
    $rfq->rejectPartToStage(1, 'sourcing', 'Get a second quote', $people['head']);

    // Done again, on to Data Entry, who send it back themselves.
    $rfq->refresh()->completeSourcingPart(1);
    $rfq->refresh()->returnSourcingPart(1, 'Quote is unreadable', $people['morgan']);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->part_rejected_to)->toBeNull()
        ->and(partsListedFor($people['riley'], 'returns'))->toBe(['RFQ3001-P1 of P2'])
        ->and(partsListedFor($people['riley'], 'rejected'))->toBe([]);
});

// ---- Data Entry -----------------------------------------------------------

it('lists a part a reviewer rejected back to Data Entry on their Rejected page, off Ready, until it\'s sent to finalize again', function () {
    $people = rejectedPagePeople();
    $rfq = rfqCarriedTo($people, 'gm');

    test()->actingAs($people['gm'])
        ->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 2, 'target_stage' => 'data_entry', 'reason' => 'Totals are off'])
        ->assertSessionHasNoErrors();

    $html = test()->actingAs($people['morgan'])->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']))->assertOk()
        ->assertSee('<title>Rejected', false)
        ->assertSee('Rejected by General Manager: Totals are off')
        ->assertSee('title="1 rejected back by a reviewer"', false)
        ->getContent();
    // The Ready table on its own — no tabs.
    expect($html)->not->toContain('data-de-tab="completed"')
        ->and(partsListedFor($people['morgan'], 'rejected'))->toBe(['RFQ3001-P2 of P2'])
        ->and(Str::betweenFirst(test()->actingAs($people['morgan'])->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), 'id="rfq-de-ready"', 'id="rfq-de-completed"'))
        ->not->toContain('RFQ3001-P2 of P2')
        ->and(Rfq::dataEntryRejectedCount($people['morgan']->id))->toBe(1)
        ->and(Rfq::queueCounts())->data_entry_rejected->toBe(1)->data_entry->toBe(0);

    startDataEntryOn($rfq->refresh(), 2, $people['morgan']);
    $rfq->refresh()->completeDataEntryPart(2, $people['morgan']);

    expect(partsListedFor($people['morgan'], 'rejected'))->toBe([])
        ->and(Rfq::dataEntryRejectedCount($people['morgan']->id))->toBe(0);
});

it('keeps a part Sourcing sends back to Data Entry on their Ready list, not Rejected', function () {
    $people = rejectedPagePeople();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3001']), [1 => $people['riley']]);
    $rfq->completeSourcingPart(1);
    startDataEntryOn($rfq->refresh(), 1, $people['morgan']);
    $rfq->refresh()->completeDataEntryPart(1, $people['morgan']);
    $rfq->refresh()->returnToDataEntry(1, 'Two line items are missing');

    expect(partsListedFor($people['morgan'], 'rejected'))->toBe([])
        ->and(Rfq::dataEntryRejectedCount($people['morgan']->id))->toBe(0)
        ->and(Str::betweenFirst(test()->actingAs($people['morgan'])->get(route('admin.rfqs.index', ['status' => 'Pending']))->getContent(), 'id="rfq-de-ready"', 'id="rfq-de-completed"'))
        ->toContain('Returned by Sourcing: Two line items are missing');
});

// ---- The General Manager ----------------------------------------------------

it('lists a part Business Development rejected back to the General Manager on their Rejected page, off Review, until it\'s decided again', function () {
    $people = rejectedPagePeople();
    $rfq = rfqCarriedTo($people, 'closing');

    test()->actingAs($people['bd'])
        ->patch(route('admin.rfqs.reject-bd', $rfq), ['part' => 1, 'target_stage' => 'gm_review', 'reason' => 'Client asked for a lower price'])
        ->assertSessionHasNoErrors();

    test()->actingAs($people['gm'])->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']))->assertOk()
        ->assertSee('<title>Rejected', false)
        ->assertSee('Rejected by Business Development (closing): Client asked for a lower price')
        ->assertSee('data-action="'.route('admin.rfqs.reject-gm', $rfq).'"', false)
        ->assertSee('title="1 rejected back by Business Development"', false);
    expect(partsListedFor($people['gm'], 'rejected'))->toBe(['RFQ3001-P1 of P2'])
        ->and(partsListedFor($people['gm'], null))->toBe([])
        ->and(Rfq::gmRejectedCount())->toBe(1)
        ->and(Rfq::gmReviewCount())->toBe(0);

    test()->actingAs($people['gm'])->patch(route('admin.rfqs.approve-gm-part', $rfq), ['part' => 1])->assertSessionHasNoErrors();

    expect(partsListedFor($people['gm'], 'rejected'))->toBe([])
        ->and(Rfq::gmRejectedCount())->toBe(0);
});

it('keeps the other parts waiting on the General Manager on their Review', function () {
    $people = rejectedPagePeople();
    $rfq = rfqCarriedTo($people, 'gm');
    $rfq->approveGmPart(1, $people['gm']);
    $rfq->refresh()->rejectPartToStage(1, 'gm_review', 'Client asked for a lower price', $people['bd'], 'bd_closing');

    expect(partsListedFor($people['gm'], 'rejected'))->toBe(['RFQ3001-P1 of P2'])
        ->and(partsListedFor($people['gm'], null))->toBe(['RFQ3001-P2 of P2'])
        ->and(Rfq::gmRejectedCount())->toBe(1)
        ->and(Rfq::gmReviewCount())->toBe(1);
});

// ---- Every role -----------------------------------------------------------------

it('gives every role sent work back a Rejected link on its sidebar, and Admin one under each', function () {
    $people = rejectedPagePeople();
    $rejected = e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected']));

    foreach (['riley', 'morgan', 'ops', 'head', 'assistant', 'gm', 'bd'] as $who) {
        test()->actingAs($people[$who])->get(route('admin.dashboard'))->assertOk()
            ->assertSee('href="'.$rejected.'"', false);
    }

    $html = test()->actingAs(userWithRole('Admin'))->get(route('admin.dashboard'))->assertOk()->getContent();
    foreach (['business-development', 'senior-operations', 'sourcing', 'data-entry', 'head-of-business-development', 'gm-assistant', 'general-manager'] as $role) {
        expect($html)->toContain(e(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'rejected', 'role' => $role])));
    }
});

it('records the rejection only for a reviewer\'s Reject & Return — not a part an Admin sends back to Senior Operations', function () {
    $people = rejectedPagePeople();
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ3001']), [1 => $people['riley'], 2 => $people['sam']]);

    test()->actingAs(userWithRole('Admin'))
        ->patch(route('admin.rfqs.return-senior-ops', $rfq), ['part' => 1, 'reason' => 'Wrong supplier group'])
        ->assertSessionHasNoErrors();

    expect($rfq->refresh()->assigneeForPart(2)->pivot->part_rejected_to)->toBeNull()
        ->and(Rfq::queueCounts())->ops_returns->toBe(1)->ops_rejected->toBe(0);
});

it('fills in the rejections already waiting when the columns are added', function () {
    $people = rejectedPagePeople();
    $rfq = rfqCarriedTo($people, 'gm');
    $rfq->rejectPartToStage(1, 'sourcing', 'Get a second quote', $people['gm'], 'gm_review');
    $rfq->refresh()->rejectPartToStage(2, 'data_entry', 'Totals are off', $people['gm'], 'gm_review');
    $migration = require database_path('migrations/2026_10_26_194126_add_part_rejection_to_rfq_user_table.php');

    // As it was before: no record on the parts.
    $migration->down();
    $migration->up();

    $rfq->refresh();
    expect($rfq->assigneeForPart(1)->pivot)
        ->part_rejected_to->toBe('sourcing')
        ->part_rejected_from->toBe('gm_review')
        ->part_rejected_by->toBe($people['gm']->id)
        ->part_reject_reason->toBe('Get a second quote')
        ->and($rfq->assigneeForPart(2)->pivot)
        ->part_rejected_to->toBe('data_entry')
        ->part_reject_reason->toBe('Totals are off');
});
