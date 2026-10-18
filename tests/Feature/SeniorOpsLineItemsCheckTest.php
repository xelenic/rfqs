<?php

use App\Models\Rfq;
use App\Models\RfqAssignment;
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
 * RFQ8401 ("Valves") with its parts — given to Data Entry with $toAdd line
 * items to add, Data Entry adding $added, then finalized — waiting on Senior
 * Operations' review; split in two when $split.
 *
 * @return array{rfq: Rfq, ops: User}
 */
function finalizedForCheck(?int $toAdd = 24, ?int $added = 22, bool $split = false): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $dataEntry = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8401', 'subject' => 'Valves']), $split ? [1 => $riley, 2 => $riley] : [1 => $riley]);

    foreach ($split ? [1, 2] : [1] as $part) {
        $rfq->refresh()->completeSourcingPart($part, null, null, $toAdd);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry, 'Entered', $added === null ? null : $added + $part - 1);
        $rfq->refresh()->finalizePart($part);
    }

    return ['rfq' => $rfq->refresh(), 'ops' => userWithRole('Senior Operations')];
}

it('opens a check of the line items from Approve, on the review list', function () {
    ['rfq' => $rfq, 'ops' => $ops] = finalizedForCheck(24, 22);

    $html = test()->actingAs($ops)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSee('id="seniorOpsApproveModal"', false)
        ->assertSeeInOrder(['Line Items to Add', 'Line Items Added', 'Difference', 'Actual Line Items'])
        ->getContent();

    $button = Str::betweenFirst($html, 'js-senior-ops-approve"', '</button>');
    expect($button)
        ->toContain('data-action="'.route('admin.rfqs.approve-senior-ops-part', $rfq).'"')
        ->toContain('data-part="1"')
        ->toContain(e(json_encode([['part' => 1, 'label' => 'RFQ8401', 'who' => 'Riley Chen', 'to_add' => 24, 'added' => 22, 'difference' => -2]])));

    // Only Actual Line Items is for them to fill in; the rest are read only, and not sent.
    $part = Str::between($html, '<template id="seniorOpsApprovePart">', '</template>');
    expect(substr_count($part, ' readonly tabindex="-1"'))->toBe(3)
        ->and(substr_count($part, 'name='))->toBe(0)
        ->and($part)->toContain('type="number" class="form-control js-actual"');
});

it('keeps the actual line items Senior Operations verified with the part, and moves it on', function () {
    ['rfq' => $rfq, 'ops' => $ops] = finalizedForCheck(24, 22);

    test()->actingAs($ops)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'actual_line_items' => [1 => 23]])
        ->assertSessionHas('status', 'Approved — every part is through, escalated to Head of Business Development.');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->verified_line_items)->toBe(23)
        ->and($part->verifiedLineItems())->toBe(23)
        ->and($rfq->stage)->toBe('head_of_bd_review');

    // The Head of Business Development sees what was verified, and so does the RFQ's page.
    test()->actingAs(userWithRole('Head of Business Development'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('23 line items verified');
    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSeeInOrder(['Actual line items', '23']);
});

it('refuses an approval without a count of the actual line items, approving nothing', function (array $payload, string $message) {
    ['rfq' => $rfq, 'ops' => $ops] = finalizedForCheck();

    test()->actingAs($ops)->from(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))
        ->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1] + $payload)
        ->assertSessionHas('error', $message);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->senior_ops_reviewed_at)->toBeNull();
})->with([
    'none' => [[], 'Enter the actual line items you verified on RFQ8401.'],
    'empty' => [['actual_line_items' => [1 => '']], 'Enter the actual line items you verified on RFQ8401.'],
    'a word' => [['actual_line_items' => [1 => 'many']], 'Give the actual line items as a whole number, like 12.'],
    'part of one' => [['actual_line_items' => [1 => '2.5']], 'Give the actual line items as a whole number, like 12.'],
    'fewer than none' => [['actual_line_items' => [1 => -1]], 'The actual line items can\'t be less than 0.'],
    'too many' => [['actual_line_items' => [1 => 100001]], 'That\'s more line items than one part can have — at most 100,000.'],
]);

