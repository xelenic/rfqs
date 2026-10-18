<?php

use App\Models\Rfq;
use App\Models\RfqAssignment;
use App\Models\RfqComment;
use App\Models\User;
use App\Notifications\LineItemsDiscrepancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    foreach ([...Rfq::WORKFLOW_ROLES, 'Admin'] as $role) {
        Role::findOrCreate($role);
    }
});

/**
 * An RFQ whose one part Riley gave Data Entry with $toAdd line items to add
 * (null: handed over before line items were asked), and Morgan in Data Entry
 * has started on.
 *
 * @return array{rfq: Rfq, riley: User, morgan: User}
 */
function startedWithLineItems(?int $toAdd = 24): array
{
    $riley = userWithRole('Sourcing');
    $morgan = userWithRole('Data Entry');
    $rfq = splitAmong(Rfq::factory()->create(['rfq_number' => 'RFQ8101', 'subject' => 'Valves']), [1 => $riley]);
    $rfq->completeSourcingPart(1, null, null, $toAdd);
    startDataEntryOn($rfq->refresh(), 1, $morgan);

    return ['rfq' => $rfq->refresh(), 'riley' => $riley, 'morgan' => $morgan];
}

/**
 * $user sends part 1 of $rfq to finalize with $entries line items added.
 */
function sendLineItemsAdded(User $user, Rfq $rfq, int $entries): TestResponse
{
    return test()->actingAs($user)->patch(route('admin.rfqs.complete-data-entry', $rfq), ['part' => 1, 'entries' => $entries, 'comment' => 'Prices entered']);
}

it('works out the discrepancy as added minus to add, and how it reads', function (?int $added, ?int $toAdd, ?int $discrepancy, ?string $label) {
    expect(RfqAssignment::lineItemsDiscrepancyBetween($added, $toAdd))->toBe($discrepancy)
        ->and(RfqAssignment::lineItemsDiscrepancyText($added, $toAdd))->toBe($label);
})->with([
    'fewer' => [22, 24, -2, '2 fewer than the 24 to add'],
    'more' => [27, 24, 3, '3 more than the 24 to add'],
    'none added' => [0, 1500, -1500, '1,500 fewer than the 1,500 to add'],
    'the same' => [24, 24, null, null],
    'nothing to add given' => [24, null, null, null],
    'nothing added given' => [null, 24, null, null],
]);

it('tells Senior Operations and the General Manager when the line items added differ', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = startedWithLineItems(24);
    $operations = userWithRole('Senior Operations');
    $generalManager = userWithRole('General Manager');

    sendLineItemsAdded($morgan, $rfq, 22)->assertSessionHas('status');

    $part = $rfq->refresh()->assigneeForPart(1)->pivot;
    expect($part->lineItemsDiscrepancy())->toBe(-2)
        ->and($part->lineItemsDiscrepancyLabel())->toBe('2 fewer than the 24 to add');

    foreach ([$operations, $generalManager] as $person) {
        $alert = $person->notifications()->sole();

        expect($alert->type)->toBe(LineItemsDiscrepancy::class)
            ->and($alert->data)->toMatchArray([
                'rfq_id' => $rfq->id,
                'part' => 1,
                'added' => 22,
                'to_add' => 24,
                'discrepancy' => -2,
                'added_by' => $morgan->id,
                'message' => "RFQ8101: {$morgan->name} added 22 line items, 2 fewer than the 24 to add — Valves",
                'note' => 'line items differ',
            ]);
    }
});

it('tells no one else — not other roles, nor a deactivated account', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = startedWithLineItems(24);

    foreach (['Admin', 'Head of Business Development', 'GM Assistant', 'Business Development'] as $role) {
        userWithRole($role);
    }

    foreach (['Senior Operations', 'General Manager'] as $role) {
        userWithRole($role)->forceFill(['deactivated_at' => now()])->save();
    }

    sendLineItemsAdded($morgan, $rfq, 30);

    expect(User::query()->whereHas('notifications')->count())->toBe(0);
});

