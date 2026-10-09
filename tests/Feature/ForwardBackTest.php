<?php

use App\Models\Rfq;
use App\Models\RfqComment;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * Everyone it takes, Sourcing through the General Manager.
 *
 * @return array{sourcing: User, sourcing2: User, dataEntry: User, ops: User, head: User, assistant: User, gm: User, bd: User}
 */
function forwardPeople(): array
{
    return [
        'sourcing' => userWithRole('Sourcing'),
        'sourcing2' => userWithRole('Sourcing'),
        'dataEntry' => userWithRole('Data Entry'),
        'ops' => userWithRole('Senior Operations'),
        'head' => userWithRole('Head of Business Development'),
        'assistant' => userWithRole('GM Assistant'),
        'gm' => userWithRole('General Manager'),
        'bd' => userWithRole('Business Development'),
    ];
}

/**
 * RFQ1001, split two ways and carried through to $until: 'head' — waiting
 * on the Head of Business Development — or 'gm', waiting on the General
 * Manager.
 *
 * @param  array<string, User>  $people
 */
function carriedTo(array $people, string $until): Rfq
{
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ1001', 'subject' => 'Replace exit signs']), [1 => $people['sourcing'], 2 => $people['sourcing2']]);

    foreach ([1, 2] as $part) {
        $rfq->refresh()->completeSourcingPart($part);
        startDataEntryOn($rfq, $part, $people['dataEntry']);
        $rfq->refresh()->completeDataEntryPart($part, $people['dataEntry']);
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, $people['ops']);

        if ($until === 'gm') {
            $rfq->refresh()->approveHeadOfBdPart($part, $people['head']);
            $rfq->refresh()->recordGmAssistantPart($part, $people['assistant']);
        }
    }

    return $rfq->refresh();
}

/**
 * $user's Returns page (or, with no view, their Pending page).
 */
function returnsPage(User $user, ?string $view = 'returns', array $query = []): string
{
    return test()->actingAs($user)->get(route('admin.rfqs.index', array_filter(['status' => 'Pending', 'view' => $view] + $query)))->assertOk()->getContent();
}

/**
 * Sends it straight back.
 *
 * @param  array<string, mixed>  $data
 */
function sendStraightBack(User $user, Rfq $rfq, array $data): TestResponse
{
    return test()->actingAs($user)->from(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))
        ->patch(route('admin.rfqs.forward-back', $rfq), $data);
}

it('lets Sourcing send a part the Head sent back straight back to the Head, skipping the steps between', function () {
    $people = forwardPeople();
    $rfq = carriedTo($people, 'head');
    $before = $rfq->assigneeForPart(1)->pivot;

    test()->actingAs($people['head'])->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'Wrong supplier'])
        ->assertSessionHasNoErrors();
    expect($rfq->refresh()->assigneeForPart(1)->pivot->completed_at)->toBeNull();

    // On their Returns page, beside Assign to Data Entry.
    $row = Str::betweenFirst(returnsPage($people['sourcing']), '<td class="text-nowrap">RFQ1001-P1 of P2</td>', '</tr>');
    expect($row)->toContain('action="'.route('admin.rfqs.forward-back', $rfq).'"')
        ->toContain('<i class="bi bi-skip-forward-fill"></i> Send back to Head of Business Development')
        ->toContain('It skips Data Entry, Sourcing (finalize) and Senior Operations (2nd review) — the work done there before stands.');

    sendStraightBack($people['sourcing'], $rfq, ['part' => 1, 'target' => 'sourcing', 'from' => 'head_of_bd_review'])
        ->assertSessionHas('status', 'Sent RFQ1001-P1 of P2 straight back to Head of Business Development.');

    // Just as it was before the Head sent it back — waiting on the Head again.
    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->completed_at->eq($before->completed_at))->toBeTrue()
        ->and($part->data_entry_completed_at->eq($before->data_entry_completed_at))->toBeTrue()
        ->and($part->finalized_at->eq($before->finalized_at))->toBeTrue()
        ->and($part->senior_ops_reviewed_by)->toBe($people['ops']->id)
        ->and($part->returned_at)->toBeNull()
        ->and($part->head_of_bd_approved_at)->toBeNull()
        ->and($rfq->stage)->toBe('head_of_bd_review')
        ->and($rfq->partAwaitsHeadOfBdReview(1))->toBeTrue()
        ->and($rfq->comments->last())
        ->action->toBe('forwarded_back')
        ->body->toBe('Sent straight back to Head of Business Development — skipping Data Entry, Sourcing (finalize) and Senior Operations (2nd review).');

    expect(returnsPage($people['head'], null))->toContain('RFQ1001-P1 of P2');

    // Only the once.
    sendStraightBack($people['sourcing'], $rfq, ['part' => 1, 'target' => 'sourcing', 'from' => 'head_of_bd_review'])->assertStatus(422);
});