it('takes 0 as a count', function () {
    ['rfq' => $rfq, 'ops' => $ops] = finalizedForCheck();

    test()->actingAs($ops)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'actual_line_items' => [1 => '0']])
        ->assertSessionHas('status');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->verifiedLineItems())->toBe(0);
});

it('asks for every part\'s count when approving the whole RFQ, from its page', function () {
    ['rfq' => $rfq, 'ops' => $ops] = finalizedForCheck(10, 10, split: true);

    $html = test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('id="seniorOpsApproveModal"', false)
        ->getContent();
    expect(Str::betweenFirst($html, 'js-senior-ops-approve"', '</button>'))
        ->toContain('data-action="'.route('admin.rfqs.complete-senior-ops-review', $rfq).'"')
        ->toContain(e(json_encode([
            ['part' => 1, 'label' => 'RFQ8401-P1 of P2', 'who' => 'Riley Chen', 'to_add' => 10, 'added' => 10, 'difference' => 0],
            ['part' => 2, 'label' => 'RFQ8401-P2 of P2', 'who' => 'Riley Chen', 'to_add' => 10, 'added' => 11, 'difference' => 1],
        ])));

    test()->actingAs($ops)->patch(route('admin.rfqs.complete-senior-ops-review', $rfq), ['actual_line_items' => [1 => 10]])
        ->assertSessionHas('error', 'Enter the actual line items you verified on RFQ8401-P2 of P2.');
    expect($rfq->refresh()->stage)->toBe('senior_ops_review');

    test()->actingAs($ops)->patch(route('admin.rfqs.complete-senior-ops-review', $rfq), ['actual_line_items' => [1 => 10, 2 => 11]])
        ->assertSessionHas('status', 'Approved — escalated to Head of Business Development.');

    $rfq->refresh();
    expect($rfq->stage)->toBe('head_of_bd_review')
        ->and($rfq->assigneeForPart(1)->pivot->verifiedLineItems())->toBe(10)
        ->and($rfq->assigneeForPart(2)->pivot->verifiedLineItems())->toBe(11);

    test()->actingAs($ops)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('10 line items verified')
        ->assertSee('11 line items verified');
});

it('lets Admin verify on Senior Operations\' behalf', function () {
    ['rfq' => $rfq, 'ops' => $ops] = finalizedForCheck();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review', 'role' => 'senior-operations']))->assertOk()
        ->assertSee('id="senior-ops-approve-acting-as"', false);

    test()->actingAs($admin)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'actual_line_items' => [1 => 22], 'acting_user_id' => $ops->id])
        ->assertSessionHas('status');

    expect($rfq->refresh()->assigneeForPart(1)->pivot)->toMatchArray(['senior_ops_reviewed_by' => $ops->id, 'verified_line_items' => 22]);
});

it('stops showing the count once the part is sent back past the review', function () {
    ['rfq' => $rfq, 'ops' => $ops] = finalizedForCheck();
    test()->actingAs($ops)->patch(route('admin.rfqs.approve-senior-ops-part', $rfq), ['part' => 1, 'actual_line_items' => [1 => 22]]);

    $rfq->refresh()->rejectPartToStage(1, 'sourcing', 'Wrong supplier', userWithRole('Head of Business Development'));

    expect($rfq->refresh()->assigneeForPart(1)->pivot->verifiedLineItems())->toBeNull();
});

it('works out the difference, or leaves it out while a count is missing', function (?int $toAdd, ?int $added, bool $sentToFinalize, array $check) {
    $part = new RfqAssignment([
        'line_items_to_add' => $toAdd,
        'data_entry_entries' => $added,
        'data_entry_completed_at' => $sentToFinalize ? now() : null,
    ]);

    expect($part->lineItemsCheck())->toBe($check);
})->with([
    'fewer' => [24, 22, true, ['to_add' => 24, 'added' => 22, 'difference' => -2]],
    'more' => [24, 27, true, ['to_add' => 24, 'added' => 27, 'difference' => 3]],
    'the same' => [24, 24, true, ['to_add' => 24, 'added' => 24, 'difference' => 0]],
    'no line items given' => [null, 22, true, ['to_add' => null, 'added' => 22, 'difference' => null]],
    'not sent to finalize' => [24, 22, false, ['to_add' => 24, 'added' => null, 'difference' => null]],
]);
