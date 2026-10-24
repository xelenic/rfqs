<?php

use App\Models\Rfq;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * RFQ8801 ("Valves", High — an hour for GM Assistant by priority), its parts
 * through to the Head of Business Development: 24 line items to add, 22
 * added, 23 verified by Senior Operations. Split in two when $split.
 *
 * @return array{rfq: Rfq, head: User}
 */
function withTheHead(bool $split = false): array
{
    $riley = tap(userWithRole('Sourcing'))->update(['name' => 'Riley Chen']);
    $dataEntry = userWithRole('Data Entry');
    $ops = userWithRole('Senior Operations');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8801', 'subject' => 'Valves', 'priority_level' => 'High']), $split ? [1 => $riley, 2 => $riley] : [1 => $riley]);

    foreach ($split ? [1, 2] : [1] as $part) {
        $rfq->refresh()->completeSourcingPart($part, null, null, 24);
        $rfq->refresh()->completeDataEntryPart($part, $dataEntry, 'Entered', 22);
        $rfq->refresh()->finalizePart($part);
        $rfq->refresh()->approveSeniorOpsPart($part, $ops, 23);
    }

    return ['rfq' => $rfq->refresh(), 'head' => userWithRole('Head of Business Development')];
}

it('asks for the finalized line items when the Head approves — with the counts so far, read only', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();

    $html = test()->actingAs($head)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('id="headOfBdApproveModal" data-field="finalized_line_items"', false)
        ->assertSeeInOrder(['Line Items to Add', 'Line Items Added', 'Actual Line Items', 'Finalized Line Items'])
        ->getContent();

    $button = Str::betweenFirst($html, 'js-head-of-bd-approve"', '</button>');
    expect($button)
        ->toContain('data-action="'.route('admin.rfqs.approve-head-of-bd-part', $rfq).'"')
        ->and(popupParts($button))->toBe([['part' => 1, 'label' => 'RFQ8801', 'who' => 'Riley Chen', 'verified' => 23, 'to_add' => 24, 'added' => 22, 'difference' => -2]]);

    $part = Str::between($html, '<template id="headOfBdApprovePart">', '</template>');
    expect(substr_count($part, ' readonly tabindex="-1"'))->toBe(3)
        ->and(substr_count($part, 'name='))->toBe(0)
        ->and($part)->toContain('type="number" class="form-control js-actual"');
});

it('keeps the finalized line items with the part, and shows them to GM Assistant', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();

    test()->actingAs($head)->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1, 'finalized_line_items' => [1 => 30]])
        ->assertSessionHas('status', 'Approved — every part is through, escalated to GM Assistant.');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->finalized_line_items)->toBe(30)
        ->and($part->finalizedLineItems())->toBe(30)
        ->and($rfq->stage)->toBe('gm_assistant');

    test()->actingAs(userWithRole('GM Assistant'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('30 line items finalized');
    test()->actingAs($head)->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSeeInOrder(['Finalized line items', '30']);
});

it('refuses an approval without the finalized line items, approving nothing', function (array $payload, string $message) {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();

    test()->actingAs($head)->from(route('admin.rfqs.index', ['status' => 'Pending']))
        ->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1] + $payload)
        ->assertSessionHas('error', $message);

    expect($rfq->refresh()->assigneeForPart(1)->pivot->head_of_bd_approved_at)->toBeNull();
})->with([
    'none' => [[], 'Enter the finalized line items for RFQ8801.'],
    'a word' => [['finalized_line_items' => [1 => 'many']], 'Give the finalized line items as a whole number, like 12.'],
    'fewer than none' => [['finalized_line_items' => [1 => -1]], 'The finalized line items can\'t be less than 0.'],
    'too many' => [['finalized_line_items' => [1 => 100001]], 'That\'s more line items than one part can have — at most 100,000.'],
]);

it('asks for every part\'s when approving the whole RFQ', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead(split: true);
    $rfq->update(['stage' => 'head_of_bd_review']);

    test()->actingAs($head)->patch(route('admin.rfqs.approve-head-of-bd', $rfq), ['finalized_line_items' => [1 => 20]])
        ->assertSessionHas('error', 'Enter the finalized line items for RFQ8801-P2 of P2.');

    test()->actingAs($head)->patch(route('admin.rfqs.approve-head-of-bd', $rfq), ['finalized_line_items' => [1 => 20, 2 => 25]])
        ->assertSessionHas('status', 'Approved — escalated to GM Assistant.');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->finalizedLineItems())->toBe(20)
        ->and($rfq->assigneeForPart(2)->pivot->finalizedLineItems())->toBe(25);
});