it('lets Data Entry send a part the General Manager sent back straight back to them', function () {
    $people = forwardPeople();
    $rfq = carriedTo($people, 'gm');

    test()->actingAs($people['gm'])->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 2, 'target_stage' => 'data_entry', 'reason' => 'Totals are off'])
        ->assertSessionHasNoErrors();

    $row = Str::betweenFirst(returnsPage($people['dataEntry'], null), '<td class="text-nowrap">RFQ1001-P2 of P2</td>', '</tr>');
    expect($row)->toContain('Send back to General Manager')
        ->toContain('It skips Sourcing (finalize), Senior Operations (2nd review), Head of Business Development and GM Assistant');

    sendStraightBack($people['dataEntry'], $rfq, ['part' => 2, 'target' => 'data_entry', 'from' => 'gm_review'])
        ->assertSessionHas('status', 'Sent RFQ1001-P2 of P2 straight back to General Manager.');

    expect($rfq->refresh()->partAwaitsGmApproval(2))->toBeTrue()
        ->and($rfq->assigneeForPart(2)->pivot->gm_assistant_completed_by)->toBe($people['assistant']->id);
});

it('isn\'t offered when nothing would be skipped, or once the part has moved on', function () {
    $people = forwardPeople();
    $rfq = carriedTo($people, 'head');

    // Back to Senior Operations' review: their Approve goes straight to the Head anyway.
    test()->actingAs($people['head'])->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 2, 'target_stage' => 'senior_ops_review', 'reason' => 'Check again']);
    expect($rfq->refresh()->openReturns())->toBeEmpty()
        ->and(returnsPage($people['ops']))->not->toContain('Send back to');

    // Back to Sourcing, but done again the usual way.
    test()->actingAs($people['head'])->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'Wrong supplier']);
    expect($rfq->refresh()->openReturnFor(1))->not->toBeNull();

    $rfq->completeSourcingPart(1);

    expect($rfq->refresh()->openReturnFor(1))->toBeNull();
    sendStraightBack($people['sourcing'], $rfq, ['part' => 1, 'target' => 'sourcing', 'from' => 'head_of_bd_review'])->assertStatus(422);
});

it('lets only whoever it was sent back to send it — or Admin, as them', function () {
    $people = forwardPeople();
    $rfq = carriedTo($people, 'head');
    test()->actingAs($people['head'])->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'Wrong supplier']);
    $data = ['part' => 1, 'target' => 'sourcing', 'from' => 'head_of_bd_review'];

    sendStraightBack($people['dataEntry'], $rfq, $data)->assertForbidden();
    // Not the other part's Sourcing member.
    sendStraightBack($people['sourcing2'], $rfq, $data)->assertForbidden();

    sendStraightBack(userWithRole('Admin'), $rfq, $data + ['acting_user_id' => $people['sourcing']->id])->assertRedirect();

    expect($rfq->refresh()->returns()->sole()->forwarded_by)->toBe($people['sourcing']->id)
        ->and($rfq->comments->last()->user_id)->toBe($people['sourcing']->id);
});