it('tells no one when the line items added match, or no line items were given to add', function (?int $toAdd) {
    ['rfq' => $rfq, 'morgan' => $morgan] = startedWithLineItems($toAdd);
    $operations = userWithRole('Senior Operations');
    $generalManager = userWithRole('General Manager');

    sendLineItemsAdded($morgan, $rfq, 24);

    expect($operations->notifications()->count())->toBe(0)
        ->and($generalManager->notifications()->count())->toBe(0)
        ->and($rfq->refresh()->assigneeForPart(1)->pivot->lineItemsDiscrepancy())->toBeNull();
})->with([
    'they match' => [24],
    'none given' => [null],
]);

it('tells them once, not again when the same Send to Finalize comes twice', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = startedWithLineItems(24);
    $generalManager = userWithRole('General Manager');

    sendLineItemsAdded($morgan, $rfq, 20);
    sendLineItemsAdded($morgan, $rfq, 20);

    expect($generalManager->notifications()->count())->toBe(1);
});

it('keeps the discrepancy on the thread, and only when there is one', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = startedWithLineItems(24);

    sendLineItemsAdded($morgan, $rfq, 27);

    $comment = RfqComment::query()->where('action', 'data_entry_completed')->sole();
    expect($comment->meta)->toMatchArray(['entries' => 27, 'to_add' => 24])
        ->and($comment->actionContext())->toEndWith('27 line items added · 3 more than the 24 to add');

    ['rfq' => $matching, 'morgan' => $sam] = startedWithLineItems(24);
    sendLineItemsAdded($sam, $matching, 24);

    expect(RfqComment::query()->where('rfq_id', $matching->id)->where('action', 'data_entry_completed')->sole()->meta)->not->toHaveKey('to_add');
});

it('is in their bell, and opens the RFQ', function () {
    ['rfq' => $rfq, 'morgan' => $morgan] = startedWithLineItems(24);
    $generalManager = userWithRole('General Manager');

    sendLineItemsAdded($morgan, $rfq, 22);

    test()->actingAs($generalManager)->get(route('admin.dashboard'))->assertOk()
        ->assertSee("RFQ8101: {$morgan->name} added 22 line items, 2 fewer than the 24 to add")
        ->assertSee('line items differ');

    test()->actingAs($generalManager)->get(route('admin.notifications.open', $generalManager->notifications()->sole()->id))
        ->assertRedirect(route('admin.rfqs.show', $rfq));
});

it('shows the discrepancy to Data Entry, Sourcing, Senior Operations and on the RFQ\'s page', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = startedWithLineItems(24);
    sendLineItemsAdded($morgan, $rfq, 22);

    test()->actingAs($morgan)->get(route('admin.rfqs.index', ['status' => 'Pending', 'tab' => 'completed']))->assertOk()
        ->assertSeeInOrder(['RFQ8101', '22', '2 fewer than the 24 to add']);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSeeInOrder(['Sent to finalize by Data Entry', '22 line items added, 2 fewer than the 24 to add']);

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertSee('2 fewer than the 24 to add');

    $rfq->refresh()->finalizePart(1);

    test()->actingAs(userWithRole('Senior Operations'))->get(route('admin.rfqs.index', ['status' => 'Pending', 'view' => 'review']))->assertOk()
        ->assertSeeInOrder(['22 line items added', '2 fewer than the 24 to add']);
});

it('shows nothing extra when the line items added match', function () {
    ['rfq' => $rfq, 'riley' => $riley, 'morgan' => $morgan] = startedWithLineItems(24);
    sendLineItemsAdded($morgan, $rfq, 24);

    test()->actingAs($riley)->get(route('admin.rfqs.index', ['status' => 'Pending']))->assertOk()
        ->assertSee('Sent to finalize by Data Entry')
        ->assertDontSee('than the 24 to add');

    test()->actingAs(userWithRole('Admin'))->get(route('admin.rfqs.show', $rfq))->assertOk()
        ->assertDontSee('than the 24 to add');
});