it('times GM Assistant by the Actual Line Items Senior Operations counted, not the finalized ones — even with per line item off', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();
    expect(Setting::gmAssistantPerItem()['enabled'])->toBeFalse();

    $rfq->approveHeadOfBdPart(1, $head, 30);

    // 23 actual line items × a minute each — not the 30 finalized, nor the 22 added.
    expect($rfq->refresh()->gmAssistantTargetSeconds(1))->toBe(1380)
        ->and($rfq->gmAssistantTargetBreakdown(1))->toBe(['items' => 23, 'perItem' => 60, 'seconds' => 1380, 'source' => 'actual']);

    test()->actingAs(userWithRole('GM Assistant'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('23 line items × 1 min = 23m')
        ->assertSee('23 actual line items')
        ->assertSee('30 line items finalized');
});

it('goes back to the earlier counts once the part is sent back past Senior Operations\' review', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();
    Setting::putGmAssistantPerItem(['enabled' => true, 'seconds' => 60]);
    $rfq->approveHeadOfBdPart(1, $head, 30);

    $rfq->refresh()->rejectPartToStage(1, 'senior_ops_review', 'Count again', userWithRole('General Manager'), 'gm_review');

    expect($rfq->refresh()->assigneeForPart(1)->pivot->finalizedLineItems())->toBeNull()
        ->and($rfq->gmAssistantLineItemsFor(1))->toBe(['count' => 22, 'source' => 'added']);
});

it('lets Admin finalize them on the Head\'s behalf', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();
    $admin = userWithRole('Admin');

    test()->actingAs($admin)->get(route('admin.rfqs.index', ['status' => 'Pending', 'role' => 'head-of-business-development']))->assertOk()
        ->assertSee('id="head-of-bd-approve-acting-as"', false);

    test()->actingAs($admin)->patch(route('admin.rfqs.approve-head-of-bd-part', $rfq), ['part' => 1, 'finalized_line_items' => [1 => 21], 'acting_user_id' => $head->id])
        ->assertSessionHas('status');

    expect($rfq->refresh()->assigneeForPart(1)->pivot)->toMatchArray(['head_of_bd_approved_by' => $head->id, 'finalized_line_items' => 21]);
});

it('times each part of a whole-RFQ approval by its own Actual Line Items × the time a line item takes', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead(split: true);
    DB::table('rfq_user')->where('rfq_id', $rfq->id)->where('part_number', 2)->update(['verified_line_items' => 15]);
    $rfq->update(['stage' => 'head_of_bd_review']);
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);

    test()->actingAs($head)->patch(route('admin.rfqs.approve-head-of-bd', $rfq), ['finalized_line_items' => [1 => 10, 2 => 40]])
        ->assertSessionHas('status', 'Approved — escalated to GM Assistant.');

    expect($rfq->refresh()->gmAssistantTargetSeconds(1))->toBe(2760)
        ->and($rfq->gmAssistantTargetSeconds(2))->toBe(1800);

    test()->actingAs(userWithRole('GM Assistant'))->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('23 line items × 2 min = 46m')
        ->assertSee('15 line items × 2 min = 30m');
});

it('counts GM Assistant down from it once they start — on their list and their dashboard', function () {
    test()->travelTo(CarbonImmutable::parse('2026-10-05 09:00', Setting::DEFAULT_TIMEZONE));
    ['rfq' => $rfq, 'head' => $head] = withTheHead();
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);
    $rfq->approveHeadOfBdPart(1, $head, 30);
    $gma = userWithRole('GM Assistant');
    startGmAssistantOn($rfq, 1, $gma);

    // 20 working minutes in: 46 minutes (23 actual × 2 min) less 20.
    test()->travelTo(CarbonImmutable::parse('2026-10-05 09:20', Setting::DEFAULT_TIMEZONE));
    expect($rfq->refresh()->gmAssistantCountdown(1))->toBe(['target' => 2760, 'remaining' => 1560]);

    test()->actingAs($gma)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('data-remaining="1560" data-target="2760"', false)
        ->assertSee('23 line items × 2 min = 46m');
    test()->actingAs($gma)->get(route('admin.dashboard'))->assertOk()
        ->assertSee('26m left');
});

it('keeps timing by them when the General Manager sends the part back to GM Assistant', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);
    $rfq->approveHeadOfBdPart(1, $head, 30);
    $rfq->refresh()->recordGmAssistantPart(1, userWithRole('GM Assistant'));

    $rfq->refresh()->rejectPartToStage(1, 'gm_assistant', 'Attach the brochure', userWithRole('General Manager'), 'gm_review');

    expect($rfq->refresh()->gmAssistantTargetSeconds(1))->toBe(2760)
        ->and($rfq->gmAssistantTargetBreakdown(1))->toBe(['items' => 23, 'perItem' => 120, 'seconds' => 2760, 'source' => 'actual']);
    test()->actingAs(userWithRole('GM Assistant'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'returns']))->assertOk()
        ->assertSee('23 line items × 2 min = 46m');
});

it('goes by the finalized count where no Actual Line Items were counted, then Data Entry\'s — never no time', function () {
    ['rfq' => $rfq, 'head' => $head] = withTheHead();
    DB::table('rfq_user')->where('rfq_id', $rfq->id)->update(['verified_line_items' => 0]);
    Setting::putGmAssistantPerItem(['enabled' => false, 'seconds' => 120]);

    $rfq->approveHeadOfBdPart(1, $head, 30);
    expect($rfq->refresh()->gmAssistantLineItemsFor(1))->toBe(['count' => 30, 'source' => 'finalized'])
        ->and($rfq->gmAssistantTargetSeconds(1))->toBe(3600);

    DB::table('rfq_user')->where('rfq_id', $rfq->id)->update(['finalized_line_items' => 0]);
    expect($rfq->refresh()->gmAssistantLineItemsFor(1))->toBe(['count' => 22, 'source' => 'added']);
});