it('puts a whole RFQ sent back to the assignment straight back, with the same people', function () {
    $people = forwardPeople();
    $rfq = carriedTo($people, 'head');

    test()->actingAs($people['head'])->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['target_stage' => 'operations', 'reason' => 'Split it differently'])
        ->assertSessionHasNoErrors();
    expect($rfq->refresh()->assignees)->toHaveCount(0);

    $row = Str::betweenFirst(returnsPage($people['ops']), '<td class="text-nowrap">RFQ1001</td>', '</tr>');
    expect($row)->toContain('Send back to Head of Business Development')
        ->toContain('It skips Sourcing, Data Entry, Sourcing (finalize) and Senior Operations (2nd review)');

    sendStraightBack($people['ops'], $rfq, ['target' => 'operations', 'from' => 'head_of_bd_review'])
        ->assertSessionHas('status', 'Sent RFQ1001 straight back to Head of Business Development.');

    $rfq->refresh();
    expect($rfq->assigneeForPart(1)->id)->toBe($people['sourcing']->id)
        ->and($rfq->assigneeForPart(2)->id)->toBe($people['sourcing2']->id)
        ->and($rfq->split_count)->toBe(2)
        ->and($rfq->stage)->toBe('head_of_bd_review')
        ->and($rfq->reject_target_stage)->toBeNull()
        ->and($rfq->partAwaitsHeadOfBdReview(1) && $rfq->partAwaitsHeadOfBdReview(2))->toBeTrue();
});

it('lets Business Development fix what was sent back to them and send it straight back, or just send it', function () {
    $people = forwardPeople();
    $rfq = carriedTo($people, 'gm');
    test()->actingAs($people['gm'])->patch(route('admin.rfqs.reject-gm', $rfq), ['target_stage' => 'business_development', 'reason' => 'Wrong client name'])
        ->assertSessionHasNoErrors();

    $row = Str::betweenFirst(returnsPage($people['bd']), '<td class="text-nowrap">RFQ1001</td>', '</tr>');
    expect($row)->toContain('Send back to General Manager')
        ->toContain('data-forward-back="General Manager"');

    test()->actingAs($people['bd'])->put(route('admin.rfqs.update', $rfq), [
        'wc_number' => $rfq->wc_number,
        'rfq_number' => $rfq->rfq_number,
        'priority_level' => $rfq->priority_level,
        'number_of_items' => 3,
        'status' => 'Pending',
        'subject' => 'Replace exit signs — Acme Holdings',
        'forward_back' => '1',
    ])->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'RFQ updated — and sent straight back to General Manager.');

    $rfq->refresh();
    expect($rfq->subject)->toBe('Replace exit signs — Acme Holdings')
        ->and($rfq->stage)->toBe('gm_review')
        ->and($rfq->assignees)->toHaveCount(2)
        ->and($rfq->partAwaitsGmApproval(1))->toBeTrue()
        ->and($rfq->isReturnedToBusinessDevelopment())->toBeFalse();
});

it('follows a chain back: Sourcing to the Head, who has it from the General Manager, and on to them', function () {
    $people = forwardPeople();
    $rfq = carriedTo($people, 'gm');

    test()->actingAs($people['gm'])->patch(route('admin.rfqs.reject-gm', $rfq), ['part' => 1, 'target_stage' => 'head_of_bd_review', 'reason' => 'Margins']);
    test()->actingAs($people['head'])->patch(route('admin.rfqs.reject-head-of-bd', $rfq), ['part' => 1, 'target_stage' => 'sourcing', 'reason' => 'Cheaper supplier']);

    sendStraightBack($people['sourcing'], $rfq, ['part' => 1, 'target' => 'sourcing', 'from' => 'head_of_bd_review'])->assertRedirect();

    // Back on the Head's Returns page, from the General Manager — who it can go straight back to.
    expect($rfq->refresh()->reject_target_stage)->toBe('head_of_bd_review')
        ->and(Str::betweenFirst(returnsPage($people['head']), '<td class="text-nowrap">RFQ1001-P1 of P2</td>', '</tr>'))
        ->toContain('Send back to General Manager');

    sendStraightBack($people['head'], $rfq, ['part' => 1, 'target' => 'head_of_bd_review', 'from' => 'gm_review'])->assertRedirect();

    expect($rfq->refresh()->partAwaitsGmApproval(1))->toBeTrue()
        ->and($rfq->reject_target_stage)->toBeNull()
        ->and(RfqComment::query()->where('action', 'forwarded_back')->count())->toBe(2);
});

it('lists the skipped steps in workflow order', function () {
    expect(Rfq::stagesBetween('sourcing', 'head_of_bd_review'))->toBe(['data_entry', 'finalize', 'senior_ops_review'])
        ->and(Rfq::stagesBetween('gm_assistant', 'gm_review'))->toBe([])
        ->and(Rfq::stagesBetween('head_of_bd_review', 'bd_closing'))->toBe(['gm_assistant', 'gm_review']);
});
